<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import BilingualLine from '@/components/BilingualLine.vue';
import ProgressBar from '@/components/ProgressBar.vue';
import { setAudioConfig, setUseLocalAudio } from '@/audio/player';
import { createReadingTracker } from '@/tracking/readingTracker';
import {
  markChapterCompletedOnce,
  submitParentFeedback,
  type ParentFeedbackChoice
} from '@/tracking/behaviorTracker';
import { collectEnRichWords, parseEnRichLine } from '@/utils/enRich';
import { canonicalize, tokenize } from '@/utils/tokenize';

const props = withDefaults(
  defineProps<{
    active?: boolean;
    contentUrl?: string;
    demoEndPage?: number | null;
    bookId?: string;
    chapterNo?: number | null;
  }>(),
  {
    active: true,
    contentUrl: '',
    demoEndPage: null,
    bookId: '',
    chapterNo: null
  }
);

const emit = defineEmits<{
  (e: 'edge-prev'): void;
  (e: 'edge-next'): void;
  (e: 'demo-complete'): void;
}>();

type ChapterItem = {
  id: string;
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
  items: ChapterItem[];
  interactiveWords: string[];
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
const canSubmitParentFeedback = computed(() => parentFeedbackChoice.value !== '');

const interactiveSet = shallowRef<Set<string>>(new Set());
const loading = ref(true);
const loadError = ref('');

const audioBaseOverride = import.meta.env.VITE_AUDIO_BASE_URL;
const buildStamp = typeof __BUILD_TIME__ === 'string' ? __BUILD_TIME__ : '';
const cacheBust = buildStamp ? `?v=${encodeURIComponent(buildStamp)}` : '';
const audioConfig = ref<{ cacheKey: string; baseUrl: string; manifest: Record<string, string> }>({
  cacheKey: '',
  baseUrl: '',
  manifest: {}
});

const hasLoaded = ref(false);
const tracker = shallowRef<ReturnType<typeof createReadingTracker> | null>(null);
const reachedSentence6 = ref(false);
const chapterCompletionRecorded = ref(false);
const parentFeedbackVisible = ref(false);
const parentFeedbackChoice = ref<ParentFeedbackChoice | ''>('');
const parentFeedbackComment = ref('');
const parentFeedbackSubmitting = ref(false);
const parentFeedbackError = ref('');
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
    return {
      items,
      interactiveWords: Array.from(interactiveWords),
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

  return {
    items,
    interactiveWords: Array.from(interactiveWords),
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

const loadChapter = async () => {
  loading.value = true;
  loadError.value = '';
  let loaded = false;
  try {
    const chapterUrl = cacheBust
      ? `${props.contentUrl}${props.contentUrl.includes('?') ? '&' : '?'}v=${encodeURIComponent(buildStamp)}`
      : props.contentUrl;
    const response = await fetch(chapterUrl, { cache: 'no-store' });
    if (!response.ok) {
      throw new Error(`Request failed: ${response.status}`);
    }
    const rawData = await response.json();
    const data = normalizeChapterData(rawData);
    items.value = data.items;
    currentIndex.value = 0;
    hasEmittedDemoComplete.value = false;
    interactiveSet.value = new Set(data.interactiveWords);
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

const ensureTracker = () => {
  if (tracker.value) return tracker.value;
  tracker.value = createReadingTracker('/api/tracking.php');
  return tracker.value;
};

const shouldMarkChapterComplete = (payload: InteractiveClickPayload) => {
  if (!props.bookId || !props.chapterNo) return false;
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
  if (!props.bookId || !props.chapterNo) return;
  const result = await markChapterCompletedOnce(props.bookId, props.chapterNo);
  if (result === 'failed') return;

  chapterCompletionRecorded.value = true;
  if (result === 'recorded') {
    parentFeedbackChoice.value = '';
    parentFeedbackComment.value = '';
    parentFeedbackError.value = '';
    parentFeedbackVisible.value = true;
  }
};

const onParentFeedbackSubmit = async () => {
  if (!props.bookId || !props.chapterNo) return;
  if (!canSubmitParentFeedback.value || parentFeedbackSubmitting.value) return;
  const choice = parentFeedbackChoice.value;
  if (choice !== 'yes' && choice !== 'no') return;

  parentFeedbackSubmitting.value = true;
  parentFeedbackError.value = '';
  const ok = await submitParentFeedback(
    props.bookId,
    props.chapterNo,
    choice,
    parentFeedbackComment.value
  );
  parentFeedbackSubmitting.value = false;

  if (!ok) {
    parentFeedbackError.value = '提交失败，请稍后重试。';
    return;
  }

  parentFeedbackVisible.value = false;
  parentFeedbackComment.value = '';
};

const onInteractiveWordClick = (payload: InteractiveClickPayload) => {
  tracker.value?.onWordClick();
  if (shouldMarkChapterComplete(payload)) {
    void onChapterCompleted();
  }
};

const onVisibilityChange = () => {
  tracker.value?.onVisibilityChange();
};

const onPageHide = () => {
  tracker.value?.flushBestEffort();
};

onMounted(() => {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', handleServiceWorkerMessage);
  }
  document.addEventListener('visibilitychange', onVisibilityChange);
  window.addEventListener('pagehide', onPageHide);
  window.addEventListener('beforeunload', onPageHide);
});

onBeforeUnmount(() => {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.removeEventListener('message', handleServiceWorkerMessage);
  }
  document.removeEventListener('visibilitychange', onVisibilityChange);
  window.removeEventListener('pagehide', onPageHide);
  window.removeEventListener('beforeunload', onPageHide);
  tracker.value?.dispose();
  tracker.value = null;
});

watch(currentIndex, (index) => {
  if (index < 5 || reachedSentence6.value) return;
  reachedSentence6.value = true;
  tracker.value?.onReachedSentence6();
});

watch(
  () => props.active,
  async (active) => {
    if (active && !hasLoaded.value) {
      const loaded = await loadChapter();
      if (loaded) {
        hasLoaded.value = true;
      }
    }
    if (!hasLoaded.value) return;

    const readingTracker = ensureTracker();
    readingTracker.onPageActiveChange(active);
    if (active && reachedSentence6.value) {
      readingTracker.onReachedSentence6();
    }
  },
  { immediate: true }
);

watch(
  () => props.contentUrl,
  async () => {
    hasLoaded.value = false;
    items.value = [];
    currentIndex.value = 0;
    chapterCompletionRecorded.value = false;
    parentFeedbackVisible.value = false;
    parentFeedbackChoice.value = '';
    parentFeedbackComment.value = '';
    parentFeedbackError.value = '';
    parentFeedbackSubmitting.value = false;
    if (!props.active) return;
    const loaded = await loadChapter();
    if (loaded) {
      hasLoaded.value = true;
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
      </div>
    </div>
    <ProgressBar :progress="progress" />

    <div v-if="parentFeedbackVisible" class="parent-feedback-modal">
      <div class="parent-feedback-modal__mask" aria-hidden="true"></div>
      <div class="parent-feedback-modal__dialog" role="dialog" aria-modal="true">
        <p class="parent-feedback-modal__title">家长反馈</p>
        <p class="parent-feedback-modal__question">孩子喜欢这个章节吗？是否想继续听下一个故事？</p>

        <div class="parent-feedback-modal__choices">
          <button
            type="button"
            class="parent-feedback-modal__choice"
            :class="{ 'parent-feedback-modal__choice--active': parentFeedbackChoice === 'yes' }"
            @click="parentFeedbackChoice = 'yes'"
          >
            Yes
          </button>
          <button
            type="button"
            class="parent-feedback-modal__choice"
            :class="{ 'parent-feedback-modal__choice--active': parentFeedbackChoice === 'no' }"
            @click="parentFeedbackChoice = 'no'"
          >
            No
          </button>
        </div>

        <textarea
          v-model="parentFeedbackComment"
          class="parent-feedback-modal__textarea"
          rows="3"
          placeholder="可填写简短评论（选填）"
        ></textarea>

        <p v-if="parentFeedbackError" class="parent-feedback-modal__error">{{ parentFeedbackError }}</p>
        <button
          type="button"
          class="parent-feedback-modal__submit"
          :disabled="!canSubmitParentFeedback || parentFeedbackSubmitting"
          @click="onParentFeedbackSubmit"
        >
          {{ parentFeedbackSubmitting ? '提交中...' : '提交反馈' }}
        </button>
      </div>
    </div>
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

.parent-feedback-modal {
  position: absolute;
  inset: 0;
  z-index: 15;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.parent-feedback-modal__mask {
  position: absolute;
  inset: 0;
  background: rgba(47, 78, 11, 0.32);
}

.parent-feedback-modal__dialog {
  position: relative;
  z-index: 1;
  width: min(90vw, 340px);
  border-radius: 18px;
  background: #f8f6e6;
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.16);
  padding: 18px 16px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.parent-feedback-modal__title {
  margin: 0;
  color: var(--accent-strong);
  font-size: 20px;
  font-weight: 700;
}

.parent-feedback-modal__question {
  margin: 0;
  color: #32570f;
  font-size: 14px;
  line-height: 1.4;
}

.parent-feedback-modal__choices {
  display: flex;
  gap: 10px;
}

.parent-feedback-modal__choice {
  flex: 1;
  border-radius: 999px;
  border: 2px solid rgba(var(--accent-rgb), 0.45);
  background: #fff;
  color: #32570f;
  padding: 8px 10px;
  font-size: 14px;
  font-weight: 600;
}

.parent-feedback-modal__choice--active {
  border-color: var(--accent-strong);
  background: rgba(var(--accent-rgb), 0.12);
}

.parent-feedback-modal__textarea {
  width: 100%;
  border-radius: 12px;
  border: 1px solid rgba(var(--accent-rgb), 0.25);
  padding: 10px;
  font-size: 14px;
  line-height: 1.5;
  resize: none;
}

.parent-feedback-modal__textarea:focus {
  outline: none;
  border-color: rgba(var(--accent-rgb), 0.25);
}

.parent-feedback-modal__error {
  margin: 0;
  color: #bf3d1f;
  font-size: 12px;
}

.parent-feedback-modal__submit {
  border: none;
  border-radius: 999px;
  background: var(--accent-strong);
  color: #f8f6e6;
  padding: 10px;
  font-size: 15px;
  font-weight: 700;
}

.parent-feedback-modal__submit:disabled {
  opacity: 0.5;
}
</style>
