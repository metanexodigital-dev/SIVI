/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/sw.js
 * Propósito: Implementa el Service Worker de la PWA y controla la caché de recursos estáticos.
 * Los bloques críticos se comentan para facilitar soporte y mantenimiento.
 */
'use strict';

const CACHE_NAME = 'sivi-static-1.0.0.0';
const STATIC_ASSETS = [
  './offline.html',
  './assets/vendor/bootstrap.min.css',
  './assets/vendor/bootstrap.bundle.min.js',
  './assets/app.css',
  './assets/app.js',
  './assets/mobile-scanner.css',
  './assets/mobile-scanner.js',
  './assets/sivi-onboarding.js',
  './assets/sivi-onboarding.css',
  './assets/plate-entry.js',
  './assets/plate-ux.css',
  './assets/report-print.js',
  './assets/report-print.css',
  './assets/admin-plate-policy.css',
  './assets/offline.css',
  './assets/brand/pwa/icon-192x192.png',
  './assets/brand/pwa/icon-512x512.png',
  './assets/brand/favicon/favicon.ico',
  './manifest.webmanifest'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key.startsWith('sivi-static-') && key !== CACHE_NAME)
        .map((key) => caches.delete(key))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Nunca se guardan respuestas autenticadas ni páginas de inventario.
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request, {cache: 'no-store'}).catch(() => caches.match('./offline.html')));
    return;
  }

  const isStatic = url.pathname.includes('/assets/')
    || url.pathname.endsWith('/manifest.webmanifest')
    || url.pathname.endsWith('/offline.html');
  if (!isStatic) return;

  event.respondWith(
    caches.match(request).then((cached) => cached || fetch(request, {cache: 'no-store'}).then((response) => {
      if (response && response.ok) {
        const copy = response.clone();
        event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.put(request, copy)));
      }
      return response;
    }))
  );
});
