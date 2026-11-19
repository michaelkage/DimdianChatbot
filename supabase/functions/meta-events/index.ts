// Private read-only event viewer for the GitHub Pages integration test.
// Configure DEMO_MONITOR_ACCESS_KEY as a Supabase Edge Function secret.

const allowedOrigin = "https://michaelkage.github.io";
const encoder = new TextEncoder();

function equalSecret(a: string, b: string): boolean {
  if (!a || !b || a.length !== b.length) return false;
  let mismatch = 0;
  for (let i = 0; i < a.length; i++) mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return mismatch === 0;
}

function headers(origin: string | null): Headers {
  const result = new Headers({
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
    Vary: "Origin",
  });
  if (origin === allowedOrigin) {
    result.set("Access-Control-Allow-Origin", allowedOrigin);
    result.set("Access-Control-Allow-Methods", "GET, OPTIONS");
    result.set("Access-Control-Allow-Headers", "x-demo-access-key");
    result.set("Access-Control-Max-Age", "600");
  }
  return result;
}

function json(body: unknown, status = 200, origin: string | null = null): Response {
  return new Response(JSON.stringify(body), { status, headers: headers(origin) });
}

function getCredential(): { key: string; legacy: boolean } {
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

Deno.serve(async (request: Request): Promise<Response> => {
  const origin = request.headers.get("origin");
  if (request.method === "OPTIONS") {
    if (origin !== allowedOrigin) return new Response(null, { status: 403 });
    return new Response(null, { status: 204, headers: headers(origin) });
  }
  if (request.method !== "GET") return json({ error: "Method not allowed." }, 405, origin);
  if (origin !== allowedOrigin) return json({ error: "Origin not allowed." }, 403, origin);

  const expected = Deno.env.get("DEMO_MONITOR_ACCESS_KEY") ?? "";
  const supplied = request.headers.get("x-demo-access-key") ?? "";
  if (!expected) return json({ error: "Monitor is not configured." }, 503, origin);
  if (!equalSecret(expected, supplied)) return json({ error: "Unauthorized." }, 401, origin);

  const projectUrl = Deno.env.get("SUPABASE_URL");
  if (!projectUrl) return json({ error: "Supabase URL is not configured." }, 503, origin);

  try {
    const credential = getCredential();
    const query = new URLSearchParams({
      select: "id,event_field,event_kind,message_type,from_number_masked,to_number_masked,message_text,event_timestamp,processing_status,received_at",
      order: "received_at.desc",
      limit: "50",
    });
    const requestHeaders: Record<string, string> = { apikey: credential.key };
    if (credential.legacy) requestHeaders.Authorization = `Bearer ${credential.key}`;
    const response = await fetch(
      `${projectUrl.replace(/\/+$/, "")}/rest/v1/meta_webhook_events?${query.toString()}`,
      { headers: requestHeaders },
    );
    if (!response.ok) {
      console.error("Meta event query failed:", response.status, (await response.text()).slice(0, 300));
      return json({ error: "Could not load webhook events." }, 502, origin);
    }
    const rows = await response.json();
    return json({ ok: true, events: rows }, 200, origin);
  } catch (error) {
    console.error("Meta event viewer failed:", error);
    return json({ error: "Event viewer is temporarily unavailable." }, 500, origin);
  }
});
