<script setup lang="ts">
import { ref } from 'vue';
import WelcomePage from '@/pages/WelcomePage.vue';
import StartPage from '@/pages/StartPage.vue';
import InstructionPage from '@/pages/InstructionPage.vue';
import ReadingPage from '@/pages/ReadingPage.vue';

const slideCount = 4;
const activeIndex = ref(0);

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
</script>

<template>
  <main class="slide-module" @touchstart="onTouchStart" @touchend="onTouchEnd" @touchcancel="onTouchCancel">
    <div
      class="slide-module__track"
      :style="{ transform: `translateX(-${activeIndex * 100}%)` }"
    >
      <div class="slide-module__slide">
        <WelcomePage @next="goNext" />
      </div>
      <div class="slide-module__slide">
        <StartPage @next="goNext" />
      </div>
      <div class="slide-module__slide">
        <InstructionPage @next="goNext" />
      </div>
      <div class="slide-module__slide">
        <ReadingPage :active="activeIndex === 3" @edge-prev="goPrev" @edge-next="goNext" />
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
