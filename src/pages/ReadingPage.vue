<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import BilingualLine from '@/components/BilingualLine.vue';
import ProgressBar from '@/components/ProgressBar.vue';
import { setAudioConfig, setUseLocalAudio } from '@/audio/player';
import { createReadingTracker } from '@/tracking/readingTracker';

const props = withDefaults(
  defineProps<{
    active?: boolean;
  }>(),
  {
    active: true
  }
);

const emit = defineEmits<{
  (e: 'edge-prev'): void;
  (e: 'edge-next'): void;
}>();

type ChapterItem = {
  id: string;
  zhLines: string[];
  enLines: string[];
};

type ChapterData = {
  items: ChapterItem[];
  interactiveWords: string[];
  audio?: {
    baseUrl?: string;
    manifest?: Record<string, string>;
  };
};

const items = ref<ChapterItem[]>([]);
const currentIndex = ref(0);
const currentItem = computed(() => items.value[currentIndex.value] ?? null);
const progress = computed(() => {
  if (!items.value.length) return 0;
  return (currentIndex.value + 1) / items.value.length;
});

const interactiveSet = shallowRef<Set<string>>(new Set());
const loading = ref(true);
const loadError = ref('');

const audioBaseOverride = import.meta.env.VITE_AUDIO_BASE_URL;
const buildStamp = typeof __BUILD_TIME__ === 'string' ? __BUILD_TIME__ : '';
const cacheBust = buildStamp ? `?v=${encodeURIComponent(buildStamp)}` : '';
const audioConfig = ref<{ baseUrl: string; manifest: Record<string, string> }>({
  baseUrl: '/audio/',
  manifest: {}
});

const hasLoaded = ref(false);
const tracker = shallowRef<ReturnType<typeof createReadingTracker> | null>(null);
const reachedSentence6 = ref(false);
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

const loadChapter = async () => {
  loading.value = true;
  loadError.value = '';
  let loaded = false;
  try {
    const response = await fetch(`/mock/chapter1.json${cacheBust}`, { cache: 'no-store' });
    if (!response.ok) {
      throw new Error(`Request failed: ${response.status}`);
    }
    const data = (await response.json()) as ChapterData;
    items.value = Array.isArray(data.items) ? data.items : [];
    currentIndex.value = 0;
    if (Array.isArray(data.interactiveWords)) {
      interactiveSet.value = new Set(data.interactiveWords);
    }
    const baseUrl = normalizeBaseUrl(audioBaseOverride || data.audio?.baseUrl || '/audio/');
    const manifest = data.audio?.manifest ?? {};
    audioConfig.value = { baseUrl, manifest };
    setAudioConfig({ baseUrl, manifest });
    precacheStatus.value = 'idle';
    precacheProgress.value = { done: 0, total: 0 };
    setUseLocalAudio(true);
    void requestPrecache();
    loaded = true;
  } catch (error) {
    loadError.value = 'Content load failed.';
    const baseUrl = normalizeBaseUrl(audioBaseOverride || '/audio/');
    audioConfig.value = { baseUrl, manifest: {} };
    setAudioConfig({ baseUrl, manifest: {} });
    precacheStatus.value = 'error';
  } finally {
    loading.value = false;
  }
  return loaded;
};

const buildAudioUrls = () => {
  const manifestValues = Object.values(audioConfig.value.manifest);
  const fileNames =
    manifestValues.length > 0
      ? manifestValues
      : Array.from(interactiveSet.value).map((word) => `${word}.mp3`);

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
    registration.active.postMessage({ type: 'precache-audio', urls });
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
};

const onTouchEnd = (event: TouchEvent) => {
  if (!touchStartTime || event.changedTouches.length === 0) return;
  const touch = event.changedTouches[0];
  const dx = touch.clientX - touchStartX;
  const dy = touch.clientY - touchStartY;
  const dt = Date.now() - touchStartTime;
  touchStartTime = 0;

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
};

const ensureTracker = () => {
  if (tracker.value) return tracker.value;
  tracker.value = createReadingTracker('/api/tracking.php');
  return tracker.value;
};

const onInteractiveWordClick = () => {
  tracker.value?.onWordClick();
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
  tracker.value?.flushBestEffort();
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
  </section>
</template>

<style scoped lang="less">
.reading-screen {
  position: relative;
  background: var(--bg);
  color: var(--accent);
  padding-bottom: calc(64px + env(safe-area-inset-bottom));
}

.reading {
  flex: 1;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  z-index: 1;
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
</style>
