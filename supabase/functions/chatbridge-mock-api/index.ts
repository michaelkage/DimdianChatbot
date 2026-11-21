const PROJECT_URL = Deno.env.get("SUPABASE_URL") ?? "";
const secretMap = (() => {
  try { return JSON.parse(Deno.env.get("SUPABASE_SECRET_KEYS") ?? "{}"); } catch { return {}; }
})();
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY") ?? secretMap.default ?? "";
const BUCKET = "chatbridge-private";
const MAX_UPLOAD = 10 * 1024 * 1024;

class HttpError extends Error {
  status: number;
  constructor(status: number, message: string) {
    super(message);
    this.status = status;
  }
}

function backendHeaders(extra: Record<string, string> = {}): Headers {
  const headers = new Headers({ apikey: SERVICE_KEY, ...extra });
  // New sb_secret keys are not JWTs and must not be sent as Bearer tokens.
  if (!SERVICE_KEY.startsWith("sb_secret_")) headers.set("Authorization", `Bearer ${SERVICE_KEY}`);
  return headers;
}

function json(data: unknown, status = 200, cors: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store, private", "X-Content-Type-Options": "nosniff", ...cors },
  });
}

function errorResponse(code: string, message: string, status: number, cors: Record<string, string>): Response {
  return json({ error: { code, message } }, status, cors);
}

function normalizeOrigin(value: string | null): string | null {
  if (!value) return null;
  try {
    const parsed = new URL(value);
    if (parsed.origin !== value.replace(/\/$/, "") || parsed.username || parsed.password) return null;
    if (parsed.protocol !== "https:" && !(parsed.protocol === "http:" && ["localhost", "127.0.0.1"].includes(parsed.hostname))) return null;
    return parsed.origin;
  } catch {
    return null;
  }
}

async function rest(method: string, path: string, body?: unknown, extra: Record<string, string> = {}): Promise<any> {
  const headers = backendHeaders({ Accept: "application/json", ...extra });
  let payload: string | undefined;
  if (body !== undefined) {
    headers.set("Content-Type", "application/json");
    payload = JSON.stringify(body);
  }
  const response = await fetch(`${PROJECT_URL}${path}`, { method, headers, body: payload });
  const text = await response.text();
  if (!response.ok) throw new HttpError(response.status, text || "Supabase request failed.");
  if (!text) return null;
  try { return JSON.parse(text); } catch { return text; }
}

async function resolveTenant(origin: string): Promise<string | null> {
  const originQuery = new URLSearchParams({
    select: "tenant_id",
    origin: `eq.${origin}`,
    status: "eq.verified",
    limit: "1",
  });
  const origins = await rest("GET", `/rest/v1/chatbridge_site_origins?${originQuery}`);
  if (!Array.isArray(origins) || !origins[0]?.tenant_id) return null;
  const tenantQuery = new URLSearchParams({
    select: "id",
    id: `eq.${origins[0].tenant_id}`,
    status: "eq.active",
    limit: "1",
  });
  const tenants = await rest("GET", `/rest/v1/chatbridge_tenants?${tenantQuery}`);
  return Array.isArray(tenants) && tenants[0]?.id ? String(tenants[0].id) : null;
}

async function digestHex(value: string | Uint8Array): Promise<string> {
  const bytes = typeof value === "string" ? new TextEncoder().encode(value) : value;
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, "0")).join("");
}

function randomToken(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(32));
  return [...bytes].map(byte => byte.toString(16).padStart(2, "0")).join("");
}

async function takeRateLimit(tenantId: string, bucket: string, maxRequests: number, windowSeconds: number): Promise<void> {
  const allowed = await rest("POST", "/rest/v1/rpc/chatbridge_take_rate_limit", {
    p_bucket_key: await digestHex(bucket),
    p_tenant_id: tenantId,
    p_max_requests: maxRequests,
    p_window_seconds: windowSeconds,
  });
  if (allowed !== true) throw new HttpError(429, "rate_limited");
}

async function createSession(req: Request, tenantId: string, cors: Record<string, string>): Promise<Response> {
  const body = await req.json();
  const name = body.name == null || body.name === "" ? null : String(body.name).trim();
  const phone = body.phone == null || body.phone === "" ? null : String(body.phone).trim();
  if (name && name.length > 100) return errorResponse("invalid_field", "Name is too long.", 422, cors);
  if (phone) return errorResponse("mock_channel_unavailable", "WhatsApp continuation is disabled in the mock database. Leave the phone field empty.", 422, cors);
  if (body.whatsapp_opt_in === true) return errorResponse("mock_channel_unavailable", "WhatsApp continuation is disabled in the mock database.", 422, cors);

  const forwarded = req.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ?? "unknown";
  await takeRateLimit(tenantId, `session:${tenantId}:${forwarded}`, 20, 900);
  const token = randomToken();
  const expiresAt = new Date(Date.now() + 168 * 60 * 60 * 1000).toISOString();
  const created = await rest("POST", "/rest/v1/rpc/chatbridge_create_guest_session", {
    p_tenant_id: tenantId,
    p_name: name,
    p_phone: null,
    p_phone_normalized: null,
    p_whatsapp_opt_in: false,
    p_token_hash: await digestHex(token),
    p_expires_at: expiresAt,
  });
  if (!created?.session_id || !created?.conversation_id) throw new HttpError(500, "session_create_failed");
  return json({ data: { ...created, session_token: token } }, 201, cors);
}

async function authenticate(req: Request, tenantId: string, requireOpen: boolean): Promise<any> {
  const token = req.headers.get("x-dimdian-guest-token") ?? "";
  if (!/^[a-f0-9]{64}$/.test(token)) throw new HttpError(401, "unauthorized");
  try {
    return await rest("POST", "/rest/v1/rpc/chatbridge_auth_guest_session", {
      p_tenant_id: tenantId,
      p_token_hash: await digestHex(token),
      p_require_open: requireOpen,
    });
  } catch (error) {
    const detail = error instanceof HttpError ? error.message : "";
    if (detail.includes("session_expired")) throw new HttpError(401, "session_expired");
    if (detail.includes("conversation_closed")) throw new HttpError(409, "conversation_closed");
    throw error;
  }
}

async function messages(req: Request, tenantId: string, cors: Record<string, string>): Promise<Response> {
  if (req.method === "POST") {
    const raw = await req.text();
    if (raw.length > 20000) return errorResponse("payload_too_large", "The request is too large.", 413, cors);
    let body: any;
    try { body = JSON.parse(raw); } catch { return errorResponse("invalid_json", "Send a valid JSON object.", 400, cors); }
    const message = typeof body.message === "string" ? body.message.trim() : "";
    if (!message || message.length > 4000) return errorResponse("message_required", "Write a message of up to 4,000 characters.", 422, cors);
    const session = await authenticate(req, tenantId, true);
    await takeRateLimit(tenantId, `message:${tenantId}:${session.session_id}`, 20, 60);
    const clientId = body.client_message_id ?? crypto.randomUUID();
    if (typeof clientId !== "string" || !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(clientId)) {
      return errorResponse("invalid_idempotency_key", "client_message_id must be a UUID.", 422, cors);
    }
    let saved: any;
    try {
      saved = await rest("POST", "/rest/v1/rpc/chatbridge_append_message", {
        p_tenant_id: tenantId,
        p_conversation_id: session.conversation_id,
        p_client_message_id: clientId,
        p_body: message,
      });
    } catch (error) {
      const detail = error instanceof HttpError ? error.message : "";
      if (detail.includes("idempotency_conflict")) return errorResponse("idempotency_conflict", "This message ID was already used for different text.", 409, cors);
      if (detail.includes("conversation_closed_or_missing")) return errorResponse("conversation_closed", "This conversation has been closed. Start a new chat to continue.", 409, cors);
      throw error;
    }
    if (!saved?.id) throw new HttpError(500, "message_save_failed");
    const inserted = saved.inserted === true;
    delete saved.inserted;
    return json({
      data: saved,
      meta: {
        transport: inserted ? "website_chat_only" : "replayed",
        idempotent_replay: !inserted,
        note: "Your message is saved in the Supabase mock site. No WhatsApp message or live support notification is sent.",
      },
    }, inserted ? 201 : 200, cors);
  }

  const session = await authenticate(req, tenantId, false);
  const url = new URL(req.url);
  const afterRaw = url.searchParams.get("after") ?? "0";
  const limitRaw = url.searchParams.get("limit") ?? "50";
  if (!/^\d{1,18}$/.test(afterRaw) || !/^\d{1,3}$/.test(limitRaw)) {
    return errorResponse("invalid_cursor", "The message cursor or limit is invalid.", 422, cors);
  }
  const after = Number(afterRaw);
  const limit = Math.min(100, Math.max(1, Number(limitRaw)));
  const query = new URLSearchParams({
    select: "id,sequence_id,sender_type,body,delivery_status,created_at",
    tenant_id: `eq.${tenantId}`,
    conversation_id: `eq.${session.conversation_id}`,
    sequence_id: `gt.${after}`,
    order: "sequence_id.asc",
    limit: String(limit + 1),
  });
  const rows = await rest("GET", `/rest/v1/chatbridge_messages?${query}`);
  const hasMore = rows.length > limit;
  const page = hasMore ? rows.slice(0, limit) : rows;
  const items = page.map((row: any) => ({
    id: row.id,
    sequence_id: Number(row.sequence_id),
    sender: row.sender_type,
    message: row.body,
    delivery_status: row.delivery_status,
    created_at: row.created_at,
  }));
  const nextCursor = items.length ? items[items.length - 1].sequence_id : after;
  return json({
    data: items,
    meta: { handoff: null, after, next_cursor: nextCursor, has_more: hasMore, poll_after_ms: 3000, delivery_updates: [] },
  }, 200, cors);
}

function safeFilename(value: string): string {
  const name = value.replace(/\\/g, "/").split("/").pop() ?? "attachment";
  return name.replace(/[^A-Za-z0-9._-]/g, "_").slice(0, 180) || "attachment";
}

function detectMime(bytes: Uint8Array): string | null {
  if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) return "image/jpeg";
  if (bytes.length >= 8 && [137,80,78,71,13,10,26,10].every((value, index) => bytes[index] === value)) return "image/png";
  if (bytes.length >= 12 && new TextDecoder().decode(bytes.slice(0,4)) === "RIFF" && new TextDecoder().decode(bytes.slice(8,12)) === "WEBP") return "image/webp";
  if (bytes.length >= 5 && new TextDecoder().decode(bytes.slice(0,5)) === "%PDF-") return "application/pdf";
  try {
    const text = new TextDecoder("utf-8", { fatal: true }).decode(bytes);
    if (text.includes("\u0000")) return null;
    try { JSON.parse(text); return "application/json"; } catch { return "text/plain"; }
  } catch { return null; }
}

async function storage(method: string, path: string, body?: Uint8Array | Record<string, unknown>, contentType?: string): Promise<any> {
  const headers = backendHeaders({ Accept: "application/json" });
  let payload: BodyInit | undefined;
  if (body instanceof Uint8Array) {
    headers.set("Content-Type", contentType ?? "application/octet-stream");
    headers.set("x-upsert", "false");
    payload = body;
  } else if (body !== undefined) {
    headers.set("Content-Type", "application/json");
    payload = JSON.stringify(body);
  }
  const response = await fetch(`${PROJECT_URL}${path}`, { method, headers, body: payload });
  const text = await response.text();
  if (!response.ok) throw new HttpError(response.status, text || "storage_request_failed");
  if (!text) return null;
  try { return JSON.parse(text); } catch { return null; }
}

async function attachments(req: Request, tenantId: string, cors: Record<string, string>): Promise<Response> {
  if (req.method === "POST") {
    const session = await authenticate(req, tenantId, true);
    await takeRateLimit(tenantId, `attachment:${tenantId}:${session.session_id}`, 5, 300);
    const form = await req.formData();
    const file = form.get("attachment");
    if (!(file instanceof File)) return errorResponse("attachment_required", "Choose a file to attach.", 422, cors);
    if (file.size < 1 || file.size > MAX_UPLOAD) return errorResponse("attachment_size_invalid", "Attachments must be 10 MiB or smaller.", 413, cors);
    const bytes = new Uint8Array(await file.arrayBuffer());
    const mime = detectMime(bytes);
    const extensions: Record<string, string> = {
      "image/jpeg": "jpg", "image/png": "png", "image/webp": "webp",
      "application/pdf": "pdf", "text/plain": "txt", "application/json": "json",
    };
    if (!mime || !extensions[mime]) return errorResponse("attachment_type_not_allowed", "Use a JPEG, PNG, WebP, PDF, text or JSON attachment.", 415, cors);
    const debugBundle = form.get("attachment_kind") === "debug_bundle";
    if (debugBundle && (form.get("diagnostic_consent") !== "1" || !["application/json", "text/plain"].includes(mime))) {
      return errorResponse("diagnostic_consent_required", "A diagnostic bundle requires explicit consent and must be JSON or plain text.", 422, cors);
    }
    const attachmentId = crypto.randomUUID();
    const storagePath = `${tenantId}/${session.conversation_id}/${attachmentId}.${extensions[mime]}`;
    const encodedPath = storagePath.split("/").map(encodeURIComponent).join("/");
    const visibility = debugBundle ? "staff_only" : "customer_visible";
    let uploaded = false;
    try {
      await storage("POST", `/storage/v1/object/${BUCKET}/${encodedPath}`, bytes, mime);
      uploaded = true;
      await rest("POST", "/rest/v1/chatbridge_attachments", {
        id: attachmentId,
        tenant_id: tenantId,
        conversation_id: session.conversation_id,
        storage_bucket: BUCKET,
        storage_path: storagePath,
        original_filename: safeFilename(file.name),
        mime_type: mime,
        byte_size: bytes.length,
        sha256: await digestHex(bytes),
        visibility,
        uploader_type: "visitor",
        metadata: { attachment_kind: debugBundle ? "debug_bundle" : "file" },
      }, { Prefer: "return=minimal" });
    } catch (error) {
      if (uploaded) {
        try { await storage("DELETE", `/storage/v1/object/${BUCKET}/${encodedPath}`); } catch { /* retain primary failure */ }
      }
      throw error;
    }
    return json({
      data: {
        id: attachmentId,
        conversation_id: session.conversation_id,
        filename: safeFilename(file.name),
        mime_type: mime,
        byte_size: bytes.length,
        visibility,
        created_at: new Date().toISOString(),
      },
    }, 201, cors);
  }

  const session = await authenticate(req, tenantId, false);
  const query = new URLSearchParams({
    select: "id,storage_path,original_filename,mime_type,byte_size,created_at",
    tenant_id: `eq.${tenantId}`,
    conversation_id: `eq.${session.conversation_id}`,
    visibility: "eq.customer_visible",
    deleted_at: "is.null",
    order: "created_at.asc",
  });
  const rows = await rest("GET", `/rest/v1/chatbridge_attachments?${query}`);
  const items = [];
  for (const row of rows) {
    const encodedPath = String(row.storage_path).split("/").map(encodeURIComponent).join("/");
    const signed = await storage("POST", `/storage/v1/object/sign/${BUCKET}/${encodedPath}`, { expiresIn: 60 });
    const signedUrl = String(signed?.signedURL ?? "");
    items.push({
      id: row.id,
      filename: row.original_filename,
      mime_type: row.mime_type,
      byte_size: Number(row.byte_size),
      created_at: row.created_at,
      signed_url: signedUrl.startsWith("/") ? PROJECT_URL + signedUrl : signedUrl,
      url_expires_in: 60,
    });
  }
  return json({ data: items }, 200, cors);
}


function getPublishableKey(): string {
  try {
    const keys = JSON.parse(Deno.env.get("SUPABASE_PUBLISHABLE_KEYS") ?? "{}");
    if (typeof keys.default === "string" && keys.default) return keys.default;
  } catch { /* use legacy fallback below */ }
  return Deno.env.get("SUPABASE_ANON_KEY") ?? "";
}

async function authenticateStaff(req: Request, tenantId: string): Promise<{ userId: string; role: string }> {
  const authorization = req.headers.get("authorization") ?? "";
  const match = authorization.match(/^Bearer\s+(.+)$/i);
  if (!match || !getPublishableKey()) throw new HttpError(401, "staff_auth_required");
  const authResponse = await fetch(`${PROJECT_URL}/auth/v1/user`, {
    headers: { apikey: getPublishableKey(), Authorization: `Bearer ${match[1]}` },
  });
  if (!authResponse.ok) throw new HttpError(401, "staff_auth_invalid");
  const user = await authResponse.json();
  if (!user?.id || typeof user.id !== "string") throw new HttpError(401, "staff_auth_invalid");

  const query = new URLSearchParams({
    select: "role",
    tenant_id: `eq.${tenantId}`,
    user_id: `eq.${user.id}`,
    status: "eq.active",
    limit: "1",
  });
  const memberships = await rest("GET", `/rest/v1/chatbridge_staff_memberships?${query}`);
  if (!Array.isArray(memberships) || !memberships[0]?.role) throw new HttpError(403, "staff_membership_required");
  return { userId: user.id, role: String(memberships[0].role) };
}

async function staffConversations(req: Request, tenantId: string, cors: Record<string, string>, staff: { userId: string; role: string }): Promise<Response> {
  const url = new URL(req.url);
  const status = url.searchParams.get("status") ?? "open";
  const limitRaw = url.searchParams.get("limit") ?? "50";
  if (!["open", "closed", "all"].includes(status) || !/^\d{1,3}$/.test(limitRaw)) {
    return errorResponse("invalid_filter", "Choose open, closed or all conversations and a valid limit.", 422, cors);
  }
  const beforeRaw = url.searchParams.get("before");
  const before = beforeRaw ? new Date(beforeRaw) : null;
  if (beforeRaw && (!before || Number.isNaN(before.getTime()))) return errorResponse("invalid_cursor", "The before cursor must be a valid timestamp.", 422, cors);
  const rows = await rest("POST", "/rest/v1/rpc/chatbridge_staff_list_conversations", {
    p_tenant_id: tenantId,
    p_user_id: staff.userId,
    p_status: status,
    p_limit: Math.min(100, Math.max(1, Number(limitRaw))),
    p_before: before ? before.toISOString() : null,
  });
  return json({ data: Array.isArray(rows) ? rows : [] }, 200, cors);
}

async function staffConversationDetail(req: Request, tenantId: string, cors: Record<string, string>, staff: { userId: string; role: string }, conversationId: string, resource: "messages" | "attachments"): Promise<Response> {
  const exists = await rest("GET", `/rest/v1/chatbridge_conversations?${new URLSearchParams({
    select: "id",
    id: `eq.${conversationId}`,
    tenant_id: `eq.${tenantId}`,
    limit: "1",
  })}`);
  if (!Array.isArray(exists) || !exists.length) return errorResponse("conversation_not_found", "This conversation does not exist.", 404, cors);

  if (resource === "messages") {
    if (req.method === "POST") {
      const raw = await req.text();
      if (raw.length > 20000) return errorResponse("payload_too_large", "The request is too large.", 413, cors);
      let body: any;
      try { body = JSON.parse(raw); } catch { return errorResponse("invalid_json", "Send a valid JSON object.", 400, cors); }
      const message = typeof body.message === "string" ? body.message.trim() : "";
      if (!message || message.length > 4000) return errorResponse("message_required", "Write a message of up to 4,000 characters.", 422, cors);
      await takeRateLimit(tenantId, `staff-message:${tenantId}:${staff.userId}`, 60, 60);
      const clientId = body.client_message_id ?? crypto.randomUUID();
      if (typeof clientId !== "string" || !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(clientId)) {
        return errorResponse("invalid_idempotency_key", "client_message_id must be a UUID.", 422, cors);
      }
      const saved = await rest("POST", "/rest/v1/rpc/chatbridge_staff_append_message", {
        p_tenant_id: tenantId,
        p_conversation_id: conversationId,
        p_user_id: staff.userId,
        p_client_message_id: clientId,
        p_body: message,
      });
      if (!saved?.id) throw new HttpError(500, "message_save_failed");
      const inserted = saved.inserted === true;
      delete saved.inserted;
      return json({ data: saved, meta: { idempotent_replay: !inserted, transport: "website_chat_only" } }, inserted ? 201 : 200, cors);
    }

    const url = new URL(req.url);
    const afterRaw = url.searchParams.get("after") ?? "0";
    const limitRaw = url.searchParams.get("limit") ?? "100";
    if (!/^\d{1,18}$/.test(afterRaw) || !/^\d{1,3}$/.test(limitRaw)) return errorResponse("invalid_cursor", "The message cursor or limit is invalid.", 422, cors);
    const after = Number(afterRaw);
    const limit = Math.min(200, Math.max(1, Number(limitRaw)));
    const query = new URLSearchParams({
      select: "id,sequence_id,sender_type,body,delivery_status,created_at,metadata",
      tenant_id: `eq.${tenantId}`,
      conversation_id: `eq.${conversationId}`,
      sequence_id: `gt.${after}`,
      order: "sequence_id.asc",
      limit: String(limit + 1),
    });
    const rows = await rest("GET", `/rest/v1/chatbridge_messages?${query}`);
    const hasMore = rows.length > limit;
    const page = hasMore ? rows.slice(0, limit) : rows;
    const data = page.map((row: any) => ({
      id: row.id,
      sequence_id: Number(row.sequence_id),
      sender: row.sender_type,
      message: row.body,
      delivery_status: row.delivery_status,
      created_at: row.created_at,
      actor_user_id: row.metadata?.actor_user_id ?? null,
    }));
    return json({ data, meta: { next_cursor: data.length ? data[data.length - 1].sequence_id : after, has_more: hasMore } }, 200, cors);
  }

  const query = new URLSearchParams({
    select: "id,storage_path,original_filename,mime_type,byte_size,visibility,created_at,metadata",
    tenant_id: `eq.${tenantId}`,
    conversation_id: `eq.${conversationId}`,
    deleted_at: "is.null",
    order: "created_at.asc",
  });
  const rows = await rest("GET", `/rest/v1/chatbridge_attachments?${query}`);
  const data = [];
  for (const row of rows) {
    const encodedPath = String(row.storage_path).split("/").map(encodeURIComponent).join("/");
    const signed = await storage("POST", `/storage/v1/object/sign/${BUCKET}/${encodedPath}`, { expiresIn: 120 });
    const signedUrl = String(signed?.signedURL ?? "");
    data.push({
      id: row.id,
      filename: row.original_filename,
      mime_type: row.mime_type,
      byte_size: Number(row.byte_size),
      visibility: row.visibility,
      created_at: row.created_at,
      attachment_kind: row.metadata?.attachment_kind ?? "file",
      signed_url: signedUrl.startsWith("/") ? PROJECT_URL + signedUrl : signedUrl,
      url_expires_in: 120,
    });
  }
  return json({ data }, 200, cors);
}

function secureEquals(left: string, right: string): boolean {
  if (!left || left.length !== right.length) return false;
  let mismatch = 0;
  for (let i = 0; i < left.length; i++) mismatch |= left.charCodeAt(i) ^ right.charCodeAt(i);
  return mismatch === 0;
}

async function removeStoredAttachments(rows: any[], tenantId: string): Promise<void> {
  if (!rows.length) return;
  const buckets = new Map<string, any[]>();
  for (const row of rows) {
    const bucket = String(row.storage_bucket || BUCKET);
    const items = buckets.get(bucket) ?? [];
    items.push(row);
    buckets.set(bucket, items);
  }
  for (const [bucket, items] of buckets) {
    await storage("DELETE", `/storage/v1/object/${encodeURIComponent(bucket)}`, {
      prefixes: items.map(row => String(row.storage_path)),
    });
    const ids = items.map(row => String(row.id)).join(",");
    await rest("DELETE", `/rest/v1/chatbridge_attachments?id=in.(${ids})&tenant_id=eq.${encodeURIComponent(tenantId)}`, undefined, { Prefer: "return=minimal" });
  }
}

async function expiredAttachments(tenantId: string, days: number, diagnostic: boolean): Promise<{ deleted: number; failures: number }> {
  if (days === 0) return { deleted: 0, failures: 0 };
  const cutoff = new Date(Date.now() - days * 86400000).toISOString();
  const params = new URLSearchParams({ select: "id,storage_bucket,storage_path", tenant_id: `eq.${tenantId}`, created_at: `lt.${cutoff}`, order: "created_at.asc", limit: "500" });
  params.set("metadata->>attachment_kind", diagnostic ? "eq.debug_bundle" : "neq.debug_bundle");
  const rows = await rest("GET", `/rest/v1/chatbridge_attachments?${params}`);
  const items = Array.isArray(rows) ? rows : [];
  if (!items.length) return { deleted: 0, failures: 0 };
  try {
    await removeStoredAttachments(items, tenantId);
    return { deleted: items.length, failures: 0 };
  } catch (error) {
    console.error("Retention attachment cleanup failed", error);
    return { deleted: 0, failures: items.length };
  }
}

async function expiredConversations(tenantId: string, days: number): Promise<{ deleted: number; attachments: number; failures: number }> {
  if (days === 0) return { deleted: 0, attachments: 0, failures: 0 };
  const cutoff = new Date(Date.now() - days * 86400000).toISOString();
  const params = new URLSearchParams({ select: "id,guest_id", tenant_id: `eq.${tenantId}`, or: `(last_message_at.lt.${cutoff},and(last_message_at.is.null,created_at.lt.${cutoff}))`, order: "created_at.asc", limit: "250" });
  const rows = await rest("GET", `/rest/v1/chatbridge_conversations?${params}`);
  let deleted = 0, attachments = 0, failures = 0;
  for (const conversation of Array.isArray(rows) ? rows : []) {
    const conversationId = String(conversation.id), guestId = String(conversation.guest_id);
    const attachmentQuery = new URLSearchParams({ select: "id,storage_bucket,storage_path", tenant_id: `eq.${tenantId}`, conversation_id: `eq.${conversationId}`, limit: "500" });
    const files = await rest("GET", `/rest/v1/chatbridge_attachments?${attachmentQuery}`);
    try {
      const fileRows = Array.isArray(files) ? files : [];
      await removeStoredAttachments(fileRows, tenantId);
      attachments += fileRows.length;
    } catch (error) {
      failures++;
      console.error("Conversation retention cleanup failed", conversationId, error);
      continue;
    }
    await rest("DELETE", `/rest/v1/chatbridge_guest_sessions?tenant_id=eq.${tenantId}&conversation_id=eq.${conversationId}`, undefined, { Prefer: "return=minimal" });
    await rest("DELETE", `/rest/v1/chatbridge_messages?tenant_id=eq.${tenantId}&conversation_id=eq.${conversationId}`, undefined, { Prefer: "return=minimal" });
    await rest("DELETE", `/rest/v1/chatbridge_conversations?tenant_id=eq.${tenantId}&id=eq.${conversationId}`, undefined, { Prefer: "return=minimal" });
    deleted++;
    const remaining = await rest("GET", `/rest/v1/chatbridge_conversations?select=id&tenant_id=eq.${tenantId}&guest_id=eq.${guestId}&limit=1`);
    if (!Array.isArray(remaining) || remaining.length === 0) {
      await rest("DELETE", `/rest/v1/chatbridge_guest_sessions?tenant_id=eq.${tenantId}&guest_id=eq.${guestId}`, undefined, { Prefer: "return=minimal" });
      await rest("DELETE", `/rest/v1/chatbridge_guests?tenant_id=eq.${tenantId}&id=eq.${guestId}`, undefined, { Prefer: "return=minimal" });
    }
  }
  return { deleted, attachments, failures };
}

async function runRetentionForTenant(tenantId: string): Promise<Record<string, number>> {
  const settingRows = await rest("GET", `/rest/v1/chatbridge_retention_settings?select=conversation_days,attachment_days,diagnostic_days,audit_days&tenant_id=eq.${tenantId}&limit=1`);
  const settings = Array.isArray(settingRows) ? settingRows[0] : null;
  if (!settings) return { conversations_deleted: 0, attachments_deleted: 0, audit_events_deleted: 0, failures: 0 };
  let attachmentsDeleted = 0, failures = 0;
  const normalFiles = await expiredAttachments(tenantId, Number(settings.attachment_days), false);
  const diagnostics = await expiredAttachments(tenantId, Number(settings.diagnostic_days), true);
  attachmentsDeleted += normalFiles.deleted + diagnostics.deleted;
  failures += normalFiles.failures + diagnostics.failures;
  const conversations = await expiredConversations(tenantId, Number(settings.conversation_days));
  attachmentsDeleted += conversations.attachments;
  failures += conversations.failures;
  let auditEventsDeleted = 0;
  const auditDays = Number(settings.audit_days);
  if (auditDays > 0) {
    const removed = await rest("POST", "/rest/v1/rpc/chatbridge_retention_delete_audit_events", { p_tenant_id: tenantId });
    auditEventsDeleted = Number(removed) || 0;
  }
  await rest("POST", "/rest/v1/chatbridge_retention_runs", { tenant_id: tenantId, conversations_deleted: conversations.deleted, attachments_deleted: attachmentsDeleted, audit_events_deleted: auditEventsDeleted, failures }, { Prefer: "return=minimal" });
  return { conversations_deleted: conversations.deleted, attachments_deleted: attachmentsDeleted, audit_events_deleted: auditEventsDeleted, failures };
}

async function runRetentionCleanup(tenantId?: string): Promise<Record<string, unknown>> {
  const params = new URLSearchParams({ select: "tenant_id", order: "tenant_id.asc" });
  if (tenantId) params.set("tenant_id", `eq.${tenantId}`);
  const rows = await rest("GET", `/rest/v1/chatbridge_retention_settings?${params}`);
  const results = [];
  for (const row of Array.isArray(rows) ? rows : []) results.push({ tenant_id: row.tenant_id, ...(await runRetentionForTenant(String(row.tenant_id))) });
  return { tenants_processed: results.length, results };
}

async function internalRetentionCleanup(req: Request): Promise<Response> {
  if (req.method !== "POST") return json({ error: { code: "method_not_allowed", message: "POST is required." } }, 405);
  const supplied = req.headers.get("x-chatbridge-retention-secret") ?? "";
  const expected = await rest("POST", "/rest/v1/rpc/chatbridge_get_retention_secret", {});
  if (typeof expected !== "string" || !secureEquals(supplied, expected)) return json({ error: { code: "unauthorized", message: "The retention worker credential is invalid." } }, 401);
  return json({ data: await runRetentionCleanup() });
}

async function staffRetentionSettings(req: Request, tenantId: string, cors: Record<string, string>, staff: { userId: string; role: string }): Promise<Response> {
  const canManage = ["owner", "admin"].includes(staff.role);
  if (req.method === "GET") {
    const rows = await rest("GET", `/rest/v1/chatbridge_retention_settings?select=conversation_days,attachment_days,diagnostic_days,audit_days,updated_at&tenant_id=eq.${tenantId}&limit=1`);
    const latest = await rest("GET", `/rest/v1/chatbridge_retention_runs?select=conversations_deleted,attachments_deleted,audit_events_deleted,failures,created_at&tenant_id=eq.${tenantId}&order=created_at.desc&limit=1`);
    return json({ data: Array.isArray(rows) ? rows[0] ?? null : null, last_run: Array.isArray(latest) ? latest[0] ?? null : null, can_manage: canManage }, 200, cors);
  }
  if (req.method === "PATCH") {
    if (!canManage) return errorResponse("admin_required", "Only tenant owners and admins can change data retention.", 403, cors);
    const raw = await req.text();
    if (raw.length > 5000) return errorResponse("payload_too_large", "The request is too large.", 413, cors);
    let body: any;
    try { body = JSON.parse(raw); } catch { return errorResponse("invalid_json", "Send a valid JSON object.", 400, cors); }
    const fields = ["conversation_days", "attachment_days", "diagnostic_days", "audit_days"];
    const update: Record<string, number | string> = { updated_by: staff.userId, updated_at: new Date().toISOString() };
    for (const field of fields) {
      const value = body[field];
      if (!Number.isInteger(value) || value < 0 || value > 3650) return errorResponse("invalid_retention_period", "Each retention period must be a whole number from 0 to 3650 days. Use 0 to keep data indefinitely.", 422, cors);
      update[field] = value;
    }
    const rows = await rest("PATCH", `/rest/v1/chatbridge_retention_settings?tenant_id=eq.${tenantId}`, update, { Prefer: "return=representation" });
    return json({ data: Array.isArray(rows) ? rows[0] ?? null : null }, 200, cors);
  }
  return errorResponse("method_not_allowed", "Use GET or PATCH.", 405, cors);
}

async function staffConversationAudit(req: Request, tenantId: string, cors: Record<string, string>, conversationId: string): Promise<Response> {
  const url = new URL(req.url);
  const limitRaw = url.searchParams.get("limit") ?? "50";
  const beforeRaw = url.searchParams.get("before");
  if (!/^\d{1,3}$/.test(limitRaw) || (beforeRaw !== null && !/^\d{1,18}$/.test(beforeRaw))) {
    return errorResponse("invalid_cursor", "The audit cursor or limit is invalid.", 422, cors);
  }
  const params = new URLSearchParams({
    select: "id,event_type,details,actor_user_id,created_at",
    tenant_id: `eq.${tenantId}`,
    conversation_id: `eq.${conversationId}`,
    order: "id.desc",
    limit: String(Math.min(100, Math.max(1, Number(limitRaw)))),
  });
  if (beforeRaw !== null) params.set("id", `lt.${beforeRaw}`);
  const rows = await rest("GET", `/rest/v1/chatbridge_staff_audit_events?${params}`);
  const data = (Array.isArray(rows) ? rows : []).map((row: any) => ({
    id: String(row.id),
    event_type: row.event_type,
    details: row.details && typeof row.details === "object" ? row.details : {},
    actor_user_id: row.actor_user_id,
    created_at: row.created_at,
  }));
  return json({ data, meta: { next_cursor: data.length ? data[data.length - 1].id : null } }, 200, cors);
}

async function staffUpdateConversation(req: Request, tenantId: string, cors: Record<string, string>, staff: { userId: string; role: string }, conversationId: string): Promise<Response> {
  const raw = await req.text();
  if (raw.length > 5000) return errorResponse("payload_too_large", "The request is too large.", 413, cors);
  let body: any;
  try { body = JSON.parse(raw); } catch { return errorResponse("invalid_json", "Send a valid JSON object.", 400, cors); }
  const status = body.status === undefined ? null : body.status;
  const updateAssignment = Object.prototype.hasOwnProperty.call(body, "assigned_to");
  const assignedTo = updateAssignment ? body.assigned_to : null;
  if (status !== null && !["open", "closed"].includes(status)) return errorResponse("invalid_status", "Status must be open or closed.", 422, cors);
  if (updateAssignment && assignedTo !== null && assignedTo !== staff.userId) return errorResponse("unsupported_assignment", "This mock inbox currently supports assigning to yourself or leaving the conversation unassigned.", 422, cors);
  const updated = await rest("POST", "/rest/v1/rpc/chatbridge_staff_update_conversation", {
    p_tenant_id: tenantId,
    p_conversation_id: conversationId,
    p_user_id: staff.userId,
    p_status: status,
    p_assigned_to: assignedTo,
    p_update_assignment: updateAssignment,
  });
  return json({ data: updated }, 200, cors);
}

Deno.serve(async (req: Request): Promise<Response> => {
  if (!PROJECT_URL || !SERVICE_KEY) return json({ error: { code: "not_configured", message: "The mock API is not configured." } }, 503);
  const incomingUrl = new URL(req.url);
  const functionMarker = "/functions/v1/chatbridge-mock-api";
  const incomingRoute = incomingUrl.pathname.includes(functionMarker) ? incomingUrl.pathname.slice(incomingUrl.pathname.indexOf(functionMarker) + functionMarker.length) : "/";
  if (incomingRoute === "/internal/retention-cleanup" || incomingUrl.pathname.endsWith("/internal/retention-cleanup")) return await internalRetentionCleanup(req);

  const origin = normalizeOrigin(req.headers.get("origin"));
  if (!origin) return errorResponse("origin_not_allowed", "A verified HTTPS website origin is required.", 403, {});
  let tenantId: string | null;
  try { tenantId = await resolveTenant(origin); }
  catch (error) {
    console.error("Tenant origin lookup failed", error instanceof HttpError ? error.status : "unknown");
    return errorResponse("service_unavailable", "The chat service is temporarily unavailable.", 503, {});
  }
  if (!tenantId) return errorResponse("origin_not_allowed", "This website is not registered with the mock chat service.", 403, {});
  const cors = {
    "Access-Control-Allow-Origin": origin,
    "Vary": "Origin",
    "Access-Control-Allow-Methods": "GET, POST, PATCH, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type, X-DIMDIAN-GUEST-TOKEN, Authorization",
    "Access-Control-Max-Age": "600",
  };
  if (req.method === "OPTIONS") return new Response(null, { status: 204, headers: cors });

  const url = new URL(req.url);
  const marker = "/functions/v1/chatbridge-mock-api";
  const route = url.pathname.includes(marker) ? url.pathname.slice(url.pathname.indexOf(marker) + marker.length) : "/";
  try {
    if (route.startsWith("/staff/")) {
      const staff = await authenticateStaff(req, tenantId);
      if (route === "/staff/conversations" && req.method === "GET") return await staffConversations(req, tenantId, cors, staff);
      const detailMatch = route.match(/^\/staff\/conversations\/([0-9a-f-]{36})\/(messages|attachments)$/i);
      if (detailMatch && ["GET", "POST"].includes(req.method)) {
        if (detailMatch[2] === "attachments" && req.method !== "GET") return errorResponse("method_not_allowed", "Attachments are read-only in the staff inbox.", 405, cors);
        return await staffConversationDetail(req, tenantId, cors, staff, detailMatch[1], detailMatch[2] as "messages" | "attachments");
      }
      if (route === "/staff/retention" && ["GET", "PATCH"].includes(req.method)) return await staffRetentionSettings(req, tenantId, cors, staff);
      if (route === "/staff/retention/run" && req.method === "POST") {
        if (!["owner", "admin"].includes(staff.role)) return errorResponse("admin_required", "Only tenant owners and admins can run data retention.", 403, cors);
        return json({ data: await runRetentionCleanup(tenantId) }, 200, cors);
      }
      const auditMatch = route.match(/^\/staff\/conversations\/([0-9a-f-]{36})\/audit$/i);
      if (auditMatch && req.method === "GET") return await staffConversationAudit(req, tenantId, cors, auditMatch[1]);
      const updateMatch = route.match(/^\/staff\/conversations\/([0-9a-f-]{36})$/i);
      if (updateMatch && req.method === "PATCH") return await staffUpdateConversation(req, tenantId, cors, staff, updateMatch[1]);
      return errorResponse("not_found", "The requested staff inbox route does not exist.", 404, cors);
    }
    if (route === "/session.php" && req.method === "POST") return await createSession(req, tenantId, cors);
    if (route === "/messages.php" && ["GET", "POST"].includes(req.method)) return await messages(req, tenantId, cors);
    if (route === "/attachments.php" && ["GET", "POST"].includes(req.method)) return await attachments(req, tenantId, cors);
    return errorResponse("not_found", "The requested mock API route does not exist.", 404, cors);
  } catch (error) {
    if (error instanceof HttpError) {
      if (error.message === "rate_limited") return errorResponse("rate_limited", "Too many requests. Please wait and try again.", 429, cors);
      if (error.message === "staff_auth_required" || error.message === "staff_auth_invalid") return errorResponse("unauthorized", "Sign in with an authorized support account.", 401, cors);
      if (error.message === "staff_membership_required") return errorResponse("forbidden", "Your account is not assigned to this business support inbox.", 403, cors);
      if (error.message === "session_expired") return errorResponse("session_expired", "This chat session is no longer valid. Start a new chat to continue.", 401, cors);
      if (error.message === "conversation_closed") return errorResponse("conversation_closed", "This conversation has been closed. Start a new chat to continue.", 409, cors);
      console.error("Mock API request failed", error.status);
      return errorResponse("server_error", "The chat service could not complete that request.", 500, cors);
    }
    console.error("Mock API unexpected failure");
    return errorResponse("server_error", "The chat service could not complete that request.", 500, cors);
  }
});
