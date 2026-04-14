/**
 * 这个文件只负责两件事：
 * 1. 生成并复用 anonymous_user_id
 * 2. 生成并复用 session_id
 *
 * 这里沿用项目之前已经确认过的会话边界规则：
 * - 在首页 `/` 刷新时，视为一次新的测试访问，重新生成 session_id
 * - 在非首页刷新时，沿用 sessionStorage 里的 session_id
 *
 * 这样既满足“首页刷新算新访问”，又满足“demo + 正式阅读共用一个 session_id”。
 */

const ANONYMOUS_USER_KEY = 'cp_analytics_anonymous_user_id';
const SESSION_KEY = 'cp_analytics_session_id';

let sharedAnonymousUserId: string | null = null;
let sharedSessionId: string | null = null;

const createId = (prefix: string) => {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID();
  }
  return `${prefix}_${Date.now()}_${Math.random().toString(16).slice(2)}`;
};

const isHomeEntryPath = () => {
  if (typeof window === 'undefined') return false;
  const path = window.location.pathname || '/';
  return path === '/';
};

export const getOrCreateAnalyticsAnonymousUserId = () => {
  if (sharedAnonymousUserId) return sharedAnonymousUserId;

  try {
    const existing = localStorage.getItem(ANONYMOUS_USER_KEY);
    if (existing) {
      sharedAnonymousUserId = existing;
      return existing;
    }

    const generated = createId('u');
    localStorage.setItem(ANONYMOUS_USER_KEY, generated);
    sharedAnonymousUserId = generated;
    return generated;
  } catch {
    const fallback = createId('u');
    sharedAnonymousUserId = fallback;
    return fallback;
  }
};

export const getOrCreateAnalyticsSessionId = () => {
  if (sharedSessionId) return sharedSessionId;

  const createAndStore = () => {
    const next = createId('s');
    try {
      sessionStorage.setItem(SESSION_KEY, next);
    } catch {}
    return next;
  };

  if (isHomeEntryPath()) {
    sharedSessionId = createAndStore();
    return sharedSessionId;
  }

  try {
    const existing = sessionStorage.getItem(SESSION_KEY);
    if (existing) {
      sharedSessionId = existing;
      return existing;
    }
  } catch {}

  sharedSessionId = createAndStore();
  return sharedSessionId;
};

export const getAnalyticsIdentity = () => ({
  sessionId: getOrCreateAnalyticsSessionId(),
  anonymousUserId: getOrCreateAnalyticsAnonymousUserId()
});
