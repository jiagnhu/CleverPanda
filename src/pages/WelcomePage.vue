<script setup lang="ts">
import { getOrCreateAnalyticsSessionId } from '@/analytics/identity';
import { postJson } from '@/api/client';
import { getSurveyEnv } from '@/utils/surveyEnv';
import { getSurveySource } from '@/utils/surveySource';
import { POPUP_VERSION } from '@/utils/popupVersion';

const CTA_KEY = 'cp_survey_cta';
const SHOW_TRIAL_CTA = false;

withDefaults(
  defineProps<{
    showSwipeGuide?: boolean;
  }>(),
  {
    showSwipeGuide: false
  }
);

const emit = defineEmits<{
  (e: 'start-reading'): void;
}>();

const postFeedback = async (payload: Record<string, unknown>) => {
  try {
    const response = await postJson('/api/feedback.php', payload);
    return response.ok;
  } catch {
    return false;
  }
};

const onCtaClick = () => {
  try {
    sessionStorage.setItem(CTA_KEY, 'true');
  } catch {}
  void postFeedback({
    action: 'cta',
    session_id: getOrCreateAnalyticsSessionId(),
    cta_clicked: 1,
    source: getSurveySource(),
    env: getSurveyEnv(),
    popup_version: POPUP_VERSION
  });
};

const onStartReading = () => {
  onCtaClick();
  emit('start-reading');
};
</script>

<template>
  <section class="screen welcome-screen" aria-label="Welcome">
    <div class="welcome-screen__content">
      <div class="welcome-screen__title">
        <div class="welcome-screen__title-cn">
          <p class="welcome-screen__line">机灵</p>
          <p class="welcome-screen__line">小熊猫</p>
        </div>
        <div class="welcome-screen__title-en">
          <p class="welcome-screen__line">CLEVER LITTLE</p>
          <p class="welcome-screen__line">PANDA</p>
        </div>
      </div>
      <div class="welcome-screen__copy">
        <p class="welcome-screen__copy-line">在阅读双语小故事的过程中学习英语</p>
        <div class="welcome-screen__copy-block">
          <p class="welcome-screen__copy-line">如果您的孩子刚好是在7岁到11岁之间</p>
          <p class="welcome-screen__copy-line">请和孩子一起大声朗读，按下单词的键</p>
          <p class="welcome-screen__copy-line">听听每个单词的发音</p>
        </div>
        <div class="welcome-screen__copy-block">
          <p class="welcome-screen__copy-line">试用时无需注册</p>
          <p class="welcome-screen__copy-line">试用仅需2-3分钟</p>
          <p class="welcome-screen__copy-line">可以随时停止</p>
        </div>
      </div>
      <div class="welcome-screen__actions">
        <button class="welcome-screen__start-btn" type="button" @click="onStartReading">
          <span class="welcome-screen__start-btn-main">开始互动阅读</span>
          <span class="welcome-screen__start-btn-sub">Start Reading</span>
        </button>
        <Transition name="swipe-guide-fade">
          <div v-if="showSwipeGuide" class="welcome-screen__swipe-guide" aria-hidden="true">
            <div class="welcome-screen__swipe-track">
              <img class="welcome-screen__swipe-hand" src="/svgs/hand.svg" alt="" />
              <div class="welcome-screen__swipe-arrows">
                <span class="welcome-screen__swipe-arrow"></span>
                <span class="welcome-screen__swipe-arrow"></span>
                <span class="welcome-screen__swipe-arrow"></span>
              </div>
            </div>
            <p class="welcome-screen__swipe-text">也可左右滑动查看更多</p>
          </div>
        </Transition>
      </div>
      <button v-if="SHOW_TRIAL_CTA" class="welcome-screen__cta" type="button" @click="onCtaClick">
        <span class="welcome-screen__cta-line">点击这里先看一下</span>
        <!-- <span class="welcome-screen__cta-line">可先试用1至2页看看</span> -->
      </button>
    </div>
  </section>
</template>

<style scoped lang="less">
.welcome-screen {
  background: #a6c92a;
  color: #f8f6e6;
  justify-content: flex-start;
}

.welcome-screen__content {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: min(86vw, 320px);
  margin-top: clamp(56px, 10vh, 92px);
  text-align: center;
}

.welcome-screen__title {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.welcome-screen__title-cn {
  font-size: 50px;
  font-weight: 700;
  line-height: 1.12;
  font-family: "FZCuYuan", "Gotham Rounded";
  .welcome-screen__line:last-of-type {
    font-size: 32px;
  }
}

.welcome-screen__title-en {
  display: none;
  font-size: 18px;
  font-weight: 700;
  font-family: "Gotham Rounded", "FZCuYuan";
  color: #f2f7b8;
  .welcome-screen__line {
    line-height: 1;
  }
}

.welcome-screen__line {
  margin: 0;
}

.welcome-screen__copy {
  display: flex;
  flex-direction: column;
  gap: 14px;
  color: #FAFF98;
  font-size: 13px;
  line-height: 21px;
  letter-spacing: 1px;
  font-family: "FZCuYuan", "Gotham Rounded";
  margin-top: 30px;
  height: 210px;
}

.welcome-screen__actions {
  width: 100%;
  margin-top: 28px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding-bottom: 18px;
}

.welcome-screen__start-btn {
  width: 100%;
  border: none;
  border-radius: 999px;
  background: #f8f6e6;
  color: var(--accent-strong);
  padding: 14px 22px 13px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  box-shadow: 0 12px 24px rgba(63, 111, 19, 0.18);
  transition: transform 120ms ease, box-shadow 120ms ease;
}

.welcome-screen__start-btn:active {
  transform: translateY(2px);
  box-shadow: 0 8px 16px rgba(63, 111, 19, 0.16);
}

.welcome-screen__start-btn-main {
  font-size: 18px;
  line-height: 1.2;
  font-weight: 700;
  letter-spacing: 0.04em;
  font-family: "FZCuYuan", "Gotham Rounded";
}

.welcome-screen__start-btn-sub {
  font-size: 12px;
  line-height: 1.2;
  color: rgba(77, 165, 26, 0.78);
  font-family: "Gotham Rounded", "FZCuYuan";
}

.welcome-screen__swipe-guide {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  color: rgba(248, 246, 230, 0.9);
}

.welcome-screen__swipe-track {
  display: flex;
  align-items: center;
  gap: 4px;
}

.welcome-screen__swipe-hand {
  width: 34px;
  height: 34px;
  display: block;
  opacity: 0.92;
  animation: welcome-swipe-hand 1.8s ease-in-out infinite;
}

.welcome-screen__swipe-arrows {
  display: flex;
  align-items: center;
  gap: 4px;
}

.welcome-screen__swipe-arrow {
  width: 10px;
  height: 10px;
  border-top: 2px solid rgba(248, 246, 230, 0.92);
  border-right: 2px solid rgba(248, 246, 230, 0.92);
  transform: rotate(45deg);
  animation: welcome-swipe-arrow 1.8s ease-in-out infinite;
}

.welcome-screen__swipe-arrow:nth-child(2) {
  animation-delay: 0.12s;
}

.welcome-screen__swipe-arrow:nth-child(3) {
  animation-delay: 0.24s;
}

.welcome-screen__swipe-text {
  margin: 0;
  font-size: 12px;
  line-height: 1.2;
  letter-spacing: 0.08em;
  font-family: "FZCuYuan", "Gotham Rounded";
}

.welcome-screen__copy-block {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.welcome-screen__copy-line {
  margin: 0;
}

.welcome-screen__cta {
  margin-top: 12px;
  padding: 7px 26px;
  border-radius: 999px;
  border: 3px solid #f8f6e6;
  color: #FAFF98;
  font-weight: 400;
  line-height: 21px;
  font-size: 16px;
  font-family: "FZCuYuan", "Gotham Rounded";
  background: transparent;
  cursor: pointer;
}

.welcome-screen__cta-line {
  margin: 0;
  display: block;
}

.swipe-guide-fade-enter-active,
.swipe-guide-fade-leave-active {
  transition: opacity 180ms ease, transform 180ms ease;
}

.swipe-guide-fade-enter-from,
.swipe-guide-fade-leave-to {
  opacity: 0;
  transform: translateY(6px);
}

@keyframes welcome-swipe-hand {
  0%,
  100% {
    transform: translateX(0);
  }

  38% {
    transform: translateX(14px);
  }

  62% {
    transform: translateX(10px);
  }
}

@keyframes welcome-swipe-arrow {
  0%,
  100% {
    opacity: 0.35;
    transform: translateX(0) rotate(45deg);
  }

  45% {
    opacity: 1;
    transform: translateX(6px) rotate(45deg);
  }
}
</style>
