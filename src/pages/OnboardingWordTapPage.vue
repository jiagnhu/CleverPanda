<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
    /** 有值时用书名标题区，替代默认「机灵小熊猫 / 一起读一读」 */
    titleEn?: string;
    titleZh?: string;
    /** 有值时在底部显示固定按钮 */
    ctaLabel?: string;
    /** 与 ctaLabel 配套：next 进入下一页，start 进入故事 */
    ctaAction?: 'next' | 'start';
  }>(),
  { active: false, ctaAction: 'start' }
);

const emit = defineEmits<{ (e: 'start'): void; (e: 'next'): void }>();

const useBookHeader = computed(
  () => typeof props.titleEn === 'string' && props.titleEn.trim() && typeof props.titleZh === 'string' && props.titleZh.trim()
);

const showCta = computed(() => typeof props.ctaLabel === 'string' && props.ctaLabel.trim().length > 0);

const pageShownAt = ref<number | null>(null);
const firstTapAt = ref<number | null>(null);
const tapCount = ref(0);
const wordsTapped = ref<string[]>([]);

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

const onCta = () => {
  if (props.ctaAction === 'next') {
    emit('next');
  } else {
    emit('start');
  }
};
</script>

<template>
  <section class="ob-page screen" :class="{ 'ob-page--with-cta': showCta && active }">
    <div class="ob-page__main">
      <header v-if="useBookHeader" class="ob-page__header ob-page__header--book">
        <h1 class="ob-page__title-en">{{ titleEn }}</h1>
        <p class="ob-page__title-zh">{{ titleZh }}</p>
      </header>
      <header v-else class="ob-page__header ob-page__header--default">
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
    </div>

    <div v-if="showCta && active" class="ob-page__cta-wrap">
      <button type="button" class="ob-page__cta" @click="onCta">
        {{ ctaLabel }}
      </button>
    </div>
  </section>
</template>

<style scoped lang="less">
.ob-page__content :deep(.word-plain) {
  color: #a8ce20;
}

.ob-page__content :deep(.bilingual__zh) {
  color: #a8ce20;
}

.ob-page {
  background: #f0f9a0;
  color: var(--accent-strong);
  font-family: 'FZCuYuan', 'Gotham Rounded';
  display: flex;
  flex-direction: column;
  align-items: stretch;
  min-height: 100%;
}

.ob-page__main {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  min-height: 0;
  position: relative;
}

.ob-page__header {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.ob-page__header--default {
  margin-top: clamp(48px, 10vh, 88px);
  gap: 2px;
  color: #a8ce20;
}

.ob-page__header--book {
  margin-top: clamp(40px, 8vh, 72px);
  gap: 10px;
  padding: 0 28px;
  margin-bottom: 16px;
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

.ob-page__title-en {
  margin: 0;
  font-family: 'Gotham Rounded', 'FZCuYuan';
  font-size: 15px;
  font-weight: 700;
  line-height: 1.25;
  letter-spacing: 0.02em;
  color: var(--accent-strong);
}

.ob-page__title-zh {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  letter-spacing: 0.1em;
  color: #a8ce20;
}

.ob-page__divider {
  width: 60%;
  border-top: 2px dashed rgba(var(--accent-rgb), 0.35);
  margin: 4px 0 8px;
}

.ob-page__content {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  width: 100%;
  box-sizing: border-box;
}

.ob-page__content :deep(.bilingual__en) {
  font-size: clamp(17px, 4.2vw, 20px);
  font-weight: 700;
  color: var(--accent-strong);
}


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

.ob-page__cta-wrap {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 40px;
  z-index: 20;
  display: flex;
  justify-content: center;
  padding: 12px 28px calc(12px + env(safe-area-inset-bottom));
  box-sizing: border-box;
}

.ob-page__cta {
  border: none;
  border-radius: 999px;
  // background: var(--accent-strong);
  color: #A8CE20;
  padding: 10px 40px;
  font-size: 16px;
  border: 1px solid #A8CE20;
  cursor: pointer;
  touch-action: manipulation;
  width: 166px;
}

.ob-page__cta:active {
  transform: translateY(2px);
}
</style>
