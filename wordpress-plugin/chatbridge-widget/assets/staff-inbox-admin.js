(function () {
  'use strict';

  const root = document.getElementById('chatbridge-staff-inbox');
  if (!root || !window.ChatBridgeStaffInbox) return;

  const $ = (id) => document.getElementById(id);
  const state = { me: null, conversations: [], activeId: null, agents: [], staff: [], searchTimer: null };
  const statusText = $('cb-staff-status-text');

  function setStatus(message, isError) {
    statusText.textContent = message || '';
    statusText.classList.toggle('is-error', Boolean(isError));
  }

  async function request(action, values) {
    const params = new URLSearchParams();
    params.set('action', action);
    params.set('nonce', ChatBridgeStaffInbox.nonce);
    Object.keys(values || {}).forEach((key) => {
      if (values[key] !== undefined && values[key] !== null) params.set(key, String(values[key]));
    });
    const response = await fetch(ChatBridgeStaffInbox.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: params.toString()
    });
    const result = await response.json();
    if (!response.ok || !result || result.success !== true) {
      const message = result && result.data && result.data.message
        ? result.data.message
        : 'The staff request failed.';
      throw new Error(message);
    }
    return result.data;
  }

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function uuidV4() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
    else for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
  }

  function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
  }

  function renderConversations() {
    const host = $('cb-staff-conversations');
    host.replaceChildren();
    if (!state.conversations.length) {
      host.appendChild(element('p', 'cb-staff-empty', 'No conversations in this view.'));
      return;
    }
    state.conversations.forEach((conversation) => {
      const button = element('button', 'cb-staff-conversation');
      button.type = 'button';
      button.classList.toggle('is-active', conversation.id === state.activeId);
      button.appendChild(element('strong', 'cb-staff-conversation-name', conversation.visitor_name || 'Website visitor'));
      button.appendChild(element('span', 'cb-staff-conversation-phone', conversation.visitor_phone || 'No phone number'));
      button.appendChild(element('small', 'cb-staff-conversation-meta',
        (conversation.assigned_staff_name ? 'Assigned: ' + conversation.assigned_staff_name : 'Unassigned') +
        ' · ' + (conversation.status || 'open')));
      button.addEventListener('click', () => selectConversation(conversation.id));
      host.appendChild(button);
    });
  }

  function currentConversation() {
    return state.conversations.find((item) => item.id === state.activeId) || null;
  }

  function renderAssignmentControls() {
    const conversation = currentConversation();
    const select = $('cb-staff-assignee');
    const selfAssign = $('cb-staff-self-assign');
    const unassign = $('cb-staff-unassign');
    select.hidden = true;
    selfAssign.hidden = true;
    unassign.hidden = true;
    if (!conversation || !state.me) return;

    const admin = state.me.role === 'owner' || state.me.role === 'admin';
    if (admin) {
      select.hidden = false;
      select.replaceChildren();
      const none = element('option', '', 'Unassigned');
      none.value = '';
      select.appendChild(none);
      state.agents.filter((agent) => agent.status === 'active').forEach((agent) => {
        const option = element('option', '', agent.display_name + ' (' + agent.role + ')');
        option.value = agent.id;
        select.appendChild(option);
      });
      if (conversation.assigned_staff_id && !state.agents.some((agent) => agent.id === conversation.assigned_staff_id)) {
        const disabledAssignee = element('option', '', (conversation.assigned_staff_name || 'Former staff') + ' (inactive)');
        disabledAssignee.value = conversation.assigned_staff_id;
        disabledAssignee.disabled = true;
        select.appendChild(disabledAssignee);
      }
      select.value = conversation.assigned_staff_id || '';
    } else if (!conversation.assigned_staff_id) {
      selfAssign.hidden = false;
    } else if (conversation.assigned_staff_id === state.me.id) {
      unassign.hidden = false;
    }
  }

  function renderMessages(items) {
    const host = $('cb-staff-messages');
    host.replaceChildren();
    if (!items || !items.length) {
      host.appendChild(element('p', 'cb-staff-empty', 'No messages yet.'));
      return;
    }
    items.forEach((message) => {
      const article = element('article', 'cb-staff-message ' + (message.sender_type === 'visitor' ? 'is-visitor' : 'is-business'));
      const heading = element('div', 'cb-staff-message-heading',
        message.sender_type === 'visitor' ? 'Visitor' : (message.staff_author_name || 'Business'));
      const body = element('p', 'cb-staff-message-body', message.body || '');
      const meta = element('small', 'cb-staff-message-meta',
        formatDate(message.sent_at) + (message.delivery_status ? ' · ' + message.delivery_status : ''));
      article.append(heading, body, meta);
      host.appendChild(article);
    });
    host.scrollTop = host.scrollHeight;
  }

  function renderNotes(items) {
    const host = $('cb-staff-note-list');
    host.replaceChildren();
    if (!items || !items.length) {
      host.appendChild(element('p', 'cb-staff-empty', 'No private notes yet.'));
      return;
    }
    items.forEach((note) => {
      const article = element('article', 'cb-staff-note');
      article.appendChild(element('p', 'cb-staff-note-body', note.body || ''));
      article.appendChild(element('small', 'cb-staff-note-meta',
        (note.author_name || 'Staff') + ' · ' + formatDate(note.created_at)));
      host.appendChild(article);
    });
  }

  async function loadConversation(id) {
    const conversation = state.conversations.find((item) => item.id === id);
    if (!conversation) return;
    state.activeId = id;
    $('cb-staff-thread-title').textContent = conversation.visitor_name || 'Website visitor';
    $('cb-staff-thread-subtitle').textContent =
      (conversation.visitor_phone || 'No phone number') + ' · ' + conversation.status;
    renderConversations();
    renderAssignmentControls();
    const [messages, notes] = await Promise.all([
      request('chatbridge_staff_messages', { conversation_id: id, after: 0 }),
      request('chatbridge_staff_notes', { conversation_id: id, after: 0 })
    ]);
    renderMessages(messages.items || []);
    renderNotes(notes.items || []);
  }

  async function selectConversation(id) {
    try {
      setStatus('Loading conversation…', false);
      await loadConversation(id);
      setStatus('', false);
    } catch (error) {
      setStatus(error.message, true);
    }
  }

  async function loadAgents() {
    if (!state.me || !['owner', 'admin'].includes(state.me.role)) return;
    const result = await request('chatbridge_staff_agents', {});
    state.agents = result.items || [];
  }

  async function loadStaffDirectory() {
    if (!state.me || !['owner', 'admin'].includes(state.me.role)) return;
    const result = await request('chatbridge_staff_agents', {});
    state.staff = result.items || [];
    state.agents = state.staff.filter((person) => person.status === 'active');
    if (state.me.role === 'owner') renderStaffAccess();
  }

  function renderStaffAccess() {
    const panel = $('cb-staff-role-panel');
    const host = $('cb-staff-role-list');
    if (!panel || !host || !state.me || state.me.role !== 'owner') return;
    panel.hidden = false;
    host.replaceChildren();
    if (!state.staff.length) {
      host.appendChild(element('p', 'cb-staff-empty', 'No staff identities have signed in yet.'));
      return;
    }
    state.staff.forEach((person) => {
      const row = element('div', 'cb-staff-role-row');
      row.appendChild(element('strong', 'cb-staff-role-name', person.display_name + (person.email ? ' · ' + person.email : '')));
      const role = document.createElement('select');
      role.setAttribute('aria-label', 'Role for ' + person.display_name);
      [['agent', 'Agent'], ['admin', 'Admin'], ['owner', 'Owner']].forEach(([value, label]) => {
        const option = element('option', '', label);
        option.value = value;
        role.appendChild(option);
      });
      role.value = person.role;
      const status = document.createElement('select');
      status.setAttribute('aria-label', 'Status for ' + person.display_name);
      [['active', 'Active'], ['disabled', 'Disabled']].forEach(([value, label]) => {
        const option = element('option', '', label);
        option.value = value;
        status.appendChild(option);
      });
      status.value = person.status;
      const save = element('button', 'button', 'Save');
      save.type = 'button';
      save.addEventListener('click', async () => {
        try {
          save.disabled = true;
          await request('chatbridge_staff_role_update', {
            staff_identity_id: person.id,
            role: role.value,
            status: status.value
          });
          setStatus('Staff access updated.', false);
          await loadStaffDirectory();
          await refresh();
        } catch (error) {
          setStatus(error.message, true);
        } finally {
          save.disabled = false;
        }
      });
      row.append(role, status, save);
      host.appendChild(row);
    });
  }

  async function loadConversations() {
    const result = await request('chatbridge_staff_inbox', {
      status: $('cb-staff-status').value,
      after: 0,
      limit: 100,
      search: $('cb-staff-search').value.trim()
    });
    state.conversations = result.items || [];
    renderConversations();
    if (state.activeId && state.conversations.some((item) => item.id === state.activeId)) {
      renderAssignmentControls();
    } else if (state.activeId) {
      state.activeId = null;
      $('cb-staff-thread-title').textContent = 'Select a conversation';
      $('cb-staff-thread-subtitle').textContent = '';
      $('cb-staff-messages').replaceChildren(element('p', 'cb-staff-empty', 'Messages will appear here.'));
      $('cb-staff-note-list').replaceChildren();
      renderAssignmentControls();
    }
  }

  async function refresh() {
    try {
      setStatus('Refreshing…', false);
      await loadConversations();
      if (state.activeId) await loadConversation(state.activeId);
      setStatus('Updated just now.', false);
    } catch (error) {
      setStatus(error.message, true);
    }
  }

  $('cb-staff-refresh').addEventListener('click', refresh);
  $('cb-staff-status').addEventListener('change', () => {
    state.activeId = null;
    refresh();
  });
  $('cb-staff-search').addEventListener('input', () => {
    window.clearTimeout(state.searchTimer);
    state.searchTimer = window.setTimeout(() => {
      state.activeId = null;
      refresh();
    }, 250);
  });

  $('cb-staff-send').addEventListener('click', async () => {
    const conversation = currentConversation();
    const message = $('cb-staff-reply').value.trim();
    if (!conversation || !message) return;
    try {
      setStatus('Sending website reply…', false);
      await request('chatbridge_staff_reply', {
        conversation_id: conversation.id,
        message: message,
        client_message_id: uuidV4()
      });
      $('cb-staff-reply').value = '';
      await loadConversation(conversation.id);
      setStatus('Reply sent to the website conversation.', false);
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  $('cb-staff-add-note').addEventListener('click', async () => {
    const conversation = currentConversation();
    const body = $('cb-staff-note').value.trim();
    if (!conversation || !body) return;
    try {
      setStatus('Saving private note…', false);
      await request('chatbridge_staff_note_create', { conversation_id: conversation.id, body: body });
      $('cb-staff-note').value = '';
      const notes = await request('chatbridge_staff_notes', { conversation_id: conversation.id, after: 0 });
      renderNotes(notes.items || []);
      setStatus('Private note saved. It is not visible to the customer.', false);
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  $('cb-staff-assignee').addEventListener('change', async () => {
    const conversation = currentConversation();
    if (!conversation) return;
    try {
      const staffId = $('cb-staff-assignee').value;
      await request('chatbridge_staff_assign', {
        conversation_id: conversation.id,
        staff_identity_id: staffId,
        assignment_action: staffId ? 'assign' : 'unassign'
      });
      await refresh();
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  $('cb-staff-self-assign').addEventListener('click', async () => {
    const conversation = currentConversation();
    if (!conversation || !state.me) return;
    try {
      await request('chatbridge_staff_assign', {
        conversation_id: conversation.id,
        staff_identity_id: state.me.id,
        assignment_action: 'assign'
      });
      await refresh();
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  $('cb-staff-unassign').addEventListener('click', async () => {
    const conversation = currentConversation();
    if (!conversation) return;
    try {
      await request('chatbridge_staff_assign', {
        conversation_id: conversation.id,
        assignment_action: 'unassign'
      });
      await refresh();
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  async function init() {
    try {
      state.me = await request('chatbridge_staff_me', {});
      await loadStaffDirectory();
      await refresh();
      window.setInterval(() => {
        if (document.visibilityState === 'visible') refresh();
      }, 20000);
    } catch (error) {
      setStatus(error.message, true);
      $('cb-staff-conversations').replaceChildren(element('p', 'cb-staff-empty', error.message));
    }
  }

  init();
})();
