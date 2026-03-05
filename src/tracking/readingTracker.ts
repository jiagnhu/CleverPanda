import { buildApiUrl } from '@/api/client';

type TrackingPayload = {
  session_id: string;
  anonymous_user_id: string;
  click_count_delta?: number;
  active_duration_delta_ms?: number;
  reached_sentence_6?: true;
};

type ReadingTracker = {
  sessionId: string;
  anonymousUserId: string;
  onWordClick: () => void;
  onReachedSentence6: () => void;
  onVisibilityChange: () => void;
  onPageActiveChange: (active: boolean) => void;
  flushBestEffort: () => void;
  dispose: () => void;
};

const CLICK_FLUSH_THRESHOLD = 3;
const FLUSH_INTERVAL_MS = 2000;
const ANONYMOUS_USER_KEY = 'cp_anonymous_user_id';
const READING_SESSION_KEY = 'cp_reading_session_id';
let sharedReadingSessionId: string | null = null;

const createId = (prefix: string) => {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID();
  }
  return `${prefix}_${Date.now()}_${Math.random().toString(16).slice(2)}`;
};

export const getOrCreateAnonymousUserId = () => {
  try {
    const existing = localStorage.getItem(ANONYMOUS_USER_KEY);
    if (existing) return existing;
    const generated = createId('u');
    localStorage.setItem(ANONYMOUS_USER_KEY, generated);
    return generated;
  } catch {
    return createId('u');
  }
};

export const getOrCreateReadingSessionId = () => {
  if (sharedReadingSessionId) return sharedReadingSessionId;

  const isHomePath = (() => {
    if (typeof window === 'undefined') return false;
    const path = window.location.pathname || '/';
    return path === '/';
  })();

  const createAndStore = () => {
    const next = createId('s');
    try {
      sessionStorage.setItem(READING_SESSION_KEY, next);
    } catch {}
    return next;
  };

  if (isHomePath) {
    sharedReadingSessionId = createAndStore();
    return sharedReadingSessionId;
  }

  try {
    const existing = sessionStorage.getItem(READING_SESSION_KEY);
    if (existing) {
      sharedReadingSessionId = existing;
      return sharedReadingSessionId;
    }
  } catch {}

  sharedReadingSessionId = createAndStore();
  return sharedReadingSessionId;
};

const clampDelta = (value: number) => (Number.isFinite(value) && value > 0 ? Math.floor(value) : 0);

const isSameOriginUrl = (targetUrl: string) => {
  if (typeof window === 'undefined') return true;
  try {
    const resolved = new URL(targetUrl, window.location.href);
    return resolved.origin === window.location.origin;
  } catch {
    return true;
  }
};

export const createReadingTracker = (path = '/api/tracking.php'): ReadingTracker => {
  const endpoint = buildApiUrl(path);
  const canUseBeacon = isSameOriginUrl(endpoint);
  const sessionId = getOrCreateReadingSessionId();
  const anonymousUserId = getOrCreateAnonymousUserId();

  let pendingClicks = 0;
  let pendingActiveDuration = 0;
  let pendingReachedSentence6 = false;
  let pendingSessionCreate = true;
  let reachedSentence6Recorded = false;

  let segmentStartAt: number | null = null;
  let isPageActive = false;

  let sending = false;
  let flushQueued = false;
  let disposed = false;

  const hasPending = () =>
    pendingSessionCreate || pendingClicks > 0 || pendingActiveDuration > 0 || pendingReachedSentence6;

  const startActiveSegment = () => {
    if (segmentStartAt !== null) return;
    if (!isPageActive) return;
    if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return;
    segmentStartAt = Date.now();
  };

  const stopActiveSegment = () => {
    if (segmentStartAt === null) return;
    const delta = clampDelta(Date.now() - segmentStartAt);
    segmentStartAt = null;
    if (delta > 0) {
      pendingActiveDuration += delta;
    }
  };

  const buildPayload = (): TrackingPayload | null => {
    if (!hasPending()) return null;
    const payload: TrackingPayload = {
      session_id: sessionId,
      anonymous_user_id: anonymousUserId
    };
    if (pendingSessionCreate) {
      pendingSessionCreate = false;
    }
    if (pendingClicks > 0) {
      payload.click_count_delta = pendingClicks;
      pendingClicks = 0;
    }
    if (pendingActiveDuration > 0) {
      payload.active_duration_delta_ms = pendingActiveDuration;
      pendingActiveDuration = 0;
    }
    if (pendingReachedSentence6) {
      payload.reached_sentence_6 = true;
      pendingReachedSentence6 = false;
    }
    return payload;
  };

  const restorePayload = (payload: TrackingPayload) => {
    pendingSessionCreate = true;
    pendingClicks += clampDelta(payload.click_count_delta ?? 0);
    pendingActiveDuration += clampDelta(payload.active_duration_delta_ms ?? 0);
    if (payload.reached_sentence_6) {
      pendingReachedSentence6 = true;
    }
  };

  const postPayload = async (payload: TrackingPayload) => {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    if (!response.ok) {
      throw new Error(`tracking_request_failed_${response.status}`);
    }
  };

  const flush = async () => {
    if (disposed) return;
    if (sending) {
      flushQueued = true;
      return;
    }
    const payload = buildPayload();
    if (!payload) return;
    sending = true;
    try {
      await postPayload(payload);
    } catch {
      restorePayload(payload);
    } finally {
      sending = false;
      if (flushQueued) {
        flushQueued = false;
        void flush();
      }
    }
  };

  const flushBestEffort = () => {
    if (disposed) return;
    stopActiveSegment();
    const payload = buildPayload();
    if (!payload) return;

    const body = JSON.stringify(payload);
    let sent = false;
    if (canUseBeacon && typeof navigator !== 'undefined' && 'sendBeacon' in navigator) {
      sent = navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
    }
    if (!sent) {
      void fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
        keepalive: true
      }).catch(() => {
        restorePayload(payload);
      });
    }
  };

  const flushInterval = window.setInterval(() => {
    if (!hasPending()) return;
    void flush();
  }, FLUSH_INTERVAL_MS);
  void flush();

  return {
    sessionId,
    anonymousUserId,
    onWordClick: () => {
      if (disposed) return;
      pendingClicks += 1;
      if (pendingClicks >= CLICK_FLUSH_THRESHOLD) {
        void flush();
      }
    },
    onReachedSentence6: () => {
      if (disposed || reachedSentence6Recorded) return;
      reachedSentence6Recorded = true;
      pendingReachedSentence6 = true;
      void flush();
    },
    onVisibilityChange: () => {
      if (disposed) return;
      if (document.visibilityState === 'visible') {
        startActiveSegment();
        return;
      }
      stopActiveSegment();
      void flush();
    },
    onPageActiveChange: (active: boolean) => {
      if (disposed) return;
      isPageActive = active;
      if (active) {
        startActiveSegment();
        return;
      }
      stopActiveSegment();
      void flush();
    },
    flushBestEffort,
    dispose: () => {
      if (disposed) return;
      flushBestEffort();
      disposed = true;
      window.clearInterval(flushInterval);
      stopActiveSegment();
    }
  };
};
