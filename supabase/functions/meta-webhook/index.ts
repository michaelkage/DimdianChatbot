// Supabase Edge Function: Meta WhatsApp webhook for the GitHub Pages mock site.
// This function is intentionally separate from the production PHP webhook.
// Required Edge Function secrets:
//   META_APP_SECRET
//   META_WEBHOOK_VERIFY_TOKEN
// Supabase injects SUPABASE_URL and either SUPABASE_SECRET_KEYS or
// SUPABASE_SERVICE_ROLE_KEY into the function environment.

const encoder = new TextEncoder();

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" },
  });
}

function equalHex(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let mismatch = 0;
  for (let i = 0; i < a.length; i++) mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return mismatch === 0;
}

async function hmacSha256Hex(secret: string, body: Uint8Array): Promise<string> {
  const key = await crypto.subtle.importKey(
    "raw",
    encoder.encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  const signature = await crypto.subtle.sign("HMAC", key, body);
  return Array.from(new Uint8Array(signature), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

async function validMetaSignature(rawBody: Uint8Array, header: string | null, appSecret: string): Promise<boolean> {
  if (!header || !appSecret) return false;
  const match = /^sha256=([a-f0-9]{64})$/i.exec(header.trim());
  if (!match) return false;
  const expected = await hmacSha256Hex(appSecret, rawBody);
  return equalHex(expected, match[1].toLowerCase());
}

function maskNumber(value: unknown): string | null {
  const digits = String(value ?? "").replace(/\D/g, "");
  return digits.length < 5 ? null : `••••${digits.slice(-4)}`;
}

function unixTimestamp(value: unknown): string | null {
  const seconds = Number(value);
  if (!Number.isFinite(seconds) || seconds <= 0) return null;
  const date = new Date(seconds * 1000);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function getSupabaseCredential(): { key: string; legacy: boolean } {
  const encoded = Deno.env.get("SUPABASE_SECRET_KEYS");
  if (encoded) {
    try {
      const keys = JSON.parse(encoded);
      if (typeof keys.default === "string" && keys.default) return { key: keys.default, legacy: false };
    } catch {
      // Fall through to the legacy environment variable.
    }
  }
  const legacyKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY");
  if (legacyKey) return { key: legacyKey, legacy: true };
  throw new Error("Supabase server credential is not configured.");
}

async function saveEvents(rows: Record<string, unknown>[]): Promise<void> {
  if (rows.length === 0) return;
  const projectUrl = Deno.env.get("SUPABASE_URL");
  if (!projectUrl) throw new Error("SUPABASE_URL is not configured.");
  const credential = getSupabaseCredential();
  const headers: Record<string, string> = {
    apikey: credential.key,
    "Content-Type": "application/json",
    Prefer: "resolution=ignore-duplicates,return=minimal",
  };
  // Legacy service-role JWTs need the Authorization header. New secret API keys
  // are sent only as apikey, as required by Supabase's current key model.
  if (credential.legacy) headers.Authorization = `Bearer ${credential.key}`;

  const response = await fetch(
    `${projectUrl.replace(/\/+$/, "")}/rest/v1/meta_webhook_events?on_conflict=provider_event_id`,
    { method: "POST", headers, body: JSON.stringify(rows) },
  );
  if (!response.ok) {
    const detail = (await response.text()).slice(0, 500);
    console.error("Could not persist Meta webhook events:", response.status, detail);
    throw new Error("Could not persist webhook events.");
  }
}

async function digestHex(value: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", encoder.encode(value));
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function eventRow(
  providerEventId: string,
  field: string,
  kind: string,
  value: Record<string, unknown>,
  payloadHash: string,
  message?: Record<string, unknown>,
): Record<string, unknown> {
  const text = String((message?.text as Record<string, unknown> | undefined)?.body ?? "");
  return {
    provider_event_id: providerEventId,
    event_field: field,
    event_kind: kind,
    message_type: String(message?.type ?? "unknown").slice(0, 40),
    from_number_masked: maskNumber(message?.from),
    to_number_masked: maskNumber(message?.to),
    message_text: text ? text.slice(0, 4000) : null,
    event_timestamp: unixTimestamp(message?.timestamp),
    phone_number_id: String(((value.metadata as Record<string, unknown> | undefined)?.phone_number_id) ?? "").slice(0, 80) || null,
    payload_sha256: payloadHash,
    processing_status: "received",
  };
}

async function extractRows(payload: Record<string, unknown>, rawBody: Uint8Array): Promise<Record<string, unknown>[]> {
  const rows: Record<string, unknown>[] = [];
  const digest = await crypto.subtle.digest("SHA-256", rawBody);
  const payloadHash = Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
  const expectedPhoneNumberId = Deno.env.get("META_PHONE_NUMBER_ID") ?? "";
  const entries = Array.isArray(payload.entry) ? payload.entry : [];

  for (const entryValue of entries) {
    if (!entryValue || typeof entryValue !== "object") continue;
    const entry = entryValue as Record<string, unknown>;
    const changes = Array.isArray(entry.changes) ? entry.changes : [];
    for (const changeValue of changes) {
      if (!changeValue || typeof changeValue !== "object") continue;
      const change = changeValue as Record<string, unknown>;
      const field = String(change.field ?? "unknown").slice(0, 80);
      const value = change.value && typeof change.value === "object"
        ? change.value as Record<string, unknown>
        : {};
      const metadata = value.metadata && typeof value.metadata === "object"
        ? value.metadata as Record<string, unknown>
        : {};
      const receivedPhoneNumberId = String(metadata.phone_number_id ?? "");
      if (expectedPhoneNumberId && receivedPhoneNumberId !== expectedPhoneNumberId) continue;

      if (field === "smb_message_echoes") {
        const echoes = Array.isArray(value.message_echoes) ? value.message_echoes : [];
        for (const item of echoes) {
          if (!item || typeof item !== "object") continue;
          const message = item as Record<string, unknown>;
          const id = String(message.id ?? "");
          if (!id) continue;
          rows.push(eventRow(`echo:${id}`, field, "business_app_echo", value, payloadHash, message));
        }
      } else if (field === "messages") {
        const incoming = Array.isArray(value.messages) ? value.messages : [];
        for (const item of incoming) {
          if (!item || typeof item !== "object") continue;
          const message = item as Record<string, unknown>;
          const id = String(message.id ?? "");
          if (!id) continue;
          rows.push(eventRow(`inbound:${id}`, field, "incoming_message", value, payloadHash, message));
        }

        const statuses = Array.isArray(value.statuses) ? value.statuses : [];
        for (const item of statuses) {
          if (!item || typeof item !== "object") continue;
          const status = item as Record<string, unknown>;
          const id = String(status.id ?? "");
          const state = String(status.status ?? "unknown");
          const timestamp = String(status.timestamp ?? "");
          if (!id) continue;
          rows.push(eventRow(`status:${id}:${state}:${timestamp}`, field, `delivery_${state}`, value, payloadHash, status));
        }
      } else {
        const id = await digestHex(`${field}:${String(entry.id ?? "")}:${payloadHash}`);
        rows.push(eventRow(`other:${id}`, field, "other_webhook_event", value, payloadHash));
      }
    }
  }
  return rows;
}

Deno.serve(async (request: Request): Promise<Response> => {
  const url = new URL(request.url);
  const method = request.method.toUpperCase();

  if (method === "GET") {
    const mode = url.searchParams.get("hub.mode") ?? url.searchParams.get("hub_mode") ?? "";
    const token = url.searchParams.get("hub.verify_token") ?? url.searchParams.get("hub_verify_token") ?? "";
    const challenge = url.searchParams.get("hub.challenge") ?? url.searchParams.get("hub_challenge") ?? "";
    const expected = Deno.env.get("META_WEBHOOK_VERIFY_TOKEN") ?? "";
    if (mode === "subscribe" && expected && token === expected && challenge) {
      return new Response(challenge, {
        status: 200,
        headers: { "Content-Type": "text/plain; charset=utf-8", "Cache-Control": "no-store" },
      });
    }
    return new Response("Forbidden", { status: 403 });
  }

  if (method !== "POST") {
    return new Response("Method Not Allowed", { status: 405, headers: { Allow: "GET, POST" } });
  }

  const appSecret = Deno.env.get("META_APP_SECRET") ?? "";
  if (!appSecret) return json({ error: "Webhook is not configured." }, 503);

  const rawBody = new Uint8Array(await request.arrayBuffer());
  if (rawBody.byteLength > 1_048_576) return new Response("Payload Too Large", { status: 413 });

  const signature = request.headers.get("x-hub-signature-256");
  if (!(await validMetaSignature(rawBody, signature, appSecret))) {
    return new Response("Invalid signature", { status: 401 });
  }

  let payload: Record<string, unknown>;
  try {
    const parsed = JSON.parse(new TextDecoder().decode(rawBody));
    if (!parsed || typeof parsed !== "object" || parsed.object !== "whatsapp_business_account") {
      return json({ error: "Invalid WhatsApp webhook payload." }, 400);
    }
    payload = parsed as Record<string, unknown>;
  } catch {
    return json({ error: "Invalid JSON." }, 400);
  }

  try {
    const rows = await extractRows(payload, rawBody);
    await saveEvents(rows);
    return json({ ok: true, received: rows.length });
  } catch (error) {
    console.error("Meta webhook processing failed:", error);
    return json({ error: "Webhook processing failed." }, 500);
  }
});
