<?php
declare(strict_types=1);
require_once dirname(__DIR__,3) . '/app/bootstrap.php';
$apiBase = rtrim((string)app_config('app.base_url',''),'/') . '/api/';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Support inbox · Dimdian</title>
<link rel="stylesheet" href="./admin.css">
</head>
<body data-api-base="<?= htmlspecialchars($apiBase,ENT_QUOTES,'UTF-8') ?>">
<main class="shell">
  <section id="login-view" class="login-view">
    <form id="login-form" class="login-card">
      <p class="eyebrow">DIMDIAN / SUPPORT</p>
      <h1>Staff sign in</h1>
      <p class="muted">Sign in to open the customer support inbox.</p>
      <label>Email address<input name="email" type="email" autocomplete="username" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <p class="form-error" id="login-error" role="status"></p>
      <button type="submit" class="primary">Sign in</button>
    </form>
  </section>

  <section id="inbox-view" class="inbox-view" hidden>
    <aside class="queue">
      <div class="queue-head">
        <div><p class="eyebrow">DIMDIAN / SUPPORT</p><h1>Inbox</h1></div>
        <button id="logout-button" class="quiet" type="button">Sign out</button>
      </div>
      <div class="queue-tools">
        <select id="status-filter" aria-label="Filter conversations">
          <option value="active">Active</option><option value="needs_human">Needs human</option><option value="open">Open</option><option value="closed">Closed</option><option value="all">All conversations</option>
        </select>
      </div>
      <div id="conversation-list" class="conversation-list"><p class="empty-state">Loading conversations…</p></div>
      <div class="queue-foot" id="staff-identity"></div>
    </aside>

    <section class="thread">
      <div id="thread-empty" class="thread-empty">
        <div class="empty-mark">↗</div><h2>Choose a conversation</h2><p>Customer messages and part context will appear here.</p>
      </div>
      <div id="thread-view" class="thread-view" hidden>
        <header class="thread-head">
          <div class="thread-customer"><h2 id="customer-name">Customer</h2><p id="customer-meta"></p></div>
          <div class="thread-actions">
            <a id="product-link" href="#" target="_blank" rel="noopener noreferrer" hidden>View listing ↗</a>
            <select id="thread-status" aria-label="Conversation status"><option value="open">Open</option><option value="needs_human">Needs human</option><option value="closed">Closed</option></select>
          </div>
        </header>
        <div id="thread-messages" class="thread-messages" aria-live="polite"></div>
        <form id="reply-form" class="reply-form">
          <p id="reply-error" class="form-error" role="status"></p>
          <textarea id="reply-body" rows="2" maxlength="5000" placeholder="Reply to customer…" required></textarea>
          <div class="reply-foot"><span>Internal support team · customer-visible reply</span><button type="submit" class="primary">Send reply</button></div>
        </form>
      </div>
    </section>
  </section>
</main>
<script src="./admin.js" defer></script>
</body>
</html>
