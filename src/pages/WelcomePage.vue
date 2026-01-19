<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';

type ModalState = 'closed' | 'consent' | 'question' | 'thanks';
const COMPLETED_KEY = 'cp_survey_completed';
const SKIPPED_KEY = 'cp_survey_skipped';
const ANSWER_KEY = 'cp_survey_answer';

const modalState = ref<ModalState>('closed');
const answer = ref('');
const textareaRef = ref<HTMLTextAreaElement | null>(null);
let thanksTimer: number | null = null;

const isOpen = computed(() => modalState.value !== 'closed');
const isThanks = computed(() => modalState.value === 'thanks');
const canSubmit = computed(() => answer.value.trim().length > 0);

const hasCompleted = () => {
  try {
    return localStorage.getItem(COMPLETED_KEY) === 'true';
  } catch {
    return false;
  }
};

const hasSkippedSession = () => {
  try {
    return sessionStorage.getItem(SKIPPED_KEY) === 'true';
  } catch {
    return false;
  }
};

const setCompleted = () => {
  try {
    localStorage.setItem(COMPLETED_KEY, 'true');
  } catch {}
};

const setSkippedSession = () => {
  try {
    sessionStorage.setItem(SKIPPED_KEY, 'true');
  } catch {}
};

const setStoredAnswer = (value: string) => {
  try {
    localStorage.setItem(ANSWER_KEY, value);
  } catch {}
};

const closeModal = () => {
  modalState.value = 'closed';
  if (thanksTimer) {
    window.clearTimeout(thanksTimer);
    thanksTimer = null;
  }
};

const openSurvey = () => {
  if (hasCompleted() || hasSkippedSession()) return;
  modalState.value = 'consent';
};

const onSkip = () => {
  setSkippedSession();
  closeModal();
};

const onConsent = () => {
  modalState.value = 'question';
  nextTick(() => textareaRef.value?.focus());
};

const startThanksTimer = () => {
  if (thanksTimer) {
    window.clearTimeout(thanksTimer);
  }
  thanksTimer = window.setTimeout(() => {
    closeModal();
  }, 3000);
};

const onSubmit = () => {
  if (!canSubmit.value) return;
  setStoredAnswer(answer.value.trim());
  setCompleted();
  answer.value = '';
  modalState.value = 'thanks';
  startThanksTimer();
};

const onThanksClick = () => {
  if (!isThanks.value) return;
  closeModal();
};

onBeforeUnmount(() => {
  if (thanksTimer) {
    window.clearTimeout(thanksTimer);
  }
});
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
      <button class="welcome-screen__cta" type="button" @click="openSurvey">
        <span class="welcome-screen__cta-line">请点击这里开始试用吧</span>
        <span class="welcome-screen__cta-line">可先试用1至2页看看</span>
      </button>
    </div>
    <div v-if="isOpen" class="welcome-modal">
      <div class="welcome-modal__overlay" aria-hidden="true"></div>
      <div
        class="welcome-dialog"
        :class="{ 'welcome-dialog--thanks': isThanks }"
        role="dialog"
        aria-modal="true"
        @click="onThanksClick"
      >
        <template v-if="modalState === 'consent'">
          <p class="welcome-dialog__eyebrow">在继续之前，</p>
          <div class="welcome-dialog__body">
            <p class="welcome-dialog__text">我们正在测试一个非常早期的版本。</p>
            <p class="welcome-dialog__text">您愿意回答一个简单的问题吗？</p>
          </div>
          <div class="welcome-dialog__actions">
            <button
              class="welcome-dialog__button welcome-dialog__button--primary"
              type="button"
              @click.stop="onConsent"
            >
              我愿意回答一个问题
            </button>
            <button
              class="welcome-dialog__button welcome-dialog__button--ghost"
              type="button"
              @click.stop="onSkip"
            >
              跳过
            </button>
          </div>
        </template>
        <template v-else-if="modalState === 'question'">
          <p class="welcome-dialog__eyebrow">在继续之前</p>
          <div class="welcome-dialog__body">
            <p class="welcome-dialog__question">当你第一次打开这个页面时，</p>
            <p class="welcome-dialog__question">你的第一反应是什么？</p>
          </div>
          <textarea
            ref="textareaRef"
            v-model="answer"
            class="welcome-dialog__textarea"
            rows="4"
            placeholder="请用几句话随意写下你的第一感觉…"
          ></textarea>
          <p class="welcome-dialog__hint">没有对错，只是你的直觉反应</p>
          <button
            class="welcome-dialog__button welcome-dialog__button--ghost"
            type="button"
            :disabled="!canSubmit"
            @click.stop="onSubmit"
          >
            提交
          </button>
        </template>
        <template v-else>
          <div class="welcome-dialog__div">
            <p class="welcome-dialog__thanks">谢谢你!</p>
            <p class="welcome-dialog__thanks-sub">THANK YOU!</p>
          </div>
        </template>
      </div>
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
  margin-top: 12vh;
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

.welcome-modal {
  position: fixed;
  inset: 0;
  z-index: 30;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.welcome-modal__overlay {
  position: absolute;
  inset: 0;
  background: rgba(89, 122, 12, 0.3);
  backdrop-filter: blur(2px);
}

.welcome-dialog {
  position: relative;
  z-index: 1;
  width: min(86vw, 320px);
  background: #9fc225;
  border-radius: 24px;
  padding: 22px 20px 20px;
  color: #f8f6e6;
  text-align: center;
  box-shadow: 0 12px 28px rgba(0, 0, 0, 0.16);
  display: flex;
  flex-direction: column;
  gap: 14px;
  font-family: "FZCuYuan", "Gotham Rounded";
}

.welcome-dialog--thanks {
  padding: 42px 20px;
  min-height: 220px;
  justify-content: center;
  cursor: pointer;
}

.welcome-dialog__eyebrow {
  margin: 0;
  font-size: 14px;
  letter-spacing: 0.2em;
  color: #FAFF98;
}

.welcome-dialog__body {
  display: flex;
  flex-direction: column;
}

.welcome-dialog__text {
  margin: 0;
  font-size: 16px;
  font-weight: 400;
  color: #f8f6b3;
  line-height: 1.5;
}

.welcome-dialog__question {
  margin: 0;
  font-size: 16px;
  font-weight: 400;
  color: #f8f6b3;
  line-height: 1.5;
}

.welcome-dialog__textarea {
  width: 100%;
  min-height: 120px;
  border-radius: 16px;
  border: none;
  padding: 12px 14px;
  font-size: 14px;
  line-height: 1.6;
  color: #A8CE20;
  background: #ffffff;
  font-family: "FZCuYuan", "Gotham Rounded";
  box-shadow: inset 0 2px 0 rgba(0, 0, 0, 0.08);
  resize: none;
}

.welcome-dialog__textarea:focus {
  outline: none;
  box-shadow: inset 0 2px 0 rgba(0, 0, 0, 0.08);
}

.welcome-dialog__textarea::placeholder {
  color: #A8CE20;
}

.welcome-dialog__hint {
  margin: 0;
  font-size: 12px;
  color: #FAFF98;
}

.welcome-dialog__actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.welcome-dialog__button {
  border-radius: 999px;
  padding: 10px 16px;
  font-size: 14px;
  font-weight: 700;
  font-family: "FZCuYuan", "Gotham Rounded";
  cursor: pointer;
  transition: transform 120ms ease, box-shadow 120ms ease, opacity 120ms ease;
}

.welcome-dialog__button:active {
  transform: translateY(1px);
}

.welcome-dialog__button--primary {
  border: none;
  background: #f8f6e6;
  color: #7eaa1a;
  box-shadow: 0 6px 0 rgba(122, 150, 28, 0.4);
}

.welcome-dialog__button--ghost {
  border: 4px solid rgba(248, 246, 230, 0.9);
  background: transparent;
  color: #f8f6e6;
}

.welcome-dialog__button[disabled] {
  opacity: 0.6;
  cursor: default;
  box-shadow: none;
}

.welcome-dialog__div {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  padding: 100px 0;
}

.welcome-dialog__thanks {
  margin: 0;
  font-size: 46px;
  font-weight: 700;
}

.welcome-dialog__thanks-sub {
  margin: 0;
  font-size: 23px;
  letter-spacing: 0.1em;
  color: #f8f6b3;
  font-weight: 700;
}
</style>
