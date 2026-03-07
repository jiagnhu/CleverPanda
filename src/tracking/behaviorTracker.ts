import { postJson } from '@/api/client';
import { getOrCreateAnonymousUserId, getOrCreateReadingSessionId } from '@/tracking/readingTracker';

const LAST_VISIT_AT_KEY = 'cp_last_visit_at';
const RETURN_VISIT_PROCESSED_KEY = 'cp_return_visit_processed_session';
const CHAPTER_COMPLETED_PREFIX = 'cp_chapter_completed_';
const RETURN_VISIT_MIN_HOURS = 24;
const RETURN_VISIT_MAX_HOURS = 72;

const chapterCompleteLocks = new Set<string>();

export type ChapterCompletionResult = 'recorded' | 'already' | 'failed';
export type ParentFeedbackChoice = 'yes' | 'no';
export type MoreChaptersResponse = 'yes' | 'no';

type BaseEventPayload = {
  event_type:
    | 'return_visit'
    | 'chapter_complete'
    | 'parent_feedback'
    | 'more_chapters_response';
  session_id: string;
  anonymous_user_id: string;
  event_at: string;
};

const postBehaviorEvent = async (payload: BaseEventPayload & Record<string, unknown>) => {
  try {
    const response = await postJson('/api/behavior_events.php', payload);
    return response.ok;
  } catch {
    return false;
  }
};

const getChapterCompletedKey = (sessionId: string, bookId: string, chapterNo: number) =>
  `${CHAPTER_COMPLETED_PREFIX}${sessionId}_${bookId}_${chapterNo}`;

const isPositiveInteger = (value: unknown): value is number =>
  typeof value === 'number' && Number.isInteger(value) && value > 0;

const toRounded = (value: number) => Math.round(value * 100) / 100;

export const trackReturnVisitOnOpen = async () => {
  const sessionId = getOrCreateReadingSessionId();
  const anonymousUserId = getOrCreateAnonymousUserId();

  try {
    const processedFor = sessionStorage.getItem(RETURN_VISIT_PROCESSED_KEY);
    if (processedFor === sessionId) {
      return;
    }
    sessionStorage.setItem(RETURN_VISIT_PROCESSED_KEY, sessionId);
  } catch {}

  const now = Date.now();
  let shouldTrack = false;
  let deltaHours = 0;
  let deltaDays = 0;

  try {
    const previous = Number(localStorage.getItem(LAST_VISIT_AT_KEY) || '');
    if (Number.isFinite(previous) && previous > 0 && now > previous) {
      const deltaMs = now - previous;
      const hours = deltaMs / 3_600_000;
      if (hours >= RETURN_VISIT_MIN_HOURS && hours <= RETURN_VISIT_MAX_HOURS) {
        shouldTrack = true;
        deltaHours = toRounded(hours);
        deltaDays = toRounded(deltaMs / 86_400_000);
      }
    }
    localStorage.setItem(LAST_VISIT_AT_KEY, String(now));
  } catch {}

  if (!shouldTrack) return;

  await postBehaviorEvent({
    event_type: 'return_visit',
    session_id: sessionId,
    anonymous_user_id: anonymousUserId,
    hours_since_last_visit: deltaHours,
    days_since_last_visit: deltaDays,
    event_at: new Date(now).toISOString()
  });
};

export const markChapterCompletedOnce = async (bookId: string, chapterNo: number): Promise<ChapterCompletionResult> => {
  const normalizedBookId = bookId.trim();
  if (!normalizedBookId || !isPositiveInteger(chapterNo)) {
    return 'failed';
  }

  const sessionId = getOrCreateReadingSessionId();
  if (!sessionId) return 'failed';

  const key = getChapterCompletedKey(sessionId, normalizedBookId, chapterNo);
  try {
    if (localStorage.getItem(key) === 'true') {
      return 'already';
    }
  } catch {}

  if (chapterCompleteLocks.has(key)) {
    return 'already';
  }

  chapterCompleteLocks.add(key);
  const anonymousUserId = getOrCreateAnonymousUserId();

  const ok = await postBehaviorEvent({
    event_type: 'chapter_complete',
    session_id: sessionId,
    anonymous_user_id: anonymousUserId,
    book_id: normalizedBookId,
    chapter_no: chapterNo,
    event_at: new Date().toISOString()
  });

  chapterCompleteLocks.delete(key);
  if (!ok) return 'failed';

  try {
    localStorage.setItem(key, 'true');
  } catch {}
  return 'recorded';
};

export const submitParentFeedback = async (
  bookId: string,
  chapterNo: number,
  choice: ParentFeedbackChoice,
  comment: string
) => {
  const normalizedBookId = bookId.trim();
  if (!normalizedBookId || !isPositiveInteger(chapterNo)) {
    return false;
  }

  const normalizedChoice = choice === 'yes' ? 'yes' : choice === 'no' ? 'no' : '';
  if (!normalizedChoice) return false;

  const sessionId = getOrCreateReadingSessionId();
  const anonymousUserId = getOrCreateAnonymousUserId();
  const normalizedComment = comment.trim();

  return postBehaviorEvent({
    event_type: 'parent_feedback',
    session_id: sessionId,
    anonymous_user_id: anonymousUserId,
    book_id: normalizedBookId,
    chapter_no: chapterNo,
    liked: normalizedChoice,
    comment: normalizedComment,
    event_at: new Date().toISOString()
  });
};

export const submitMoreChaptersResponse = async (
  bookId: string,
  chapterNo: number,
  response: MoreChaptersResponse,
  note: string
) => {
  const normalizedBookId = bookId.trim();
  if (!normalizedBookId || !isPositiveInteger(chapterNo)) {
    return false;
  }

  const normalizedResponse = response === 'yes' ? 'yes' : response === 'no' ? 'no' : '';
  if (!normalizedResponse) return false;

  const sessionId = getOrCreateReadingSessionId();
  const anonymousUserId = getOrCreateAnonymousUserId();
  const normalizedNote = note.trim();

  return postBehaviorEvent({
    event_type: 'more_chapters_response',
    session_id: sessionId,
    anonymous_user_id: anonymousUserId,
    book_id: normalizedBookId,
    chapter_no: chapterNo,
    more_chapters_response: normalizedResponse,
    more_chapters_note: normalizedNote,
    event_at: new Date().toISOString()
  });
};
