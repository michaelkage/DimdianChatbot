import assert from "node:assert/strict";
import { randomUUID } from "node:crypto";

const base = new URL(
  process.env.DIMDIAN_API_BASE_URL || "http://127.0.0.1:8080/api/v1/",
);
const origin = process.env.DIMDIAN_CONTRACT_ORIGIN || "https://website.example.test";
let passed = 0;

async function request(path, { method = "GET", body, token, useOrigin = true } = {}) {
  const headers = { Accept: "application/json" };
  if (useOrigin) headers.Origin = origin;
  if (token) headers["X-DIMDIAN-GUEST-TOKEN"] = token;
  const options = { method, headers };
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    options.body = JSON.stringify(body);
  }
  const response = await fetch(new URL(path, base), options);
  const raw = await response.text();
  let json = null;
  if (raw !== "") {
    try {
      json = JSON.parse(raw);
    } catch {
      throw new Error(method + " " + path + " returned non-JSON: " + raw.slice(0, 300));
    }
  }
  return { status: response.status, headers: response.headers, body: json };
}

function check(condition, label) {
  assert.ok(condition, label);
  passed += 1;
  process.stdout.write("PASS " + label + "\n");
}

const session = await request("session.php", {
  method: "POST",
  body: { name: "Portable API contract visitor", whatsapp_opt_in: false },
});
check(session.status === 201, "guest session creation returns HTTP 201");
const token = session.body?.data?.session_token;
assert.match(token || "", /^[a-f0-9]{64}$/i, "session token is a 256-bit hex credential");
check(
  typeof session.body.data.session_id === "string" &&
    typeof session.body.data.conversation_id === "string" &&
    typeof session.body.data.expires_at === "string",
  "session response exposes stable identifiers and expiry",
);

const messageId = randomUUID();
const messageText = "Portable API contract message.";
const sent = await request("messages.php", {
  method: "POST",
  token,
  body: { message: messageText, client_message_id: messageId },
});
check(sent.status === 201, "authenticated visitor message creation returns HTTP 201");
check(
  sent.body?.data?.message === messageText &&
    sent.body?.data?.delivery_status === "web_only",
  "visitor message response confirms persistence without claiming WhatsApp delivery",
);
check(
  sent.body?.meta?.idempotent_replay === false,
  "first message submission is marked as a non-replay",
);

const replay = await request("messages.php", {
  method: "POST",
  token,
  body: { message: messageText, client_message_id: messageId },
});
check(
  replay.status === 200 &&
    replay.body?.data?.id === sent.body?.data?.id &&
    replay.body?.meta?.idempotent_replay === true,
  "same idempotency key and body return the original message",
);

const conflict = await request("messages.php", {
  method: "POST",
  token,
  body: { message: "Different content.", client_message_id: messageId },
});
check(conflict.status === 409, "reusing an idempotency key with different content returns HTTP 409");

const invalidAuth = await request("messages.php?after=0", {
  token: "invalid-guest-token",
});
check(invalidAuth.status === 401, "invalid guest token cannot read a conversation");

const poll = await request("messages.php?after=0&limit=50", { token });
check(
  poll.status === 200 &&
    Array.isArray(poll.body?.data) &&
    poll.body.data.some((message) => message.id === sent.body?.data?.id) &&
    typeof poll.body?.meta?.next_cursor === "number",
  "authenticated polling returns the persisted message and cursor metadata",
);

const optInWithoutPhone = await request("consent.php", {
  method: "POST",
  token,
  body: { whatsapp_opt_in: true },
});
check(
  optInWithoutPhone.status === 422 &&
    optInWithoutPhone.body?.error?.code === "whatsapp_phone_required",
  "WhatsApp opt-in without a persisted phone number is rejected",
);

const optOut = await request("consent.php", {
  method: "POST",
  token,
  body: { whatsapp_opt_in: false },
});
check(
  optOut.status === 200 &&
    optOut.body?.data?.whatsapp_opt_in === false,
  "visitor can explicitly opt out while retaining website chat",
);

const invalidConsent = await request("consent.php", {
  method: "POST",
  token,
  body: { whatsapp_opt_in: "false" },
});
check(invalidConsent.status === 422, "consent rejects non-boolean values");

process.stdout.write("\n" + passed + " portable API contract checks passed.\n");
