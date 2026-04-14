<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import WelcomePage from '@/pages/WelcomePage.vue';
import StartPage from '@/pages/StartPage.vue';
import InstructionPage from '@/pages/InstructionPage.vue';
import ReadingPage from '@/pages/ReadingPage.vue';
import { getDemoConfig, type DemoConfig } from '@/data/books';

const slideCount = 4;
const activeIndex = ref(0);
const router = useRouter();
const demoConfig = ref<DemoConfig | null>(null);
const demoContentUrl = computed(() => demoConfig.value?.contentUrl || '');
const demoEndPage = computed(() => demoConfig.value?.endPage ?? null);
const demoBookId = computed(() => demoConfig.value?.targetBookId || '');
const demoChapterNo = computed(() => demoConfig.value?.targetChapterNo ?? 1);

const clampIndex = (index: number) => Math.max(0, Math.min(index, slideCount - 1));
const goTo = (index: number) => {
  activeIndex.value = clampIndex(index);
};
const goNext = () => goTo(activeIndex.value + 1);
const goPrev = () => goTo(activeIndex.value - 1);

const SWIPE_THRESHOLD = 48;
const SWIPE_MAX_TIME = 600;
let touchStartX = 0;
let touchStartY = 0;
let touchStartTime = 0;

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

const onDemoComplete = () => {
  const bookId = demoConfig.value?.targetBookId;
  if (!bookId) {
    void router.replace({ path: '/' });
    return;
  }
  void router.push({ name: 'book-success', params: { bookId } });
};

onMounted(async () => {
  demoConfig.value = await getDemoConfig();
});
</script>

<template>
  <main class="slide-module" @touchstart="onTouchStart" @touchend="onTouchEnd" @touchcancel="onTouchCancel">
    <div
      class="slide-module__track"
      :style="{ transform: `translateX(-${activeIndex * 100}%)` }"
    >
      <div class="slide-module__slide">
        <WelcomePage />
      </div>
      <div class="slide-module__slide">
        <StartPage @next="goNext" />
      </div>
      <div class="slide-module__slide">
        <InstructionPage @next="goNext" />
      </div>
      <div class="slide-module__slide">
        <ReadingPage
          v-if="demoContentUrl"
          :active="activeIndex === 3"
          :content-url="demoContentUrl"
          :demo-end-page="demoEndPage"
          :book-id="demoBookId"
          :chapter-no="demoChapterNo"
          @edge-prev="goPrev"
          @edge-next="goNext"
          @demo-complete="onDemoComplete"
        />
        <section v-else class="slide-module__loading">
          <p class="slide-module__loading-text">Loading...</p>
        </section>
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

.slide-module__loading {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--accent-strong);
}

.slide-module__loading-text {
  margin: 0;
}
</style>
