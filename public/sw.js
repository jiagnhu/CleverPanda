const AUDIO_CACHE = 'audio-cache-v1.3';
const FONT_CACHE = 'font-cache-v1.1';
const SVG_CACHE = 'svg-cache-v1';
const AUDIO_EXTENSIONS = ['.mp3'];
let isPrecaching = false;

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

function isAudioRequest(url) {
  if (url.pathname.startsWith('/audio/')) return true;
  return AUDIO_EXTENSIONS.some((ext) => url.pathname.endsWith(ext));
}

function isFontRequest(url) {
  return url.pathname.startsWith('/Fonts/');
}

function isSvgRequest(url) {
  return url.pathname.startsWith('/svgs/');
}

function parseRange(rangeHeader, size) {
  const match = /bytes=(\d*)-(\d*)/.exec(rangeHeader);
  if (!match) return null;
  const start = match[1] ? Number(match[1]) : 0;
  const end = match[2] ? Number(match[2]) : size - 1;
  if (Number.isNaN(start) || Number.isNaN(end) || start > end || start >= size) {
    return null;
  }
  return {
    start,
    end: Math.min(end, size - 1)
  };
}

async function createRangedResponse(cachedResponse, rangeHeader) {
  const buffer = await cachedResponse.arrayBuffer();
  const size = buffer.byteLength;
  const range = parseRange(rangeHeader, size);
  if (!range) return null;

  const sliced = buffer.slice(range.start, range.end + 1);
  return new Response(sliced, {
    status: 206,
    statusText: 'Partial Content',
    headers: {
      'Content-Type': cachedResponse.headers.get('Content-Type') || 'audio/mpeg',
      'Content-Length': String(sliced.byteLength),
      'Content-Range': `bytes ${range.start}-${range.end}/${size}`,
      'Accept-Ranges': 'bytes'
    }
  });
}

async function cacheAudioRequest(request) {
  const cache = await caches.open(AUDIO_CACHE);
  const rangeHeader = request.headers.get('range');

  if (rangeHeader) {
    const cachedResponse = await cache.match(request.url);
    if (cachedResponse) {
      const ranged = await createRangedResponse(cachedResponse, rangeHeader);
      if (ranged) return ranged;
    }
    return fetch(request);
  }

  const cached = await cache.match(request);
  if (cached) return cached;

  const response = await fetch(request);
  if (response.ok) {
    cache.put(request, response.clone());
  }
  return response;
}

async function cacheFontRequest(request) {
  const cache = await caches.open(FONT_CACHE);
  const cached = await cache.match(request);
  if (cached) return cached;

  const response = await fetch(request);
  if (response.ok) {
    cache.put(request, response.clone());
  }
  return response;
}

async function cacheSvgRequest(request) {
  const cache = await caches.open(SVG_CACHE);
  const cached = await cache.match(request);
  if (cached) return cached;

  const response = await fetch(request);
  if (response.ok) {
    cache.put(request, response.clone());
  }
  return response;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (isAudioRequest(url)) {
    event.respondWith(cacheAudioRequest(request));
    return;
  }
  if (isFontRequest(url)) {
    event.respondWith(cacheFontRequest(request));
    return;
  }
  if (isSvgRequest(url)) {
    event.respondWith(cacheSvgRequest(request));
  }
});

async function postMessageToClient(source, message) {
  if (!source) return;
  if ('id' in source) {
    const client = await self.clients.get(source.id);
    if (client) client.postMessage(message);
    return;
  }
  if (typeof source.postMessage === 'function') {
    source.postMessage(message);
  }
}

async function precacheAudio(urls, source) {
  if (isPrecaching) {
    await postMessageToClient(source, { type: 'precache-error', error: 'busy' });
    return;
  }

  if (!urls.length) {
    await postMessageToClient(source, { type: 'precache-error', error: 'empty' });
    return;
  }

  isPrecaching = true;
  let failed = 0;
  const cache = await caches.open(AUDIO_CACHE);

  for (let i = 0; i < urls.length; i += 1) {
    const url = urls[i];
    try {
      const cached = await cache.match(url);
      if (cached) {
        await postMessageToClient(source, {
          type: 'precache-progress',
          done: i + 1,
          total: urls.length
        });
        continue;
      }
      const response = await fetch(url, { cache: 'no-store' });
      if (response.ok) {
        await cache.put(url, response.clone());
      } else {
        failed += 1;
      }
    } catch {
      failed += 1;
    }

    await postMessageToClient(source, {
      type: 'precache-progress',
      done: i + 1,
      total: urls.length
    });
  }

  await postMessageToClient(source, {
    type: 'precache-complete',
    total: urls.length,
    failed
  });

  isPrecaching = false;
}

self.addEventListener('message', (event) => {
  const data = event.data;
  if (!data || data.type !== 'precache-audio') return;
  const urls = Array.isArray(data.urls) ? data.urls : [];
  event.waitUntil(precacheAudio(urls, event.source));
});
