<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { postJson } from '@/api/client';
import { getSurveyEnv } from '@/utils/surveyEnv';
import { getSurveySource } from '@/utils/surveySource';
import { POPUP_VERSION } from '@/utils/popupVersion';

type ModalState = 'closed' | 'consent' | 'question' | 'thanks';
const SURVEY_VERSION = 'v3.1';
const SESSION_KEY = 'cp_survey_session';
const CTA_KEY = 'cp_survey_cta';
const COMPLETED_KEY = `cp_survey_completed_${SURVEY_VERSION}`;
const ANSWER_KEY = `cp_survey_answer_${SURVEY_VERSION}`;

const modalState = ref<ModalState>('closed');
const answer = ref('');
const submitError = ref('');
const submitting = ref(false);
const textareaRef = ref<HTMLTextAreaElement | null>(null);
let thanksTimer: number | null = null;
let autoTimer: number | null = null;

const isOpen = computed(() => modalState.value !== 'closed');
const isThanks = computed(() => modalState.value === 'thanks');
const canSubmit = computed(() => answer.value.replace(/\s+/g, '').length > 0);

const getSessionId = () => {
  try {
    const existing = sessionStorage.getItem(SESSION_KEY);
    if (existing) return existing;
    const generated =
      typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `s_${Date.now()}_${Math.random().toString(16).slice(2)}`;
    sessionStorage.setItem(SESSION_KEY, generated);
    return generated;
  } catch {
    return `s_${Date.now()}_${Math.random().toString(16).slice(2)}`;
  }
};

const hasCtaClicked = () => {
  try {
    return sessionStorage.getItem(CTA_KEY) === 'true';
  } catch {
    return false;
  }
};

const postFeedback = async (payload: Record<string, unknown>) => {
  try {
    const response = await postJson('/api/feedback.php', payload);
    return response.ok;
  } catch {
    return false;
  }
};

const hasCompleted = () => {
  try {
    return localStorage.getItem(COMPLETED_KEY) === 'true';
  } catch {
    return false;
  }
};

const setCompleted = () => {
  try {
    localStorage.setItem(COMPLETED_KEY, 'true');
  } catch {}
};

const setStoredAnswer = (value: string) => {
  try {
    localStorage.setItem(ANSWER_KEY, value);
  } catch {}
};

const clearAutoTimer = () => {
  if (autoTimer) {
    window.clearTimeout(autoTimer);
    autoTimer = null;
  }
};

const scheduleAutoPopup = () => {
  clearAutoTimer();
  if (hasCompleted()) return;
  autoTimer = window.setTimeout(() => {
    if (hasCompleted() || modalState.value !== 'closed') return;
    modalState.value = 'consent';
  }, 5000);
};

const closeModal = () => {
  modalState.value = 'closed';
  submitError.value = '';
  if (thanksTimer) {
    window.clearTimeout(thanksTimer);
    thanksTimer = null;
  }
};

const onConsent = () => {
  modalState.value = 'question';
  submitError.value = '';
  nextTick(() => textareaRef.value?.focus());
};

const startThanksTimer = () => {
  if (thanksTimer) {
    window.clearTimeout(thanksTimer);
  }
  thanksTimer = window.setTimeout(() => {
    closeModal();
  }, 2000);
};

const onSubmit = () => {
  if (!canSubmit.value || submitting.value) return;
  submitting.value = true;
  submitError.value = '';
  const trimmed = answer.value.trim();
  void (async () => {
    const ok = await postFeedback({
      action: 'submit',
      session_id: getSessionId(),
      cta_clicked: hasCtaClicked() ? 1 : 0,
      answer: trimmed,
      source: getSurveySource(),
      env: getSurveyEnv(),
      popup_version: POPUP_VERSION
    });
    submitting.value = false;
    if (!ok) {
      submitError.value = '提交失败，请稍后再试。';
      return;
    }
    setStoredAnswer(trimmed);
    setCompleted();
    answer.value = '';
    modalState.value = 'thanks';
    startThanksTimer();
  })();
};

onMounted(() => {
  scheduleAutoPopup();
});

onBeforeUnmount(() => {
  if (thanksTimer) {
    window.clearTimeout(thanksTimer);
  }
  clearAutoTimer();
});
</script>

<template>
  <div v-if="isOpen" class="survey-modal">
    <div class="survey-modal__overlay" aria-hidden="true"></div>
    <div
      class="survey-dialog"
      :class="{
        'survey-dialog--thanks': isThanks,
        'survey-dialog--consent': modalState === 'consent'
      }"
      role="dialog"
      aria-modal="true"
    >
      <template v-if="modalState === 'consent'">
        <p class="survey-dialog__eyebrow">在继续之前</p>
        <div class="survey-dialog__body survey-dialog__body--consent">
          <p class="survey-dialog__text">我们正在测试一个</p>
          <p class="survey-dialog__text">非常早期的版本</p>
          <p class="survey-dialog__text">你愿意回答一个简单的问题吗？</p>
        </div>
        <div class="survey-dialog__actions">
          <button
            class="survey-dialog__button survey-dialog__button--primary"
            type="button"
            @click.stop="onConsent"
          >
            我愿意回答一个问题
          </button>
        </div>
      </template>
      <template v-else-if="modalState === 'question'">
        <p class="survey-dialog__eyebrow">在继续之前</p>
        <div class="survey-dialog__body">
          <p class="survey-dialog__question">当你看到这个页面时，</p>
          <p class="survey-dialog__question">你觉得它可能是为谁准备的？</p>
        </div>
        <textarea
          ref="textareaRef"
          v-model="answer"
          class="survey-dialog__textarea"
          rows="4"
          placeholder="请写下你的第一直觉想法…"
        ></textarea>
        <p class="survey-dialog__hint">没有对错，只是你的直觉反应</p>
        <p v-if="submitError" class="survey-dialog__error">{{ submitError }}</p>
        <button
          class="survey-dialog__button survey-dialog__button--ghost"
          type="button"
          :disabled="!canSubmit || submitting"
          @click.stop="onSubmit"
        >
          提交
        </button>
      </template>
      <template v-else>
        <div class="survey-dialog__thanks-wrap">
          <p class="survey-dialog__thanks">谢谢你!</p>
          <p class="survey-dialog__thanks-sub">THANK YOU!</p>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped lang="less">
.survey-modal {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.survey-modal__overlay {
  position: absolute;
  inset: 0;
  background: rgba(89, 122, 12, 0.3);
  backdrop-filter: blur(2px);
}

.survey-dialog {
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

.survey-dialog--thanks {
  padding: 42px 20px;
  min-height: 220px;
  justify-content: center;
}

.survey-dialog--consent {
  padding: 28px 20px 24px;
  gap: 16px;
}

.survey-dialog__eyebrow {
  margin: 0;
  font-size: 14px;
  letter-spacing: 0.2em;
  color: #FAFF98;
}

.survey-dialog__body {
  display: flex;
  flex-direction: column;
}

.survey-dialog__body--consent {
  gap: 4px;
  min-height: 103px;
  justify-content: center;
}

.survey-dialog__text {
  margin: 0;
  font-size: 16px;
  font-weight: 400;
  color: #f8f6b3;
  line-height: 1.5;
}

.survey-dialog__question {
  margin: 0;
  font-size: 16px;
  font-weight: 400;
  color: #f8f6b3;
  line-height: 1.5;
}

.survey-dialog__textarea {
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

.survey-dialog__textarea:focus {
  outline: none;
  box-shadow: inset 0 2px 0 rgba(0, 0, 0, 0.08);
}

.survey-dialog__textarea::placeholder {
  color: #A8CE20;
}

.survey-dialog__hint {
  margin: 0;
  font-size: 12px;
  color: #FAFF98;
}

.survey-dialog__error {
  margin: 0;
  font-size: 12px;
  color: #fff4c2;
}

.survey-dialog__actions {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.survey-dialog__button {
  border-radius: 999px;
  padding: 10px 16px;
  font-size: 14px;
  font-weight: 700;
  font-family: "FZCuYuan", "Gotham Rounded";
  cursor: pointer;
  transition: transform 120ms ease, box-shadow 120ms ease, opacity 120ms ease;
}

.survey-dialog__button:active {
  transform: translateY(1px);
}

.survey-dialog__button--primary {
  border: none;
  background: #f8f6e6;
  color: #7eaa1a;
  box-shadow: 0 6px 0 rgba(122, 150, 28, 0.4);
}

.survey-dialog__button--ghost {
  border: 4px solid rgba(248, 246, 230, 0.9);
  background: transparent;
  color: #f8f6e6;
}

.survey-dialog__button[disabled] {
  opacity: 0.6;
  cursor: default;
  box-shadow: none;
}

.survey-dialog--consent .survey-dialog__eyebrow {
  font-size: 12px;
  letter-spacing: 0.28em;
}

.survey-dialog--consent .survey-dialog__text {
  font-size: 17px;
  font-weight: 700;
  color: #f8f6e6;
}

.survey-dialog--consent .survey-dialog__button {
  width: 100%;
  border: 3px solid rgba(248, 246, 230, 0.9);
  background: transparent;
  color: #f8f6e6;
  box-shadow: none;
  padding: 9px 16px;
  font-weight: 600;
}

.survey-dialog--consent .survey-dialog__button:active {
  transform: translateY(1px);
  opacity: 0.92;
}

.survey-dialog__thanks-wrap {
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  padding: 100px 0;
}

.survey-dialog__thanks {
  margin: 0;
  font-size: 46px;
  font-weight: 700;
}

.survey-dialog__thanks-sub {
  margin: 0;
  font-size: 23px;
  letter-spacing: 0.1em;
  color: #f8f6b3;
  font-weight: 700;
}
</style>
