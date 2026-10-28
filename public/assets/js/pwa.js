/**
 * Admin PWA: register the service worker, offer Install, and manage browser push.
 */
(function () {
  'use strict';

  var cfg = window.KUMA_PWA || {};
  var swUrl = cfg.swUrl || '';
  var scope = cfg.scope || './';
  var installBtn = document.getElementById('pwa-install-btn');
  var installTip = document.getElementById('pwa-install-tip');
  var deferredPrompt = null;
  var iosInstallHint = false;

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches
      || window.navigator.standalone === true;
  }

  function isIosDevice() {
    var ua = window.navigator.userAgent || '';
    if (/iPad|iPhone|iPod/.test(ua)) {
      return true;
    }
    // iPadOS desktop UA
    return window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1;
  }

  function hideInstallTip() {
    if (installTip) {
      installTip.hidden = true;
    }
    if (installBtn) {
      installBtn.setAttribute('aria-expanded', 'false');
    }
  }

  function hideInstall() {
    if (installBtn) {
      installBtn.hidden = true;
    }
    hideInstallTip();
  }

  function showInstall() {
    if (installBtn && !isStandalone()) {
      installBtn.hidden = false;
    }
  }

  function prefersMobileChrome() {
    return window.matchMedia('(max-width: 768px)').matches
      || /Android|iPhone|iPad|iPod/i.test(window.navigator.userAgent || '');
  }

  if (isStandalone()) {
    hideInstall();
  } else if (isIosDevice() || prefersMobileChrome()) {
    // Mobile browsers often never fire beforeinstallprompt (esp. iOS Safari).
    // Still show Install so it is not buried; click opens native prompt or a tip.
    iosInstallHint = true;
    if (isIosDevice()) {
      document.documentElement.classList.add('kuma-ios');
    }
    showInstall();
  }

  function registerWorker() {
    if (!('serviceWorker' in navigator) || !swUrl) {
      return Promise.reject(new Error('Service workers are not available in this browser.'));
    }
    return navigator.serviceWorker.register(swUrl, { scope: scope });
  }

  if ('serviceWorker' in navigator && swUrl) {
    window.addEventListener('load', function () {
      registerWorker().catch(function () {
        // Installability still works without a registered worker on some browsers.
      });
    });
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferredPrompt = event;
    showInstall();
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    hideInstall();
  });

  if (installBtn) {
    installBtn.addEventListener('click', function () {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.finally(function () {
          deferredPrompt = null;
          hideInstall();
        });
        return;
      }
      if (iosInstallHint && installTip) {
        var open = installTip.hidden;
        installTip.hidden = !open;
        installBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
    });
  }

  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw = window.atob(base64);
    var output = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) {
      output[i] = raw.charCodeAt(i);
    }
    return output;
  }

  function postForm(fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (key) {
      body.set(key, fields[key]);
    });
    if (cfg.csrfToken) {
      body.set(cfg.csrfField || 'app_csrf', cfg.csrfToken);
    }
    return fetch(cfg.pushApiUrl || 'api-push-subscription.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString()
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data || !data.ok) {
          throw new Error((data && data.error) || 'The push request failed.');
        }
        return data;
      });
    });
  }

  function getPushStatus() {
    return fetch(cfg.pushApiUrl || 'api-push-subscription.php', {
      method: 'GET',
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json();
    });
  }

  function enablePush() {
    if (!('Notification' in window) || !('PushManager' in window)) {
      return Promise.reject(new Error('This browser does not support push alerts.'));
    }
    if (!cfg.vapidPublicKey) {
      return Promise.reject(new Error('Browser push keys are not ready on this server.'));
    }
    return registerWorker()
      .then(function () {
        return navigator.serviceWorker.ready;
      })
      .then(function (reg) {
        return Notification.requestPermission().then(function (permission) {
          if (permission !== 'granted') {
            throw new Error('Notification permission was not granted.');
          }
          return reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(cfg.vapidPublicKey)
          });
        });
      })
      .then(function (subscription) {
        var json = subscription.toJSON();
        var keys = json.keys || {};
        return postForm({
          action: 'subscribe',
          endpoint: json.endpoint || '',
          p256dh: keys.p256dh || '',
          auth: keys.auth || ''
        });
      });
  }

  function disablePush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      return Promise.reject(new Error('This browser does not support push alerts.'));
    }
    return navigator.serviceWorker.ready
      .then(function (reg) {
        return reg.pushManager.getSubscription();
      })
      .then(function (subscription) {
        if (!subscription) {
          return { ok: true, subscribed: false, deviceCount: 0 };
        }
        var endpoint = subscription.endpoint;
        return subscription.unsubscribe().then(function () {
          return postForm({
            action: 'unsubscribe',
            endpoint: endpoint
          });
        });
      });
  }

  window.KUMA_PWA_PUSH = {
    enable: enablePush,
    disable: disablePush,
    status: getPushStatus
  };

  function wireBrowserButtons() {
    var board = document.getElementById('notifications-card');
    var enableBtn = document.getElementById('notify-browser-enable');
    var disableBtn = document.getElementById('notify-browser-disable');
    var statusEl = document.getElementById('notify-browser-status');
    var messageEl = document.getElementById('notify-browser-message');
    if (!enableBtn && !disableBtn) {
      return;
    }

    function setBrowserMessage(text, isError) {
      if (!messageEl) {
        return;
      }
      messageEl.hidden = !text;
      messageEl.textContent = text || '';
      messageEl.style.color = isError ? '#d32f2f' : '#2e7d32';
    }

    function refreshBrowserStatus(data) {
      if (!statusEl || !data) {
        return;
      }
      var count = typeof data.deviceCount === 'number' ? data.deviceCount : 0;
      statusEl.textContent = count > 0
        ? (count + ' device' + (count === 1 ? '' : 's') + ' subscribed.')
        : 'No device is subscribed yet.';
    }

    if (enableBtn && !enableBtn.dataset.kumaWired) {
      enableBtn.dataset.kumaWired = '1';
      enableBtn.addEventListener('click', function () {
        setBrowserMessage('Asking this browser for permission…', false);
        enablePush().then(function (data) {
          refreshBrowserStatus(data);
          var alertBox = board ? board.querySelector('input[name="notify_alert_browser"]') : null;
          if (alertBox) {
            alertBox.checked = true;
          }
          setBrowserMessage('This device will receive browser alerts. Save notifications if the Alerts box was off.', false);
        }).catch(function (err) {
          setBrowserMessage((err && err.message) || 'Could not enable browser alerts.', true);
        });
      });
    }

    if (disableBtn && !disableBtn.dataset.kumaWired) {
      disableBtn.dataset.kumaWired = '1';
      disableBtn.addEventListener('click', function () {
        setBrowserMessage('Removing this device…', false);
        disablePush().then(function (data) {
          refreshBrowserStatus(data);
          setBrowserMessage('This device will no longer receive browser alerts.', false);
        }).catch(function (err) {
          setBrowserMessage((err && err.message) || 'Could not disable browser alerts.', true);
        });
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireBrowserButtons);
  } else {
    wireBrowserButtons();
  }
})();
