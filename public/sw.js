/* Simple KUMA admin service worker — installability + future push hooks. */
self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function () {
  // Network only. Admin pages stay live; this worker is for install + push.
});

self.addEventListener('push', function (event) {
  var title = 'Simple KUMA';
  var options = {
    body: 'You have a new alert.',
    icon: './assets/images/pwa-192.png',
    badge: './assets/images/pwa-192.png',
    data: { url: './index.php?page=dashboard' }
  };

  if (event.data) {
    try {
      var payload = event.data.json();
      if (payload && typeof payload === 'object') {
        if (payload.title) {
          title = String(payload.title);
        }
        if (payload.body) {
          options.body = String(payload.body);
        }
        if (payload.url) {
          options.data.url = String(payload.url);
        }
      }
    } catch (err) {
      var text = event.data.text();
      if (text) {
        options.body = text;
      }
    }
  }

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = (event.notification.data && event.notification.data.url)
    ? event.notification.data.url
    : './index.php?page=dashboard';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        if ('focus' in client) {
          client.focus();
          if ('navigate' in client) {
            return client.navigate(target);
          }
          return client;
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(target);
      }
      return undefined;
    })
  );
});
