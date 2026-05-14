<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import WelcomePage from '@/pages/WelcomePage.vue';
import OnboardingWordTapPage from '@/pages/OnboardingWordTapPage.vue';
import AliceTitlePage from '@/pages/AliceTitlePage.vue';
import { getDemoConfig, getOnboardingDemoConfig } from '@/data/books';
import { trackAnalyticsContinuationAction } from '@/analytics/manager';

const SWIPE_GUIDE_COMPLETED_KEY = 'cp_swipe_guide_completed_v1';

const activeIndex = ref(0);
const router = useRouter();
const showSwipeGuide = ref(false);
const storyBookId = ref('alice-001');
const storyChapterNo = ref(1);

type OnboardingPage = {
  zh: string;
  enRich: string;
  targetWord: string;
};

const onboardingPages = ref<OnboardingPage[]>([]);
const slideCount = ref(2); // Welcome + AliceTitle; grows after onboarding loads

const loadOnboarding = async () => {
  const [config, demoConfig] = await Promise.all([getOnboardingDemoConfig(), getDemoConfig()]);
  if (demoConfig?.targetBookId) {
    storyBookId.value = demoConfig.targetBookId;
    storyChapterNo.value = demoConfig.targetChapterNo ?? 1;
  }
  if (!config?.contentUrl) return;
  try {
    const res = await fetch(config.contentUrl, { cache: 'no-store' });
    if (!res.ok) return;
    const data = await res.json() as {
      chapter?: Array<{
        pages?: Array<{ zh?: string; enRich?: string; targetWord?: string }>;
      }>;
    };
    const chapter = Array.isArray(data.chapter) ? data.chapter[0] : data.chapter;
    const pages = chapter?.pages ?? [];
    onboardingPages.value = pages
      .filter((p) => p.zh && p.enRich)
      .map((p) => ({ zh: p.zh!, enRich: p.enRich!, targetWord: p.targetWord ?? '' }));
    slideCount.value = 1 + onboardingPages.value.length + 1; // Welcome + pages + AliceTitle
  } catch {}
};

const clampIndex = (index: number) => Math.max(0, Math.min(index, slideCount.value - 1));

const hasCompletedSwipeGuide = () => {
  try {
    return localStorage.getItem(SWIPE_GUIDE_COMPLETED_KEY) === '1';
  } catch {
    return false;
  }
};

const markSwipeGuideCompleted = () => {
  try {
    localStorage.setItem(SWIPE_GUIDE_COMPLETED_KEY, '1');
  } catch {}
};

const hideSwipeGuide = () => {
  showSwipeGuide.value = false;
};

const goTo = (index: number) => {
  const nextIndex = clampIndex(index);
  if (activeIndex.value === 0 && nextIndex > 0) {
    markSwipeGuideCompleted();
  }
  if (nextIndex !== activeIndex.value) {
    hideSwipeGuide();
  }
  activeIndex.value = nextIndex;
};

const goNext = () => goTo(activeIndex.value + 1);
const goPrev = () => goTo(activeIndex.value - 1);

const onStartReading = () => {
  trackAnalyticsContinuationAction({
    action: 'start_reading_click',
    targetBookId: storyBookId.value,
    targetChapterNo: storyChapterNo.value,
    flow: 'mvp_onboarding'
  });
  markSwipeGuideCompleted();
  hideSwipeGuide();
  goTo(1);
};

const onAliceStart = () => {
  trackAnalyticsContinuationAction({
    action: 'onboarding_to_story_click',
    targetBookId: storyBookId.value,
    targetChapterNo: storyChapterNo.value,
    flow: 'mvp_onboarding'
  });
  void router.push({
    name: 'book-title',
    params: {
      bookId: storyBookId.value
    }
  });
};

const SWIPE_THRESHOLD = 48;
const SWIPE_MAX_TIME = 600;
let touchStartX = 0;
let touchStartY = 0;
let touchStartTime = 0;

const showSwipeGuideIfNeeded = () => {
  if (hasCompletedSwipeGuide()) return;
  showSwipeGuide.value = true;
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

onMounted(async () => {
  await loadOnboarding();
  showSwipeGuideIfNeeded();
});

onBeforeUnmount(() => {
  hideSwipeGuide();
});
</script>

<template>
  <main class="slide-module" @touchstart="onTouchStart" @touchend="onTouchEnd" @touchcancel="onTouchCancel">
    <div
      class="slide-module__track"
      :style="{ transform: `translateX(-${activeIndex * 100}%)` }"
    >
      <!-- Slide 0: Welcome -->
      <div class="slide-module__slide">
        <WelcomePage
          :show-swipe-guide="showSwipeGuide && activeIndex === 0"
          @start-reading="onStartReading"
        />
      </div>

      <!-- Slides 1-N: Onboarding word tap pages (loaded from JSON) -->
      <div v-for="(page, i) in onboardingPages" :key="i" class="slide-module__slide">
        <OnboardingWordTapPage
          :zh="page.zh"
          :en-rich="page.enRich"
          :target-word="page.targetWord"
          :page-index="i + 1"
          :active="activeIndex === i + 1"
        />
      </div>

      <!-- Final onboarding slide: Alice title transition -->
      <div class="slide-module__slide">
        <AliceTitlePage @start="onAliceStart" />
      </div>
    </div>
  </main>
</template>

<style scoped lang="less">
.slide-module {
  position: relative;
  height: 100dvh;
  width: 100%;
  overflow: hidden;
  background: var(--bg);
  touch-action: pan-y;
}

.slide-module__track {
  display: flex;
  height: 100%;
  width: 100%;
  flex-wrap: nowrap;
  transition: transform 320ms ease;
  will-change: transform;
}

.slide-module__slide {
  flex: 0 0 100%;
  height: 100%;
}
</style>
