<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import {
  buildInteractivePositionKey,
  countChapterInteractivePositions,
  createChapterContentVersion
} from '@/analytics/contentMetrics';
import {
  initAnalyticsAppSession,
  recordAnalyticsChapterCompleteOnce,
  registerAnalyticsContentChapter,
  trackAnalyticsAudioPlayCompleted,
  trackAnalyticsAudioPlayStarted,
  trackAnalyticsContentLoadFailed,
  trackAnalyticsContinuationAction,
  trackAnalyticsPageView,
  trackAnalyticsPageDwell,
  trackAnalyticsSessionInterrupted,
  trackAnalyticsWordTap
} from '@/analytics/manager';
import { getOrCreateAnalyticsSessionId } from '@/analytics/identity';
import BilingualLine from '@/components/BilingualLine.vue';
import MoreChaptersModal from '@/components/MoreChaptersModal.vue';
import ParentFeedbackModal from '@/components/ParentFeedbackModal.vue';
import ProgressBar from '@/components/ProgressBar.vue';
import { playWord, setAudioConfig, setUseLocalAudio } from '@/audio/player';
import { collectEnRichWords, parseEnRichLine } from '@/utils/enRich';
import { canonicalize, tokenize } from '@/utils/tokenize';

const props = withDefaults(
  defineProps<{
    active?: boolean;
    contentUrl?: string;
    demoEndPage?: number | null;
    bookId?: string;
    chapterNo?: number | null;
    showNextChapterButton?: boolean;
    nextChapterLabel?: string;
    showBackToChaptersButton?: boolean;
  }>(),
  {
    active: true,
    contentUrl: '',
    demoEndPage: null,
    bookId: '',
    chapterNo: null,
    showNextChapterButton: false,
    nextChapterLabel: '下一章',
    showBackToChaptersButton: false
  }
);

const emit = defineEmits<{
  (e: 'edge-prev'): void;
  (e: 'edge-next'): void;
  (e: 'demo-complete'): void;
  (e: 'next-chapter'): void;
  (e: 'exit-home'): void;
}>();

type ChapterItem = {
  id: string;
  pageNo: number;
  zhLines: string[];
  enLines: string[];
};

type AudioConfig = {
  cacheKey?: string;
  baseUrl?: string;
  manifest?: Record<string, string>;
};

type LegacyChapterData = {
  items: ChapterItem[];
  interactiveWords?: string[];
  chapter?: RichChapterPayload | RichChapterPayload[];
  chapters?: RichChapterPayload[];
};

type RichPageData = {
  page?: number;
  zh?: string | string[];
  mandarin?: string | string[];
  enRich?: string | string[];
  enRichLines?: string[];
  en?: string | string[];
  enLines?: string[];
};

type RichChapterPayload = {
  number?: number;
  titleEnglish?: string;
  titleMandarin?: string;
  pages?: RichPageData[];
  audio?: AudioConfig;
};

type RichChapterData = {
  schemaVersion?: string;
  chapter?: RichChapterPayload | RichChapterPayload[];
  chapters?: RichChapterPayload[];
  pages?: RichPageData[];
};

type NormalizedChapterData = {
  bookId: string;
  chapterNo: number | null;
  items: ChapterItem[];
  interactiveWords: string[];
  contentVersion: string;
  totalInteractivePositions: number;
  audio: {
    cacheKey: string;
    baseUrl: string;
    manifest: Record<string, string>;
  } | null;
};

const items = ref<ChapterItem[]>([]);
const currentIndex = ref(0);
const currentItem = computed(() => items.value[currentIndex.value] ?? null);
const progress = computed(() => {
  if (!items.value.length) return 0;
  return (currentIndex.value + 1) / items.value.length;
});
const isLastPage = computed(
  () => items.value.length > 0 && currentIndex.value === items.value.length - 1
);
const showNextChapterCta = computed(
  () => isLastPage.value && props.showNextChapterButton && !props.demoEndPage
);
const showBackToChaptersCta = computed(
  () =>
    isLastPage.value &&
    !showNextChapterCta.value &&
    props.showBackToChaptersButton &&
    !props.demoEndPage
);
const lastPageNextChapterUnlocked = ref(false);
const nextChapterEnabled = computed(
  () => !currentLastInteractiveTarget.value || lastPageNextChapterUnlocked.value
);
const showBackToChaptersButton = computed(
  () => showBackToChaptersCta.value && nextChapterEnabled.value
);

const interactiveSet = shallowRef<Set<string>>(new Set());
const loading = ref(true);
const loadError = ref('');
const analyticsBookId = ref('');
const analyticsChapterNo = ref<number | null>(null);
const contentVersion = ref('');
const totalInteractivePositions = ref(0);

const audioBaseOverride = import.meta.env.VITE_AUDIO_BASE_URL;
const buildStamp = typeof __BUILD_TIME__ === 'string' ? __BUILD_TIME__ : '';
const cacheBust = buildStamp ? `?v=${encodeURIComponent(buildStamp)}` : '';
const audioConfig = ref<{ cacheKey: string; baseUrl: string; manifest: Record<string, string> }>({
  cacheKey: '',
  baseUrl: '',
  manifest: {}
});

const hasLoaded = ref(false);
const chapterCompletionRecorded = ref(false);
const parentFeedbackVisible = ref(false);
const moreChaptersModalVisible = ref(false);
const lastTrackedPageViewKey = ref('');
const precacheStatus = ref<'idle' | 'downloading' | 'done' | 'error'>('idle');
const precacheProgress = ref({ done: 0, total: 0 });
const precachePercent = computed(() => {
  const { done, total } = precacheProgress.value;
  if (!total) return 0;
  return Math.min(100, Math.round((done / total) * 100));
});

const normalizeBaseUrl = (url: string) => (url.endsWith('/') ? url : `${url}/`);
const SWIPE_THRESHOLD = 48;
const SWIPE_MAX_TIME = 600;
let touchStartX = 0;
let touchStartY = 0;
let touchStartTime = 0;
let touchHorizontalLock = false;
const hasEmittedDemoComplete = ref(false);
let pageDwellStartedAt: number | null = null;
let pageDwellAccumulatedMs = 0;
let pageDwellContext: {
  bookId: string;
  chapterNo: number;
  contentVersion: string;
  pageNo: number;
} | null = null;
let interruptionTracked = false;

type InteractiveClickPayload = {
  canonical: string;
  lineIndex: number;
  interactiveIndexInLine: number;
};

const toLineArray = (value: unknown): string[] => {
  if (Array.isArray(value)) {
    return value
      .map((line) => (typeof line === 'string' ? line : ''))
      .filter((line) => line.length > 0);
  }
  if (typeof value === 'string') {
    return [value];
  }
  return [];
};

const normalizeChapterAudio = (audio: AudioConfig | null | undefined) => {
  if (!audio) return null;
  if (typeof audio.baseUrl !== 'string' || !audio.baseUrl.trim()) return null;

  const sourceManifest = audio.manifest;
  if (!sourceManifest || typeof sourceManifest !== 'object') return null;

  const manifestEntries = Object.entries(sourceManifest).filter(
    ([key, value]) => typeof key === 'string' && key.trim() && typeof value === 'string' && value.trim()
  );

  if (!manifestEntries.length) return null;

  const cacheKey =
    typeof audio.cacheKey === 'string' && audio.cacheKey.trim()
      ? audio.cacheKey.trim()
      : 'chapter-audio';

  return {
    cacheKey,
    baseUrl: normalizeBaseUrl(audio.baseUrl.trim()),
    manifest: Object.fromEntries(manifestEntries)
  };
};

const normalizeChapterData = (raw: unknown): NormalizedChapterData => {
  const chapterContainer = raw as Pick<RichChapterData, 'chapter' | 'chapters'>;
  const chapterPayload = Array.isArray(chapterContainer.chapter)
    ? chapterContainer.chapter[0]
    : chapterContainer.chapter ??
      (Array.isArray(chapterContainer.chapters) ? chapterContainer.chapters[0] : undefined);

  const asLegacy = raw as LegacyChapterData;
  if (Array.isArray(asLegacy.items)) {
    const items = asLegacy.items.map((item, index) => {
      const legacyItem = item as {
        id?: string;
        zhLines?: string[] | string;
        enLines?: string[] | string;
        enRichLines?: string[] | string;
      };
      return {
        id: legacyItem.id || `s${index + 1}`,
        pageNo: index + 1,
        zhLines: toLineArray(legacyItem.zhLines),
        enLines: toLineArray(legacyItem.enRichLines ?? legacyItem.enLines)
      };
    });
    const sourceWords = Array.isArray(asLegacy.interactiveWords) ? asLegacy.interactiveWords : [];
    const interactiveWords = new Set(sourceWords);
    if (interactiveWords.size === 0) {
      items.forEach((item) => {
        item.enLines.forEach((line) => {
          collectEnRichWords(line).forEach((word) => {
            interactiveWords.add(word);
          });
        });
      });
    }

    const totalPositions = countChapterInteractivePositions(items, interactiveWords);
    const normalizedBookId =
      typeof (raw as { bookId?: unknown }).bookId === 'string' ? String((raw as { bookId?: unknown }).bookId) : '';
    const normalizedChapterNo =
      chapterPayload && typeof chapterPayload.number === 'number' ? chapterPayload.number : null;

    return {
      bookId: normalizedBookId,
      chapterNo: normalizedChapterNo,
      items,
      interactiveWords: Array.from(interactiveWords),
      contentVersion: createChapterContentVersion(normalizedBookId, normalizedChapterNo ?? 0, items),
      totalInteractivePositions: totalPositions,
      audio: normalizeChapterAudio(chapterPayload?.audio)
    };
  }

  const asRich = raw as RichChapterData;

  const pages = Array.isArray(chapterPayload?.pages)
    ? chapterPayload.pages
    : Array.isArray(asRich.pages)
      ? asRich.pages
      : [];

  const items = pages.map((page, index) => {
    const zhLines = toLineArray(page.zh ?? page.mandarin);
    const enLines = toLineArray(page.enRich ?? page.enRichLines ?? page.en ?? page.enLines);
    const pageIndex = typeof page.page === 'number' ? page.page : index + 1;
    return {
      id: `p${pageIndex}`,
      pageNo: pageIndex,
      zhLines,
      enLines
    };
  });

  const interactiveWords = new Set<string>();
  items.forEach((item) => {
    item.enLines.forEach((line) => {
      collectEnRichWords(line).forEach((word) => {
        interactiveWords.add(word);
      });
    });
  });

  const normalizedBookId =
    typeof (raw as { bookId?: unknown }).bookId === 'string' ? String((raw as { bookId?: unknown }).bookId) : '';
  const normalizedChapterNo =
    chapterPayload && typeof chapterPayload.number === 'number' ? chapterPayload.number : null;
  const totalPositions = countChapterInteractivePositions(items, interactiveWords);

  return {
    bookId: normalizedBookId,
    chapterNo: normalizedChapterNo,
    items,
    interactiveWords: Array.from(interactiveWords),
    contentVersion: createChapterContentVersion(normalizedBookId, normalizedChapterNo ?? 0, items),
    totalInteractivePositions: totalPositions,
    audio: normalizeChapterAudio(chapterPayload?.audio)
  };
};

const getLineInteractiveCount = (line: string) => {
  if (!line) return 0;
  if (line.includes('{g|') || line.includes('{b|')) {
    return parseEnRichLine(line).filter((segment) => segment.kind === 'word').length;
  }
  return tokenize(line).reduce((count, token) => {
    const canonical = canonicalize(token);
    if (!canonical || !interactiveSet.value.has(canonical)) return count;
    return count + 1;
  }, 0);
};

const getLastInteractiveTarget = (item: ChapterItem | null) => {
  if (!item) return null;

  let lastTarget: { lineIndex: number; interactiveIndexInLine: number } | null = null;
  item.enLines.forEach((line, lineIndex) => {
    const count = getLineInteractiveCount(line);
    if (count <= 0) return;
    lastTarget = {
      lineIndex,
      interactiveIndexInLine: count - 1
    };
  });

  return lastTarget;
};

const currentLastInteractiveTarget = computed(() => getLastInteractiveTarget(currentItem.value));

const currentAnalyticsBookId = computed(() => analyticsBookId.value || props.bookId || '');
const currentAnalyticsChapterNo = computed(() => analyticsChapterNo.value ?? props.chapterNo ?? null);

const getPageDwellContext = () => {
  if (!props.active || !hasLoaded.value || !currentItem.value) return null;
  if (!currentAnalyticsBookId.value || !currentAnalyticsChapterNo.value || !contentVersion.value) return null;
  return {
    bookId: currentAnalyticsBookId.value,
    chapterNo: currentAnalyticsChapterNo.value,
    contentVersion: contentVersion.value,
    pageNo: currentItem.value.pageNo
  };
};

const canAccumulatePageDwell = () => {
  if (typeof document === 'undefined') return false;
  return document.visibilityState === 'visible' && getPageDwellContext() !== null;
};

const startPageDwell = () => {
  if (!canAccumulatePageDwell() || pageDwellStartedAt !== null) return;
  pageDwellContext = getPageDwellContext();
  pageDwellStartedAt = Date.now();
};

const stopPageDwell = () => {
  if (pageDwellStartedAt === null) return;
  const delta = Date.now() - pageDwellStartedAt;
  pageDwellStartedAt = null;
  if (delta > 0) {
    pageDwellAccumulatedMs += delta;
  }
};

const flushPageDwell = (bestEffort = false) => {
  stopPageDwell();
  if (!pageDwellContext || pageDwellAccumulatedMs <= 0) return;
  trackAnalyticsPageDwell({
    ...pageDwellContext,
    dwellMs: pageDwellAccumulatedMs,
    bestEffort
  });
  pageDwellContext = null;
  pageDwellAccumulatedMs = 0;
};

const restartPageDwell = () => {
  flushPageDwell();
  startPageDwell();
};

const loadChapter = async () => {
  loading.value = true;
  loadError.value = '';
  let loaded = false;
  let responseStatus: number | undefined;
  try {
    const chapterUrl = cacheBust
      ? `${props.contentUrl}${props.contentUrl.includes('?') ? '&' : '?'}v=${encodeURIComponent(buildStamp)}`
      : props.contentUrl;
    const response = await fetch(chapterUrl, { cache: 'no-store' });
    responseStatus = response.status;
    if (!response.ok) {
      throw new Error(`Request failed: ${response.status}`);
    }
    const rawData = await response.json();
    const data = normalizeChapterData(rawData);
    items.value = data.items;
    currentIndex.value = 0;
    hasEmittedDemoComplete.value = false;
    analyticsBookId.value = props.bookId || data.bookId;
    analyticsChapterNo.value = props.chapterNo ?? data.chapterNo;
    interactiveSet.value = new Set(data.interactiveWords);
    contentVersion.value = data.contentVersion;
    totalInteractivePositions.value = data.totalInteractivePositions;
    lastTrackedPageViewKey.value = '';

    if (analyticsBookId.value && analyticsChapterNo.value && data.totalInteractivePositions > 0) {
      registerAnalyticsContentChapter({
        bookId: analyticsBookId.value,
        chapterNo: analyticsChapterNo.value,
        contentVersion: data.contentVersion,
        totalInteractivePositions: data.totalInteractivePositions,
        contentUrl: props.contentUrl
      });
    }

    if (data.audio) {
      const baseUrl = normalizeBaseUrl(audioBaseOverride || data.audio.baseUrl);
      const manifest = data.audio.manifest;
      audioConfig.value = {
        cacheKey: data.audio.cacheKey,
        baseUrl,
        manifest
      };
      setAudioConfig({ baseUrl, manifest });
      setUseLocalAudio(true);
      precacheStatus.value = 'idle';
      precacheProgress.value = { done: 0, total: 0 };
      void requestPrecache();
    } else {
      audioConfig.value = {
        cacheKey: '',
        baseUrl: '',
        manifest: {}
      };
      setAudioConfig({ baseUrl: '', manifest: {} });
      setUseLocalAudio(false);
      precacheStatus.value = 'error';
      precacheProgress.value = { done: 0, total: 0 };
    }
    loaded = true;
  } catch (error) {
    loadError.value = 'Content load failed.';
    audioConfig.value = { cacheKey: '', baseUrl: '', manifest: {} };
    setAudioConfig({ baseUrl: '', manifest: {} });
    setUseLocalAudio(false);
    precacheStatus.value = 'error';
    trackAnalyticsContentLoadFailed({
      bookId: props.bookId || analyticsBookId.value,
      chapterNo: props.chapterNo ?? analyticsChapterNo.value,
      contentUrl: props.contentUrl,
      source: 'reading_page',
      message: error instanceof Error ? error.message : 'unknown_error',
      status: responseStatus
    });
  } finally {
    loading.value = false;
  }
  return loaded;
};

const buildAudioUrls = () => {
  if (!audioConfig.value.baseUrl) return [];
  const fileNames = Array.from(
    new Set(Object.values(audioConfig.value.manifest).filter((fileName) => typeof fileName === 'string' && fileName))
  );
  if (!fileNames.length) return [];

  const base = new URL(audioConfig.value.baseUrl, window.location.origin);
  return fileNames.map((fileName) => new URL(fileName, base).toString());
};

const requestPrecache = async () => {
  if (precacheStatus.value === 'downloading' || precacheStatus.value === 'done') {
    return;
  }
  if (!('serviceWorker' in navigator)) {
    precacheStatus.value = 'error';
    return;
  }

  const urls = buildAudioUrls();
  if (!urls.length) {
    precacheStatus.value = 'error';
    return;
  }

  precacheStatus.value = 'downloading';
  precacheProgress.value = { done: 0, total: urls.length };

  try {
    const registration = await navigator.serviceWorker.ready;
    if (!registration.active) {
      precacheStatus.value = 'error';
      return;
    }
    registration.active.postMessage({
      type: 'precache-audio',
      urls,
      cacheKey: audioConfig.value.cacheKey
    });
  } catch {
    precacheStatus.value = 'error';
  }
};

const handleServiceWorkerMessage = (event: MessageEvent) => {
  const data = event.data;
  if (!data || !data.type) return;
  if (data.type === 'precache-progress') {
    precacheProgress.value = { done: data.done ?? 0, total: data.total ?? 0 };
  }
  if (data.type === 'precache-complete') {
    precacheStatus.value = data.failed ? 'error' : 'done';
  }
  if (data.type === 'precache-error') {
    precacheStatus.value = 'error';
  }
};

const goNext = () => {
  const demoEndIndex =
    props.demoEndPage && props.demoEndPage > 0
      ? Math.min(items.value.length, props.demoEndPage) - 1
      : null;
  if (
    demoEndIndex !== null &&
    currentIndex.value >= demoEndIndex &&
    !hasEmittedDemoComplete.value
  ) {
    hasEmittedDemoComplete.value = true;
    emit('demo-complete');
    return;
  }
  if (currentIndex.value < items.value.length - 1) {
    currentIndex.value += 1;
    return;
  }
  emit('edge-next');
};

const goPrev = () => {
  if (currentIndex.value > 0) {
    currentIndex.value -= 1;
    return;
  }
  emit('edge-prev');
};

const onTouchStart = (event: TouchEvent) => {
  if (event.touches.length !== 1) return;
  const touch = event.touches[0];
  touchStartX = touch.clientX;
  touchStartY = touch.clientY;
  touchStartTime = Date.now();
  touchHorizontalLock = false;
};

const onTouchMove = (event: TouchEvent) => {
  if (!touchStartTime || event.touches.length !== 1) return;
  const touch = event.touches[0];
  const dx = touch.clientX - touchStartX;
  const dy = touch.clientY - touchStartY;

  if (!touchHorizontalLock && Math.abs(dx) > 8 && Math.abs(dx) > Math.abs(dy)) {
    touchHorizontalLock = true;
  }

  if (touchHorizontalLock) {
    event.preventDefault();
  }
};

const onTouchEnd = (event: TouchEvent) => {
  if (!touchStartTime || event.changedTouches.length === 0) return;
  const touch = event.changedTouches[0];
  const dx = touch.clientX - touchStartX;
  const dy = touch.clientY - touchStartY;
  const dt = Date.now() - touchStartTime;
  touchStartTime = 0;
  touchHorizontalLock = false;

  if (dt > SWIPE_MAX_TIME) return;
  if (Math.abs(dx) < SWIPE_THRESHOLD) return;
  if (Math.abs(dx) < Math.abs(dy)) return;

  if (dx < 0) {
    goNext();
  } else {
    goPrev();
  }
};

const onTouchCancel = () => {
  touchStartTime = 0;
  touchHorizontalLock = false;
};

const shouldMarkChapterComplete = (payload: InteractiveClickPayload) => {
  if (!currentAnalyticsBookId.value || !currentAnalyticsChapterNo.value) return false;
  if (chapterCompletionRecorded.value) return false;
  if (!isLastPage.value) return false;

  const lastTarget = currentLastInteractiveTarget.value;
  if (!lastTarget) return false;

  return (
    payload.lineIndex === lastTarget.lineIndex &&
    payload.interactiveIndexInLine === lastTarget.interactiveIndexInLine
  );
};

const onChapterCompleted = async () => {
  if (!currentAnalyticsBookId.value || !currentAnalyticsChapterNo.value || !contentVersion.value) return;
  const result = await recordAnalyticsChapterCompleteOnce(
    currentAnalyticsBookId.value,
    currentAnalyticsChapterNo.value,
    contentVersion.value
  );
  if (result === 'failed') return;

  chapterCompletionRecorded.value = true;
  if (result === 'recorded') {
    if (showBackToChaptersCta.value) {
      parentFeedbackVisible.value = false;
    } else {
      parentFeedbackVisible.value = true;
    }
  }
};

const onParentFeedbackSubmitted = () => {
  parentFeedbackVisible.value = false;
};

const onNextChapter = () => {
  if (!nextChapterEnabled.value) return;
  if (currentAnalyticsBookId.value && currentAnalyticsChapterNo.value) {
    trackAnalyticsContinuationAction({
      action: 'next_chapter_click',
      bookId: currentAnalyticsBookId.value,
      chapterNo: currentAnalyticsChapterNo.value
    });
  }
  emit('next-chapter');
};

const MORE_CHAPTERS_MODAL_SHOWN_PREFIX = 'cp_more_chapters_modal_shown';

const getMoreChaptersModalShownKey = () => {
  if (!currentAnalyticsBookId.value || !currentAnalyticsChapterNo.value) return '';
  const sessionId = getOrCreateAnalyticsSessionId();
  if (!sessionId) return '';
  return `${MORE_CHAPTERS_MODAL_SHOWN_PREFIX}_${sessionId}_${currentAnalyticsBookId.value}_${currentAnalyticsChapterNo.value}`;
};

const hasShownMoreChaptersModalInSession = () => {
  const key = getMoreChaptersModalShownKey();
  if (!key) return false;
  try {
    return localStorage.getItem(key) === '1';
  } catch {
    return false;
  }
};

const markMoreChaptersModalShownInSession = () => {
  const key = getMoreChaptersModalShownKey();
  if (!key) return;
  try {
    localStorage.setItem(key, '1');
  } catch {}
};

const onBackToChapters = () => {
  emit('exit-home');
};

const onMoreChaptersModalClose = () => {
  moreChaptersModalVisible.value = false;
};

const onMoreChaptersExitHome = () => {
  moreChaptersModalVisible.value = false;
  emit('exit-home');
};

const trackCurrentPageView = () => {
  if (!props.active || !hasLoaded.value || !currentItem.value) return;
  if (!currentAnalyticsBookId.value || !currentAnalyticsChapterNo.value || !contentVersion.value) return;

  // page_view 只需要记录“当前会话第一次进入这个页面”的事实，
  // 不需要在同一页停留期间反复上报。
  const pageKey = `${currentAnalyticsBookId.value}_${currentAnalyticsChapterNo.value}_${contentVersion.value}_${currentItem.value.pageNo}`;
  if (lastTrackedPageViewKey.value === pageKey) return;

  lastTrackedPageViewKey.value = pageKey;
  trackAnalyticsPageView({
    bookId: currentAnalyticsBookId.value,
    chapterNo: currentAnalyticsChapterNo.value,
    contentVersion: contentVersion.value,
    pageNo: currentItem.value.pageNo,
    // 当前页面的所有行都会同时展示，因此这里记录“当前页最后一行”
    // 作为这个 page_view 对应的最远内容位置。
    lineIndex: Math.max(currentItem.value.enLines.length - 1, 0)
  });
};

const onInteractiveWordClick = (payload: InteractiveClickPayload) => {
  if (currentItem.value && currentAnalyticsBookId.value && currentAnalyticsChapterNo.value && contentVersion.value) {
    const bookId = currentAnalyticsBookId.value;
    const chapterNo = currentAnalyticsChapterNo.value;
    const version = contentVersion.value;
    const pageNo = currentItem.value.pageNo;
    const positionKey = buildInteractivePositionKey(
      pageNo,
      payload.lineIndex,
      payload.interactiveIndexInLine
    );
    trackAnalyticsWordTap({
      bookId,
      chapterNo,
      contentVersion: version,
      pageNo,
      lineIndex: payload.lineIndex,
      interactiveIndexInLine: payload.interactiveIndexInLine,
      positionKey,
      word: payload.canonical,
      chapterTotalInteractivePositions: totalInteractivePositions.value
    });

    void playWord(payload.canonical, {
      onStarted: (audioMode) => {
        trackAnalyticsAudioPlayStarted({
          bookId,
          chapterNo,
          contentVersion: version,
          pageNo,
          positionKey,
          word: payload.canonical,
          audioMode
        });
      },
      onCompleted: (audioMode) => {
        trackAnalyticsAudioPlayCompleted({
          bookId,
          chapterNo,
          contentVersion: version,
          pageNo,
          positionKey,
          word: payload.canonical,
          audioMode
        });
      }
    });
  } else {
    void playWord(payload.canonical);
  }

  if (isLastPage.value && currentLastInteractiveTarget.value) {
    const isLastInteractiveClick =
      payload.lineIndex === currentLastInteractiveTarget.value.lineIndex &&
      payload.interactiveIndexInLine === currentLastInteractiveTarget.value.interactiveIndexInLine;
    if (isLastInteractiveClick) {
      lastPageNextChapterUnlocked.value = true;
      if (showBackToChaptersCta.value && !moreChaptersModalVisible.value) {
        if (!hasShownMoreChaptersModalInSession()) {
          markMoreChaptersModalShownInSession();
          moreChaptersModalVisible.value = true;
        }
      }
    }
  }

  if (shouldMarkChapterComplete(payload)) {
    void onChapterCompleted();
  }
};

const trackInterruptionIfNeeded = (reason: string) => {
  if (interruptionTracked || chapterCompletionRecorded.value) return;
  const context = getPageDwellContext() ?? pageDwellContext;
  if (!context) return;
  interruptionTracked = true;
  trackAnalyticsSessionInterrupted({
    ...context,
    reason
  });
};

const onPageVisibilityChange = () => {
  if (typeof document === 'undefined') return;
  if (document.visibilityState === 'visible') {
    startPageDwell();
    return;
  }
  stopPageDwell();
};

const onReadingPageHide = () => {
  flushPageDwell(true);
  trackInterruptionIfNeeded('pagehide');
};

onMounted(() => {
  initAnalyticsAppSession();
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', handleServiceWorkerMessage);
  }
  document.addEventListener('visibilitychange', onPageVisibilityChange);
  window.addEventListener('pagehide', onReadingPageHide);
  window.addEventListener('beforeunload', onReadingPageHide);
});

onBeforeUnmount(() => {
  flushPageDwell();
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.removeEventListener('message', handleServiceWorkerMessage);
  }
  document.removeEventListener('visibilitychange', onPageVisibilityChange);
  window.removeEventListener('pagehide', onReadingPageHide);
  window.removeEventListener('beforeunload', onReadingPageHide);
});

watch(currentIndex, (index, prevIndex) => {
  if (index !== prevIndex) {
    restartPageDwell();
    lastPageNextChapterUnlocked.value = false;
  }
  trackCurrentPageView();
  startPageDwell();
});

watch(
  () => props.active,
  async (active) => {
    if (!active) {
      flushPageDwell();
      return;
    }
    if (active && !hasLoaded.value) {
      const loaded = await loadChapter();
      if (loaded) {
        hasLoaded.value = true;
        trackCurrentPageView();
        startPageDwell();
      }
      return;
    }
    startPageDwell();
  },
  { immediate: true }
);

watch(
  () => props.contentUrl,
  async () => {
    flushPageDwell();
    hasLoaded.value = false;
    items.value = [];
    currentIndex.value = 0;
    lastPageNextChapterUnlocked.value = false;
    chapterCompletionRecorded.value = false;
    interruptionTracked = false;
    parentFeedbackVisible.value = false;
    moreChaptersModalVisible.value = false;
    analyticsBookId.value = '';
    analyticsChapterNo.value = null;
    contentVersion.value = '';
    totalInteractivePositions.value = 0;
    lastTrackedPageViewKey.value = '';
    if (!props.active) return;
    const loaded = await loadChapter();
    if (loaded) {
      hasLoaded.value = true;
      trackCurrentPageView();
      startPageDwell();
    }
  }
);
</script>

<template>
  <section class="screen reading-screen" aria-label="Chapter reading">
    <div
      class="precache-progress"
      :class="{ 'precache-progress--active': precacheStatus === 'downloading' }"
      aria-hidden="true"
    >
      <span class="precache-progress__fill" :style="{ width: `${precachePercent}%` }"></span>
    </div>
    <div
      class="reading"
      @touchstart.stop="onTouchStart"
      @touchmove.stop="onTouchMove"
      @touchend.stop="onTouchEnd"
      @touchcancel.stop="onTouchCancel"
    >
      <div class="reading__inner">
        <p v-if="loading" class="status">Loading...</p>
        <p v-else-if="loadError" class="status">{{ loadError }}</p>
        <p v-else-if="!currentItem" class="status">No content.</p>
        <BilingualLine
          v-else
          :zh="currentItem.zhLines"
          :en="currentItem.enLines"
          :interactiveSet="interactiveSet"
          @interactive-click="onInteractiveWordClick"
        />
        <button
          v-if="showNextChapterCta"
          type="button"
          class="next-chapter-btn"
          :disabled="!nextChapterEnabled"
          @click="onNextChapter"
        >
          {{ nextChapterLabel }}
        </button>
        <button
          v-if="showBackToChaptersButton"
          type="button"
          class="next-chapter-btn"
          @click="onBackToChapters"
        >
          返回章节列表
        </button>
      </div>
    </div>
    <ProgressBar :progress="progress" />

    <ParentFeedbackModal
      :visible="parentFeedbackVisible"
      :book-id="currentAnalyticsBookId"
      :chapter-no="currentAnalyticsChapterNo"
      @submitted="onParentFeedbackSubmitted"
    />

    <MoreChaptersModal
      :visible="moreChaptersModalVisible"
      :book-id="currentAnalyticsBookId"
      :chapter-no="currentAnalyticsChapterNo"
      @close="onMoreChaptersModalClose"
      @exit-home="onMoreChaptersExitHome"
    />
  </section>
</template>

<style scoped lang="less">
.reading-screen {
  position: relative;
  background: var(--bg);
  color: var(--accent);
  padding-bottom: calc(64px + env(safe-area-inset-bottom));
  overflow: hidden;
  overscroll-behavior: none;
}

.reading {
  flex: 1;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  z-index: 1;
  touch-action: pan-y;
}

.reading__inner {
  width: min(90vw, 360px);
}

.status {
  margin: 0;
  color: var(--accent);
  font-weight: 500;
}

.next-chapter-btn {
  margin-top: 18px;
  border: none;
  border-radius: 999px;
  background: var(--accent-strong);
  color: #f8f6e6;
  padding: 10px 18px;
  font-size: 15px;
  font-weight: 700;
  font-family: "FZCuYuan", "Gotham Rounded";
  box-shadow: 0 8px 20px rgba(var(--accent-rgb), 0.24);
}

.next-chapter-btn:active {
  transform: translateY(1px);
}

.next-chapter-btn:disabled {
  opacity: 0.45;
  box-shadow: none;
  transform: none;
}

.precache-progress {
  position: absolute;
  top: env(safe-area-inset-top);
  left: 0;
  right: 0;
  height: 4px;
  background: transparent;
  pointer-events: none;
  z-index: 1000;
  opacity: 0;
  transition: opacity 120ms ease;
}

.precache-progress--active {
  opacity: 1;
}

.precache-progress__fill {
  display: block;
  height: 100%;
  width: 0;
  background: linear-gradient(90deg, var(--accent-strong), #7bd869);
  transition: width 160ms ease;
}

</style>
