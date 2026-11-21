(function () {
  'use strict';

  const startButton = document.getElementById('chatbridge-meta-start');
  if (!startButton || typeof ChatBridgeMetaSignup === 'undefined') return;
  const subscribeButton = document.getElementById('chatbridge-meta-subscribe');
  const statusNode = document.getElementById('chatbridge-meta-status');
  const phoneInput = document.getElementById('chatbridge-meta-phone-id');
  const wabaInput = document.getElementById('chatbridge-meta-waba-id');

  let signupState = null;
  let signupConfigId = null;
  let stateExpiresAt = 0;
  let authorizationCode = null;
  let signupAssets = null;
  let sdkReady = false;
  let prepared = false;
  let preparing = false;
  let exchangeStarted = false;

  function show(message, kind) {
    if (!statusNode) return;
    statusNode.className = 'notice notice-' + (kind || 'info') + ' inline';
    const paragraph = statusNode.querySelector('p') || statusNode.appendChild(document.createElement('p'));
    paragraph.textContent = message;
  }

  async function ajax(action, values) {
    const body = new URLSearchParams(Object.assign({
      action: action,
      nonce: ChatBridgeMetaSignup.nonce
    }, values || {}));
    const response = await fetch(ChatBridgeMetaSignup.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    });
    const result = await response.json();
    if (!response.ok || !result || result.success !== true) {
      const message = result && result.data && result.data.message;
      throw new Error(message || 'The WordPress server could not complete the Meta request.');
    }
    return result.data;
  }

  function loadFacebookSdk(appId, version) {
    if (window.FB && sdkReady) return Promise.resolve();
    return new Promise(function (resolve, reject) {
      const timeout = window.setTimeout(function () {
        reject(new Error('Meta JavaScript SDK did not initialize. Check browser privacy blockers and the Meta app configuration.'));
      }, 15000);
      window.fbAsyncInit = function () {
        try {
          window.FB.init({ appId: appId, cookie: true, xfbml: false, version: version });
          sdkReady = true;
          window.clearTimeout(timeout);
          resolve();
        } catch (error) {
          window.clearTimeout(timeout);
          reject(error);
        }
      };
      if (!document.getElementById('chatbridge-facebook-sdk')) {
        const script = document.createElement('script');
        script.id = 'chatbridge-facebook-sdk';
        script.async = true;
        script.defer = true;
        script.crossOrigin = 'anonymous';
        script.src = 'https://connect.facebook.net/en_US/sdk.js';
        script.onerror = function () {
          window.clearTimeout(timeout);
          reject(new Error('Could not load the Meta JavaScript SDK.'));
        };
        document.head.appendChild(script);
      }
    });
  }

  async function prepareSignup() {
    if (preparing || prepared) return;
    preparing = true;
    startButton.disabled = true;
    try {
      const setup = await ajax('chatbridge_meta_begin', {});
      await loadFacebookSdk(setup.app_id, setup.graph_api_version);
      signupState = setup.state;
      signupConfigId = setup.config_id;
      stateExpiresAt = Date.now() + (Number(setup.expires_in_seconds) || 600) * 1000;
      prepared = true;
      show('Meta Embedded Signup is ready. Click “Connect WhatsApp Business account” to open the Meta dialog.', 'info');
    } catch (error) {
      show(error.message || 'Could not prepare Meta Embedded Signup.', 'error');
    } finally {
      preparing = false;
      startButton.disabled = !prepared;
    }
  }

  async function maybeExchange() {
    if (exchangeStarted || !signupState || !authorizationCode) return;
    const wabaId = (signupAssets && signupAssets.waba_id) || (wabaInput && wabaInput.value.trim());
    const phoneId = (signupAssets && signupAssets.phone_number_id) || (phoneInput && phoneInput.value.trim());
    if (!wabaId || !phoneId) {
      show('Meta returned the authorization code. Enter the WABA ID and Phone Number ID above if they were not supplied by the signup completion event.', 'warning');
      return;
    }

    exchangeStarted = true;
    startButton.disabled = true;
    show('Exchanging the one-time Meta authorization code securely on the server…', 'info');
    try {
      const result = await ajax('chatbridge_meta_exchange', {
        state: signupState,
        code: authorizationCode,
        waba_id: wabaId,
        phone_number_id: phoneId
      });
      signupState = null;
      authorizationCode = null;
      signupAssets = null;
      prepared = false;
      if (result.webhook_subscribed) {
        show('WhatsApp Business account connected. Webhook subscription succeeded. Keep outbound sending disabled until the test-number checks are complete.', 'success');
        if (subscribeButton) subscribeButton.hidden = true;
      } else {
        show('The account token was stored securely, but Meta webhook subscription did not complete. Check app permissions, then use “Retry webhook subscription”.', 'warning');
        if (subscribeButton) subscribeButton.hidden = false;
      }
    } catch (error) {
      signupState = null;
      prepared = false;
      show(error.message || 'Meta onboarding failed. Start a fresh signup attempt; authorization codes and state values are one-time.', 'error');
    } finally {
      exchangeStarted = false;
      startButton.disabled = false;
      prepareSignup();
    }
  }

  function onMetaMessage(event) {
    if (event.origin !== 'https://www.facebook.com' && event.origin !== 'https://web.facebook.com') return;
    let message = event.data;
    if (typeof message === 'string') {
      try { message = JSON.parse(message); } catch (_) { return; }
    }
    if (!message || message.type !== 'WA_EMBEDDED_SIGNUP') return;
    const envelope = message.data || {};
    const eventName = envelope.event || message.event || '';
    const details = envelope.data || envelope;

    if (eventName === 'CANCEL' || eventName === 'ERROR') {
      signupState = null;
      authorizationCode = null;
      signupAssets = null;
      prepared = false;
      startButton.disabled = false;
      show(eventName === 'CANCEL' ? 'WhatsApp Embedded Signup was cancelled. No connection was changed.' : 'Meta reported an Embedded Signup error. Check the Meta app configuration and operator console.', eventName === 'CANCEL' ? 'warning' : 'error');
      prepareSignup();
      return;
    }
    if (eventName === 'FINISH' || eventName === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
      signupAssets = {
        waba_id: String(details.waba_id || details.wabaId || ''),
        phone_number_id: String(details.phone_number_id || details.phoneNumberId || '')
      };
      if (wabaInput && signupAssets.waba_id) wabaInput.value = signupAssets.waba_id;
      if (phoneInput && signupAssets.phone_number_id) phoneInput.value = signupAssets.phone_number_id;
      show('Meta linked the WhatsApp account. Waiting for the authorization-code callback…', 'info');
      maybeExchange();
    }
  }

  startButton.disabled = true;
  startButton.addEventListener('click', function () {
    // FB.login must run directly in the click gesture; pre-create state and load
    // the SDK before the operator clicks so browser popup blockers do not fire.
    if (!prepared || !sdkReady || !signupState || Date.now() >= stateExpiresAt) {
      prepared = false;
      show('Preparing a fresh secure signup session. Wait for the ready message, then click again.', 'info');
      prepareSignup();
      return;
    }
    authorizationCode = null;
    signupAssets = null;
    const attemptState = signupState;
    if (wabaInput) wabaInput.value = '';
    if (phoneInput) phoneInput.value = '';
    startButton.disabled = true;
    window.removeEventListener('message', onMetaMessage);
    window.addEventListener('message', onMetaMessage);
    window.FB.login(function (response) {
      if (signupState !== attemptState) return;
      if (!response || !response.authResponse || !response.authResponse.code) {
        startButton.disabled = false;
        show('Meta did not return an authorization code. If the flow was cancelled or blocked, start again.', 'warning');
        return;
      }
      authorizationCode = String(response.authResponse.code);
      show('Authorization code received. Waiting for the WhatsApp account completion event…', 'info');
      maybeExchange();
    }, {
      config_id: signupConfigId,
      auth_type: 'rerequest',
      response_type: 'code',
      override_default_response_type: true,
      extras: { setup: { solutionID: ChatBridgeMetaSignup.partnerSolutionId } }
    });
  });

  if (phoneInput) phoneInput.addEventListener('change', maybeExchange);
  if (wabaInput) wabaInput.addEventListener('change', maybeExchange);

  if (subscribeButton) {
    subscribeButton.addEventListener('click', async function () {
      subscribeButton.disabled = true;
      try {
        const result = await ajax('chatbridge_meta_subscribe', {});
        if (result.webhook_subscribed) {
          show('Meta webhook subscription is now active.', 'success');
          subscribeButton.hidden = true;
        } else {
          show('Meta did not confirm the webhook subscription. Check the app permissions and try again.', 'warning');
        }
      } catch (error) {
        show(error.message || 'Could not subscribe the WhatsApp account to webhooks.', 'error');
      } finally {
        subscribeButton.disabled = false;
      }
    });
  }

  prepareSignup();
})();
