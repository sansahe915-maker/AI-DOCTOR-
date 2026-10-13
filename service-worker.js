'use strict';

const CACHE_NAME = 'ai-doctor-static-v2';
const STATIC_ASSETS = [
  './style.css?v=20261013-outbreak-education-1',
  './app.js?v=20261013-phone-install-1',
  './icons/app-icon.svg'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((key) => key.startsWith('ai-doctor-static-') && key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (
    request.method !== 'GET' ||
    url.origin !== self.location.origin ||
    !/\/(?:style\.css|app\.js)$/.test(url.pathname) &&
    !url.pathname.endsWith('/icons/app-icon.svg')
  ) {
    return;
  }

  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      if (cachedResponse) return cachedResponse;
      return fetch(request).then((response) => {
        if (!response.ok) return response;
        const responseCopy = response.clone();
        return caches.open(CACHE_NAME)
          .then((cache) => cache.put(request, responseCopy))
          .then(() => response);
      });
    })
  );
});
