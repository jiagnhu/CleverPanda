<script setup lang="ts">
import { ref, watch } from 'vue';
import { playWord } from '@/audio/player';
import BilingualLine from '@/components/BilingualLine.vue';
import { trackOnboardingPageResult } from '@/tracking/behaviorTracker';

const props = withDefaults(
  defineProps<{
    zh: string;
    enRich: string;
    targetWord: string;
    pageIndex: number;
    active?: boolean;
  }>(),
  { active: false }
);

const pageShownAt = ref<number | null>(null);
const firstTapAt = ref<number | null>(null);
const tapCount = ref(0);
const wordsTapped = ref<string[]>([]);

// enRich 格式（{g|word}）由 WordTapText 自动识别，interactiveSet 传空集合即可
const emptySet = new Set<string>();

const sendTracking = () => {
  const shownAt = pageShownAt.value ?? Date.now();
  const firstMs = firstTapAt.value !== null ? firstTapAt.value - shownAt : null;
  trackOnboardingPageResult({
    pageIndex: props.pageIndex,
    targetWord: props.targetWord,
    wordsTapped: wordsTapped.value,
    totalTaps: tapCount.value,
    firstTapMs: firstMs,
    hesitationMs: firstMs
  });
};

watch(
  () => props.active,
  (active, wasActive) => {
    if (active) {
      pageShownAt.value = Date.now();
      firstTapAt.value = null;
      tapCount.value = 0;
      wordsTapped.value = [];
    } else if (wasActive) {
      sendTracking();
    }
  },
  { immediate: true }
);

const onInteractiveClick = (payload: { canonical: string; lineIndex: number; interactiveIndexInLine: number }) => {
  if (firstTapAt.value === null) {
    firstTapAt.value = Date.now();
  }
  tapCount.value += 1;
  if (!wordsTapped.value.includes(payload.canonical)) {
    wordsTapped.value = [...wordsTapped.value, payload.canonical];
  }
  void playWord(payload.canonical);
};
</script>

<template>
  <section class="ob-page screen">
    <header class="ob-page__header">
      <p class="ob-page__app-name">机灵小熊猫</p>
      <p class="ob-page__tagline">一起读一读</p>
    </header>

    <div class="ob-page__content">
      <BilingualLine
        :zh="[zh]"
        :en="[enRich]"
        :interactive-set="emptySet"
        @interactive-click="onInteractiveClick"
      />
    </div>
  </section>
</template>

<style scoped lang="less">
.ob-page__content :deep(.word-plain) {
  color: #A8CE20;
}

.ob-page__content :deep(.bilingual__zh) {
  color: #A8CE20;
}

.ob-page {
  background: #f0f9a0;
  color: var(--accent-strong);
  font-family: 'FZCuYuan', 'Gotham Rounded';
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-start;
}

.ob-page__header {
  margin-top: clamp(48px, 10vh, 88px);
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 2px;
  color: #A8CE20;
}

.ob-page__app-name {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  letter-spacing: 0.06em;
}

.ob-page__tagline {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  letter-spacing: 0.06em;
}

.ob-page__content {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 28px;
  text-align: center;
}

/* 脉冲动画：仅作用于可点击词，点击时的 flash 类会暂时取消 */
.ob-page__content :deep(.word--green:not(.flash)) {
  animation: ob-pulse 1.4s ease-in-out infinite;
}

@keyframes ob-pulse {
  0%,
  100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.13);
  }
}
</style>
