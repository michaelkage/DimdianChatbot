(function () {
  'use strict';

  const root = document.getElementById('chatbridge-meta-onboarding');
  if (!root) return;

  const button = document.getElementById('chatbridge-meta-connect');
  const status = document.getElementById('chatbridge-meta-status');
  const appId = root.dataset.appId || '';
  const configId = root.dataset.configId || '';
  const solutionId = root.dataset.solutionId || '';
  const graphVersion = root.dataset.graphVersion || 'v27.0';
  const ajaxUrl = root.dataset.ajaxUrl || '';
  const nonce = root.dataset.nonce || '';

  let signupCode = '';
  let signupSessionInfo = null;
  let submitting = false;

  function setStatus(message, isError) {
    status.textContent = message;
    status.style.color = isError ? '#b32d2e' : '';
  }

  function initFacebookSdk() {
    if (!window.FB || !appId) return;
    window.FB.init({ appId: appId, version: graphVersion, xfbml: false, autoLogAppEvents: false });
  }

  if (window.FB) {
    initFacebookSdk();
  } else {
    window.fbAsyncInit = initFacebookSdk;
  }

  function trustedMetaOrigin(origin) {
    try {
      const parsed = new URL(origin);
      return parsed.protocol === 'https:' && (parsed.hostname === 'facebook.com' || parsed.hostname.endsWith('.facebook.com'));
    } catch (_) {
      return false;
    }
  }

  function parseMessage(data) {
    if (typeof data === 'string') {
      try { return JSON.parse(data); } catch (_) { return null; }
    }
    return data && typeof data === 'object' ? data : null;
  }

  async function completeSignup() {
    if (submitting || !signupCode || !signupSessionInfo) return;
    submitting = true;
    button.disabled = true;
    setStatus('Finishing secure account connection with ChatBridge…', false);
    try {
      const body = new URLSearchParams();
      body.set('action', 'chatbridge_meta_finish');
      body.set('nonce', nonce);
      body.set('code', signupCode);
      body.set('session_info', JSON.stringify(signupSessionInfo));
      const response = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        const message = result && result.data && result.data.message
          ? result.data.message
          : 'Meta onboarding could not be completed. Please try again.';
        throw new Error(message);
      }
      const data = result.data || {};
      const connections = Array.isArray(data.connections) ? data.connections : [];
      const numbers = connections.map(function (item) {
        return item.display_phone_number || item.phone_number_id;
      }).filter(Boolean);
      setStatus(
        'WhatsApp connected (' + (data.connection_mode || 'unknown') + '). ' +
        (numbers.length ? 'Number(s): ' + numbers.join(', ') : 'The account is connected.'),
        false
      );
      signupCode = '';
      signupSessionInfo = null;
    } catch (error) {
      setStatus(error && error.message ? error.message : 'Connection failed. Please try again.', true);
    } finally {
      submitting = false;
      button.disabled = false;
    }
  }

  window.addEventListener('message', function (event) {
    if (!trustedMetaOrigin(event.origin)) return;
    const message = parseMessage(event.data);
    if (!message || message.type !== 'WA_EMBEDDED_SIGNUP') return;

    const data = message.data && typeof message.data === 'object' ? message.data : {};
    const eventName = String(data.event || '').toUpperCase();
    if (eventName === 'CANCEL') {
      setStatus('The Meta signup flow was cancelled. No connection was saved.', true);
      signupCode = '';
      signupSessionInfo = null;
      return;
    }
    if (eventName === 'ERROR') {
      setStatus('Meta reported an error during signup. Please retry or check the Meta app configuration.', true);
      signupCode = '';
      signupSessionInfo = null;
      return;
    }
    if (eventName === 'FINISH' || eventName === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
      signupSessionInfo = data;
      completeSignup();
    }
  });

  button.addEventListener('click', function () {
    if (!window.FB) {
      setStatus('Meta SDK is still loading. Wait a moment and try again.', true);
      return;
    }
    signupCode = '';
    signupSessionInfo = null;
    const extras = { version: 'v5' };
    if (solutionId) extras.setup = { solutionID: solutionId };
    button.disabled = true;
    setStatus('Opening Meta Embedded Signup v5…', false);

    window.FB.login(function (response) {
      button.disabled = false;
      const code = response && response.authResponse && response.authResponse.code;
      if (!code) {
        setStatus('Meta login was cancelled or did not return an authorization code.', true);
        return;
      }
      signupCode = String(code);
      setStatus('Meta authorized the request. Waiting for the signup completion details…', false);
      completeSignup();
    }, {
      config_id: configId,
      auth_type: 'rerequest',
      response_type: 'code',
      override_default_response_type: true,
      extras: extras
    });
  });
}());
