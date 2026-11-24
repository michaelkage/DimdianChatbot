import assert from "node:assert/strict";
import { randomBytes, randomUUID, createHmac } from "node:crypto";

const publicBase = new URL(process.env.DIMDIAN_API_BASE_URL || "http://127.0.0.1:8080/api/v1/");
if (!publicBase.pathname.endsWith("/")) publicBase.pathname += "/";
const staffBase = new URL("staff/", publicBase);
const origin = process.env.DIMDIAN_CONTRACT_ORIGIN || "https://website.example.test";
const secret = process.env.DIMDIAN_STAFF_ASSERTION_SECRET || "s".repeat(64);
let passed = 0;

function check(condition, label) {
  assert.ok(condition, label);
  passed += 1;
  process.stdout.write("PASS " + label + "\n");
}

async function readJson(response, label) {
  const raw = await response.text();
  try {
    return raw === "" ? null : JSON.parse(raw);
  } catch {
    throw new Error(label + " returned non-JSON: " + raw.slice(0, 300));
  }
}

async function publicRequest(path, method, body, token) {
  const headers = { Accept: "application/json", Origin: origin };
  const options = { method, headers };
  if (token) headers["X-DIMDIAN-GUEST-TOKEN"] = token;
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    options.body = JSON.stringify(body);
  }
  const response = await fetch(new URL(path, publicBase), options);
  return { status: response.status, body: await readJson(response, method + " " + path) };
}

function makeAssertion(identity, method, target, rawBody) {
  const payload = {
    auth_provider: "portable-contract-test",
    external_subject: identity.subject,
    display_name: identity.name,
    email: identity.email,
    iat: Math.floor(Date.now() / 1000),
    nonce: randomBytes(32).toString("base64url"),
    method: method.toUpperCase(),
    target,
    body_sha256: createHmac("sha256", "unused").update("").digest("hex"),
  };
  // The API requires an ordinary SHA-256 digest of the exact body, not an HMAC.
  payload.body_sha256 = createHash(rawBody);
  const encoded = Buffer.from(JSON.stringify(payload)).toString("base64url");
  const signature = createHmac("sha256", secret).update(encoded).digest("base64url");
  return encoded + "." + signature;
}

function createHash(value) {
  return requireHash(value);
}

import { createHash as requireHash } from "node:crypto";

async function staffRequest(path, identity, method = "GET", body) {
  const url = new URL(path, staffBase);
  const rawBody = body === undefined ? "" : JSON.stringify(body);
  const target = url.pathname + url.search;
  const headers = {
    Accept: "application/json",
    "X-ChatBridge-Staff-Assertion": makeAssertion(identity, method, target, rawBody),
  };
  const options = { method, headers };
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    options.body = rawBody;
  }
  const response = await fetch(url, options);
  return { status: response.status, body: await readJson(response, method + " " + path) };
}

async function createConversation(label) {
  const session = await publicRequest("session.php", "POST", { name: label, whatsapp_opt_in: false });
  assert.equal(session.status, 201, "public session creation should succeed");
  const token = session.body?.data?.session_token;
  assert.match(token || "", /^[a-f0-9]{64}$/i);
  const sent = await publicRequest(
    "messages.php",
    "POST",
    { message: "Initial visitor message for " + label, client_message_id: randomUUID() },
    token,
  );
  assert.equal(sent.status, 201, "initial visitor message should persist");
  return { conversationId: session.body.data.conversation_id, token };
}

const agentA = {
  subject: "agent-a-" + randomUUID(),
  name: "Portable Contract Agent A",
  email: "agent-a-" + randomUUID() + "@example.test",
};
const agentB = {
  subject: "agent-b-" + randomUUID(),
  name: "Portable Contract Agent B",
  email: "agent-b-" + randomUUID() + "@example.test",
};

const conversationA = await createConversation("Staff contract visitor A");
const conversationB = await createConversation("Staff contract visitor B");

const meA = await staffRequest("me.php", agentA);
const meB = await staffRequest("me.php", agentB);
check(meA.status === 200 && meA.body?.data?.role === "agent", "first signed identity resolves to a local agent role");
check(meB.status === 200 && meB.body?.data?.role === "agent", "second signed identity resolves to a local agent role");

const inboxA = await staffRequest("inbox.php?status=open", agentA);
check(
  inboxA.status === 200 &&
    inboxA.body?.data?.items?.some((item) => item.id === conversationA.conversationId) &&
    inboxA.body?.data?.items?.some((item) => item.id === conversationB.conversationId),
  "agents can see unassigned open conversations",
);

const assignA = await staffRequest("assignments.php", agentA, "POST", {
  conversation_id: conversationA.conversationId,
  action: "assign",
  staff_identity_id: meA.body.data.id,
});
const assignB = await staffRequest("assignments.php", agentB, "POST", {
  conversation_id: conversationB.conversationId,
  action: "assign",
  staff_identity_id: meB.body.data.id,
});
check(assignA.status === 200 && assignA.body?.data?.assigned === true, "agent can self-assign an unassigned conversation");
check(assignB.status === 200 && assignB.body?.data?.assigned === true, "second agent can self-assign a different conversation");

const scopedInboxA = await staffRequest("inbox.php?status=open", agentA);
check(
  scopedInboxA.status === 200 &&
    scopedInboxA.body.data.items.some((item) => item.id === conversationA.conversationId) &&
    !scopedInboxA.body.data.items.some((item) => item.id === conversationB.conversationId),
  "agent inbox excludes conversations assigned to another agent",
);

const crossRead = await staffRequest("messages.php?conversation_id=" + conversationB.conversationId, agentA);
check(crossRead.status === 403, "agent cannot read another agent's conversation");

const steal = await staffRequest("assignments.php", agentA, "POST", {
  conversation_id: conversationB.conversationId,
  action: "assign",
  staff_identity_id: meA.body.data.id,
});
check(steal.status === 403, "agent cannot self-assign a conversation already owned by another agent");

const threadA = await staffRequest("messages.php?conversation_id=" + conversationA.conversationId, agentA);
check(
  threadA.status === 200 &&
    threadA.body?.data?.items?.some((item) => item.body === "Initial visitor message for Staff contract visitor A"),
  "assigned agent can read the visitor message thread",
);

const noteId = randomUUID();
const noteBody = "Private note that must not enter the visitor thread.";
const note = await staffRequest("notes.php", agentA, "POST", {
  id: noteId,
  conversation_id: conversationA.conversationId,
  body: noteBody,
});
const noteReplay = await staffRequest("notes.php", agentA, "POST", {
  id: noteId,
  conversation_id: conversationA.conversationId,
  body: noteBody,
});
const notes = await staffRequest("notes.php?conversation_id=" + conversationA.conversationId, agentA);
check(
  note.status === 200 &&
    note.body?.data?.created === true &&
    noteReplay.status === 200 &&
    noteReplay.body?.data?.created === false &&
    notes.body?.data?.items?.some((item) => item.body === noteBody),
  "private notes support idempotent creation and authenticated retrieval",
);
check(
  !threadA.body.data.items.some((item) => item.body === noteBody),
  "private notes are excluded from visitor-visible messages",
);

const replyId = randomUUID();
const reply = await staffRequest("replies.php", agentA, "POST", {
  conversation_id: conversationA.conversationId,
  message: "Website-only staff reply.",
  client_message_id: replyId,
});
check(
  reply.status === 200 &&
    reply.body?.data?.message?.sender_type === "business" &&
    reply.body?.data?.message?.delivery_status === "web_only" &&
    reply.body?.data?.transport === "web_only",
  "staff reply is persisted as a website-only business message",
);

const history = await staffRequest("history.php?conversation_id=" + conversationA.conversationId, agentA);
check(
  history.status === 200 && history.body?.data?.items?.length === 1,
  "assigned agent can read assignment history",
);

const adminOnlyDirectory = await staffRequest("agents.php", agentA);
const adminOnlyAudit = await staffRequest("audit.php?conversation_id=" + conversationA.conversationId, agentA);
const adminOnlyRoles = await staffRequest("roles.php", agentA, "POST", {
  staff_identity_id: meA.body.data.id,
  role: "admin",
  status: "active",
});
check(
  adminOnlyDirectory.status === 403 &&
    adminOnlyAudit.status === 403 &&
    adminOnlyRoles.status === 403,
  "agent is denied staff-directory, audit and role-management operations",
);

const unassign = await staffRequest("assignments.php", agentA, "POST", {
  conversation_id: conversationA.conversationId,
  action: "unassign",
});
const readAfterUnassign = await staffRequest("messages.php?conversation_id=" + conversationA.conversationId, agentA);
check(
  unassign.status === 200 &&
    unassign.body?.data?.unassigned === true &&
    readAfterUnassign.status === 403,
  "agent loses conversation access immediately after unassignment",
);

const agentBThread = await staffRequest("messages.php?conversation_id=" + conversationB.conversationId, agentB);
check(agentBThread.status === 200, "second agent retains access to its own assigned conversation");

process.stdout.write("\n" + passed + " backend-neutral staff API checks passed.\n");
