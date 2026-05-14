import { buildApiUrl } from '@/api/client';
import { getAnalyticsIdentity, getOrCreateAnalyticsSessionId } from '@/analytics/identity';

const ANALYTICS_ENDPOINT = buildApiUrl('/api/analytics.php');
const CLICK_FLUSH_THRESHOLD = 3;
const FLUSH_INTERVAL_MS = 2000;
const CHAPTER_COMPLETE_PREFIX = 'cp_analytics_chapter_complete';

export type ContentChapterMeta = {
  bookId: string;
  chapterNo: number;
  contentVersion: string;
  totalInteractivePositions: number;
  contentUrl?: string;
};

export type WordTapPayload = {
  bookId: string;
  chapterNo: number;
  contentVersion: string;
  pageNo: number;
  lineIndex: number;
  interactiveIndexInLine: number;
  positionKey: string;
  word: string;
  chapterTotalInteractivePositions: number;
};

export type ParentFeedbackChoice = 'yes' | 'no';
export type MoreChaptersResponse = 'yes' | 'no';
export type ChapterCompletionResult = 'recorded' | 'already' | 'failed';
export type ContinuationAction =
  | 'start_reading_click'
  | 'onboarding_to_story_click'
  | 'next_chapter_click';

type ContentEventType =
  | 'page_dwell'
  | 'audio_play_started'
  | 'audio_play_completed'
  | 'content_load_failed'
  | 'session_interrupted';

type ContentEventPayload = {
  type: ContentEventType;
  book_id?: string;
  chapter_no?: number;
  content_version?: string;
  page_no?: number;
  position_key?: string;
  word?: string;
  payload?: Record<string, unknown>;
  event_at: string;
};

type AnalyticsEventPayload =
  | {
      type: 'word_tap';
      book_id: string;
      chapter_no: number;
      content_version: string;
      page_no: number;
      position_key: string;
      word: string;
      payload?: Record<string, unknown>;
      chapter_total_interactive_positions: number;
      event_at: string;
    }
  | {
      type: 'page_view';
      book_id: string;
      chapter_no: number;
      content_version: string;
      page_no: number;
      payload?: {
        line_index?: number;
      };
      event_at: string;
    }
  | {
      type: 'chapter_complete';
      book_id: string;
      chapter_no: number;
      content_version: string;
      event_at: string;
    }
  | {
      type: 'parent_feedback';
      book_id: string;
      chapter_no: number;
      liked: ParentFeedbackChoice;
      comment: string;
      event_at: string;
    }
  | {
      type: 'more_chapters_response';
      book_id: string;
      chapter_no: number;
      response: MoreChaptersResponse;
      note: string;
      event_at: string;
    }
  | {
      type: ContinuationAction;
      book_id?: string;
      chapter_no?: number;
      payload?: Record<string, unknown>;
      event_at: string;
    }
  | ContentEventPayload;

type SyncPayload = {
  action: 'sync_session';
  session_id: string;
  anonymous_user_id: string;
  session_started_at?: string;
  active_duration_delta_ms?: number;
  session_ended_at?: string;
  content_chapters?: Array<{
    book_id: string;
    chapter_no: number;
    content_version: string;
    total_interactive_positions: number;
    content_url?: string;
  }>;
  events?: AnalyticsEventPayload[];
};

type PendingSnapshot = {
  activeDurationDeltaMs: number;
  pendingWordTapCount: number;
  contentChapters: ContentChapterMeta[];
  events: AnalyticsEventPayload[];
};

let appSessionInitialized = false;
let listenersBound = false;

let sessionStartedAt: string | null = null;
let sessionStartAcknowledged = false;

let pendingActiveDuration = 0;
let pendingWordTapCount = 0;
let pendingEvents: AnalyticsEventPayload[] = [];
let pendingContentChapters = new Map<string, ContentChapterMeta>();

let activeSegmentStartedAt: number | null = null;
let sending = false;
let flushQueued = false;
let flushTimer: number | null = null;

const isSameOriginUrl = (targetUrl: string) => {
  if (typeof window === 'undefined') return true;
  try {
    const resolved = new URL(targetUrl, window.location.href);
    return resolved.origin === window.location.origin;
  } catch {
    return true;
  }
};

const canUseBeacon = isSameOriginUrl(ANALYTICS_ENDPOINT);

const clampDelta = (value: number) => (Number.isFinite(value) && value > 0 ? Math.floor(value) : 0);

const nowIso = () => new Date().toISOString();

const hasPendingSync = () => {
  return (
    !sessionStartAcknowledged ||
    pendingActiveDuration > 0 ||
    pendingContentChapters.size > 0 ||
    pendingEvents.length > 0
  );
};

const postAnalytics = async (payload: SyncPayload) => {
  const response = await fetch(ANALYTICS_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });

  if (!response.ok) {
    throw new Error(`analytics_request_failed_${response.status}`);
  }
};

const getChapterCompleteKey = (bookId: string, chapterNo: number) => {
  const sessionId = getOrCreateAnalyticsSessionId();
  return `${CHAPTER_COMPLETE_PREFIX}_${sessionId}_${bookId}_${chapterNo}`;
};

const startActiveSegment = () => {
  if (typeof document === 'undefined') return;
  if (document.visibilityState !== 'visible') return;
  if (activeSegmentStartedAt !== null) return;
  activeSegmentStartedAt = Date.now();
};

const stopActiveSegment = () => {
  if (activeSegmentStartedAt === null) return;
  const delta = clampDelta(Date.now() - activeSegmentStartedAt);
  activeSegmentStartedAt = null;
  if (delta > 0) {
    pendingActiveDuration += delta;
  }
};

/**
 * 活跃时长不能只在 hidden/pagehide 时结算。
 * 如果用户一直停留在前台，我们也要把“已经发生的前台时间”定期滚入 pending delta，
 * 否则 duration 会系统性偏小。
 */
const rollActiveSegment = () => {
  if (activeSegmentStartedAt === null) return;
  const now = Date.now();
  const delta = clampDelta(now - activeSegmentStartedAt);
  if (delta <= 0) return;

  pendingActiveDuration += delta;
  activeSegmentStartedAt = now;
};

const ensureSessionClock = () => {
  if (!sessionStartedAt) {
    sessionStartedAt = nowIso();
  }
};

const buildPayload = (sessionEndedAt?: string) => {
  if (!sessionEndedAt) {
    rollActiveSegment();
  }

  if (!hasPendingSync() && !sessionEndedAt) return null;

  const { sessionId, anonymousUserId } = getAnalyticsIdentity();
  ensureSessionClock();

  const snapshot: PendingSnapshot = {
    activeDurationDeltaMs: pendingActiveDuration,
    pendingWordTapCount,
    contentChapters: Array.from(pendingContentChapters.values()),
    events: pendingEvents.slice()
  };

  pendingActiveDuration = 0;
  pendingWordTapCount = 0;
  pendingContentChapters = new Map();
  pendingEvents = [];

  const payload: SyncPayload = {
    action: 'sync_session',
    session_id: sessionId,
    anonymous_user_id: anonymousUserId
  };

  if (!sessionStartAcknowledged && sessionStartedAt) {
    payload.session_started_at = sessionStartedAt;
  }

  if (snapshot.activeDurationDeltaMs > 0) {
    payload.active_duration_delta_ms = snapshot.activeDurationDeltaMs;
  }

  if (snapshot.contentChapters.length > 0) {
    payload.content_chapters = snapshot.contentChapters.map((item) => ({
      book_id: item.bookId,
      chapter_no: item.chapterNo,
      content_version: item.contentVersion,
      total_interactive_positions: item.totalInteractivePositions,
      content_url: item.contentUrl
    }));
  }

  if (snapshot.events.length > 0) {
    payload.events = snapshot.events;
  }

  if (sessionEndedAt) {
    payload.session_ended_at = sessionEndedAt;
  }

  return { payload, snapshot };
};

const restoreSnapshot = (snapshot: PendingSnapshot) => {
  pendingActiveDuration += snapshot.activeDurationDeltaMs;
  pendingWordTapCount += snapshot.pendingWordTapCount;

  snapshot.contentChapters.forEach((item) => {
    pendingContentChapters.set(
      `${item.bookId}_${item.chapterNo}_${item.contentVersion}`,
      item
    );
  });

  pendingEvents = snapshot.events.concat(pendingEvents);
};

const flush = async () => {
  if (sending) {
    flushQueued = true;
    return false;
  }

  const built = buildPayload();
  if (!built) return true;

  sending = true;
  try {
    await postAnalytics(built.payload);

    if (built.payload.session_started_at) {
      sessionStartAcknowledged = true;
    }

    return true;
  } catch {
    restoreSnapshot(built.snapshot);
    return false;
  } finally {
    sending = false;
    if (flushQueued) {
      flushQueued = false;
      void flush();
    }
  }
};

const flushBestEffort = () => {
  stopActiveSegment();

  const built = buildPayload(nowIso());
  if (!built) return;

  const body = JSON.stringify(built.payload);
  let sent = false;

  if (canUseBeacon && typeof navigator !== 'undefined' && 'sendBeacon' in navigator) {
    sent = navigator.sendBeacon(ANALYTICS_ENDPOINT, new Blob([body], { type: 'application/json' }));
  }

  if (!sent) {
    void fetch(ANALYTICS_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body,
      keepalive: true
    }).catch(() => {
      restoreSnapshot(built.snapshot);
    });
    return;
  }

  if (built.payload.session_started_at) {
    sessionStartAcknowledged = true;
  }
};

const onVisibilityChange = () => {
  if (typeof document === 'undefined') return;

  if (document.visibilityState === 'visible') {
    startActiveSegment();
    return;
  }

  stopActiveSegment();
  void flush();
};

const onPageHide = () => {
  flushBestEffort();
};

const ensureFlushInterval = () => {
  if (flushTimer !== null) return;
  flushTimer = window.setInterval(() => {
    if (!hasPendingSync()) return;
    void flush();
  }, FLUSH_INTERVAL_MS);
};

const ensureListeners = () => {
  if (listenersBound) return;
  listenersBound = true;

  document.addEventListener('visibilitychange', onVisibilityChange);
  window.addEventListener('pagehide', onPageHide);
  window.addEventListener('beforeunload', onPageHide);
};

/**
 * 整个 App 生命周期里只初始化一次。
 *
 * 它负责真正的“会话开始时间”和“前台活跃时长”：
 * - 会话开始：页面首次进入应用
 * - 活跃时长：只统计前台可见时间
 */
export const initAnalyticsAppSession = () => {
  if (appSessionInitialized) return;
  appSessionInitialized = true;

  ensureSessionClock();
  ensureListeners();
  ensureFlushInterval();
  startActiveSegment();
  void flush();
};

export const registerAnalyticsContentChapter = (meta: ContentChapterMeta) => {
  initAnalyticsAppSession();
  if (!meta.bookId.trim() || meta.chapterNo <= 0 || meta.totalInteractivePositions <= 0) {
    return;
  }

  pendingContentChapters.set(`${meta.bookId}_${meta.chapterNo}_${meta.contentVersion}`, meta);
  void flush();
};

export const trackAnalyticsPageView = (payload: {
  bookId: string;
  chapterNo: number;
  contentVersion: string;
  pageNo: number;
  lineIndex?: number;
}) => {
  initAnalyticsAppSession();
  if (!payload.bookId.trim() || payload.chapterNo <= 0 || payload.pageNo <= 0) return;

  pendingEvents.push({
    type: 'page_view',
    book_id: payload.bookId,
    chapter_no: payload.chapterNo,
    content_version: payload.contentVersion,
    page_no: payload.pageNo,
    payload:
      typeof payload.lineIndex === 'number'
        ? {
            line_index: payload.lineIndex
          }
        : undefined,
    event_at: nowIso()
  });
};

export const trackAnalyticsWordTap = (payload: WordTapPayload) => {
  initAnalyticsAppSession();
  if (!payload.bookId.trim() || payload.chapterNo <= 0 || payload.pageNo <= 0 || !payload.positionKey.trim()) {
    return;
  }

  pendingEvents.push({
    type: 'word_tap',
    book_id: payload.bookId,
    chapter_no: payload.chapterNo,
    content_version: payload.contentVersion,
    page_no: payload.pageNo,
    position_key: payload.positionKey,
    word: payload.word,
    chapter_total_interactive_positions: payload.chapterTotalInteractivePositions,
    payload: {
      line_index: payload.lineIndex,
      interactive_index_in_line: payload.interactiveIndexInLine
    },
    event_at: nowIso()
  });

  pendingWordTapCount += 1;
  if (pendingWordTapCount >= CLICK_FLUSH_THRESHOLD) {
    void flush();
  }
};

const pushContentEvent = (event: Omit<ContentEventPayload, 'event_at'>, flushImmediately = false) => {
  initAnalyticsAppSession();
  pendingEvents.push({
    ...event,
    event_at: nowIso()
  });
  if (flushImmediately) {
    void flush();
  }
};

export const trackAnalyticsPageDwell = (payload: {
  bookId: string;
  chapterNo: number;
  contentVersion: string;
  pageNo: number;
  dwellMs: number;
  bestEffort?: boolean;
}) => {
  if (!payload.bookId.trim() || payload.chapterNo <= 0 || payload.pageNo <= 0 || payload.dwellMs <= 0) return;

  pushContentEvent({
    type: 'page_dwell',
    book_id: payload.bookId,
    chapter_no: payload.chapterNo,
    content_version: payload.contentVersion,
    page_no: payload.pageNo,
    payload: {
      dwell_ms: Math.floor(payload.dwellMs)
    }
  });

  if (payload.bestEffort) {
    flushBestEffort();
  }
};

export const trackAnalyticsContinuationAction = (payload: {
  action: ContinuationAction;
  bookId?: string;
  chapterNo?: number | null;
  targetBookId?: string;
  targetChapterNo?: number | null;
  flow?: string;
}) => {
  initAnalyticsAppSession();
  pendingEvents.push({
    type: payload.action,
    book_id: payload.bookId,
    chapter_no: payload.chapterNo ?? undefined,
    payload: {
      target_book_id: payload.targetBookId,
      target_chapter_no: payload.targetChapterNo,
      flow: payload.flow
    },
    event_at: nowIso()
  });
  void flush();
};

export const trackAnalyticsAudioPlayStarted = (payload: {
  bookId: string;
  chapterNo: number;
  contentVersion: string;
  pageNo: number;
  positionKey: string;
  word: string;
  audioMode: 'local' | 'speech';
}) => {
  if (!payload.bookId.trim() || payload.chapterNo <= 0 || payload.pageNo <= 0 || !payload.positionKey.trim()) return;
  pushContentEvent({
    type: 'audio_play_started',
    book_id: payload.bookId,
    chapter_no: payload.chapterNo,
    content_version: payload.contentVersion,
    page_no: payload.pageNo,
    position_key: payload.positionKey,
    word: payload.word,
    payload: {
      audio_mode: payload.audioMode
    }
  });
};

export const trackAnalyticsAudioPlayCompleted = (payload: {
  bookId: string;
  chapterNo: number;
  contentVersion: string;
  pageNo: number;
  positionKey: string;
  word: string;
  audioMode: 'local';
}) => {
  if (!payload.bookId.trim() || payload.chapterNo <= 0 || payload.pageNo <= 0 || !payload.positionKey.trim()) return;
  pushContentEvent({
    type: 'audio_play_completed',
    book_id: payload.bookId,
    chapter_no: payload.chapterNo,
    content_version: payload.contentVersion,
    page_no: payload.pageNo,
    position_key: payload.positionKey,
    word: payload.word,
    payload: {
      audio_mode: payload.audioMode
    }
  });
};

export const trackAnalyticsContentLoadFailed = (payload: {
  bookId?: string;
  chapterNo?: number | null;
  contentUrl?: string;
  source: string;
  message?: string;
  status?: number;
}) => {
  pushContentEvent(
    {
      type: 'content_load_failed',
      book_id: payload.bookId,
      chapter_no: payload.chapterNo ?? undefined,
      payload: {
        content_url: payload.contentUrl,
        source: payload.source,
        message: payload.message,
        status: payload.status
      }
    },
    true
  );
};

export const trackAnalyticsSessionInterrupted = (payload: {
  bookId: string;
  chapterNo: number;
  contentVersion: string;
  pageNo: number;
  reason: string;
}) => {
  if (!payload.bookId.trim() || payload.chapterNo <= 0 || payload.pageNo <= 0) return;
  pushContentEvent({
    type: 'session_interrupted',
    book_id: payload.bookId,
    chapter_no: payload.chapterNo,
    content_version: payload.contentVersion,
    page_no: payload.pageNo,
    payload: {
      reason: payload.reason
    }
  });
  flushBestEffort();
};

/**
 * 这里用 sessionStorage 做一次前端防重，原因不是“以客户端为准”，
 * 而是为了避免同一会话里重复弹出家长反馈。
 *
 * 真正的数据去重仍然会在服务端做。
 */
export const recordAnalyticsChapterCompleteOnce = async (
  bookId: string,
  chapterNo: number,
  contentVersion: string
): Promise<ChapterCompletionResult> => {
  initAnalyticsAppSession();
  if (!bookId.trim() || chapterNo <= 0) return 'failed';

  const key = getChapterCompleteKey(bookId, chapterNo);
  try {
    if (sessionStorage.getItem(key) === '1') {
      return 'already';
    }
  } catch {}

  pendingEvents.push({
    type: 'chapter_complete',
    book_id: bookId,
    chapter_no: chapterNo,
    content_version: contentVersion,
    event_at: nowIso()
  });

  const ok = await flush();
  if (!ok) return 'failed';

  try {
    sessionStorage.setItem(key, '1');
  } catch {}

  return 'recorded';
};

export const submitAnalyticsParentFeedback = async (
  bookId: string,
  chapterNo: number,
  liked: ParentFeedbackChoice,
  comment: string
) => {
  initAnalyticsAppSession();
  if (!bookId.trim() || chapterNo <= 0) return false;

  pendingEvents.push({
    type: 'parent_feedback',
    book_id: bookId,
    chapter_no: chapterNo,
    liked,
    comment: comment.trim(),
    event_at: nowIso()
  });

  return flush();
};

export const submitAnalyticsMoreChaptersResponse = async (
  bookId: string,
  chapterNo: number,
  response: MoreChaptersResponse,
  note: string
) => {
  initAnalyticsAppSession();
  if (!bookId.trim() || chapterNo <= 0) return false;

  pendingEvents.push({
    type: 'more_chapters_response',
    book_id: bookId,
    chapter_no: chapterNo,
    response,
    note: note.trim(),
    event_at: nowIso()
  });

  return flush();
};
