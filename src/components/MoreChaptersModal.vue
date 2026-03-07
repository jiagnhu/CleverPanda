<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import {
  submitMoreChaptersResponse,
  type MoreChaptersResponse
} from '@/tracking/behaviorTracker';

type ModalStep = 'choice' | 'yes-contact' | 'no-feedback' | 'thanks' | 'yes-thanks';

const props = withDefaults(
  defineProps<{
    visible: boolean;
    bookId?: string;
    chapterNo?: number | null;
  }>(),
  {
    bookId: '',
    chapterNo: null
  }
);

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'exit-home'): void;
}>();

const step = ref<ModalStep>('choice');
const email = ref('');
const feedback = ref('');
const submitting = ref(false);
const error = ref('');
let exitTimer: number | null = null;

const resetState = () => {
  step.value = 'choice';
  email.value = '';
  feedback.value = '';
  submitting.value = false;
  error.value = '';
  if (exitTimer) {
    window.clearTimeout(exitTimer);
    exitTimer = null;
  }
};

watch(
  () => props.visible,
  (visible, prevVisible) => {
    if (visible && !prevVisible) {
      resetState();
    }
  }
);

const submitResponse = async (response: MoreChaptersResponse, note = '') => {
  if (!props.bookId || !props.chapterNo) return false;
  return submitMoreChaptersResponse(props.bookId, props.chapterNo, response, note);
};

const onYes = async () => {
  if (submitting.value) return;
  submitting.value = true;
  error.value = '';
  const ok = await submitResponse('yes');
  submitting.value = false;
  if (!ok) {
    error.value = '提交失败，请稍后重试。';
    return;
  }
  step.value = 'yes-contact';
};

const onYesDone = async (withEmail: boolean) => {
  if (submitting.value) return;
  submitting.value = true;
  error.value = '';
  const note = withEmail && email.value.trim() ? `邮箱:${email.value.trim()}` : '';
  const ok = await submitResponse('yes', note);
  submitting.value = false;
  if (!ok) {
    error.value = '提交失败，请稍后重试。';
    return;
  }
  if (!withEmail) {
    emit('exit-home');
    return;
  }

  step.value = 'yes-thanks';
  exitTimer = window.setTimeout(() => {
    emit('exit-home');
  }, 1000);
};

const onNo = async () => {
  if (submitting.value) return;
  submitting.value = true;
  error.value = '';
  const ok = await submitResponse('no');
  submitting.value = false;
  if (!ok) {
    error.value = '提交失败，请稍后重试。';
    return;
  }
  step.value = 'no-feedback';
};

const onNoDone = async () => {
  if (submitting.value) return;
  submitting.value = true;
  error.value = '';
  const ok = await submitResponse('no', feedback.value.trim());
  submitting.value = false;
  if (!ok) {
    error.value = '提交失败，请稍后重试。';
    return;
  }

  step.value = 'thanks';
  exitTimer = window.setTimeout(() => {
    emit('exit-home');
  }, 1500);
};

onBeforeUnmount(() => {
  if (exitTimer) {
    window.clearTimeout(exitTimer);
  }
});
</script>

<template>
  <div v-if="visible" class="more-chapters-modal">
    <div class="more-chapters-modal__mask" aria-hidden="true"></div>
    <div class="more-chapters-modal__dialog" role="dialog" aria-modal="true">
      <template v-if="step === 'choice'">
        <p class="more-chapters-modal__title">想继续读下一章吗？</p>
        <div class="more-chapters-modal__actions more-chapters-modal__actions--inline">
          <button class="more-chapters-modal__btn" type="button" :disabled="submitting" @click="onYes">
            是的
          </button>
          <button class="more-chapters-modal__btn more-chapters-modal__btn--ghost" type="button" :disabled="submitting" @click="onNo">
            不用了
          </button>
        </div>
      </template>

      <template v-else-if="step === 'yes-contact'">
        <p class="more-chapters-modal__title">是否接收更新通知？</p>
        <p class="more-chapters-modal__subtitle">可选填写邮箱，我们有新章节会通知你</p>
        <input
          v-model="email"
          type="email"
          class="more-chapters-modal__input"
          placeholder="邮箱（可选）"
        />
        <div class="more-chapters-modal__actions">
          <button class="more-chapters-modal__btn" type="button" :disabled="submitting" @click="onYesDone(true)">
            提交
          </button>
          <button class="more-chapters-modal__btn more-chapters-modal__btn--ghost" type="button" :disabled="submitting" @click="onYesDone(false)">
            跳过
          </button>
        </div>
      </template>

      <template v-else-if="step === 'no-feedback'">
        <p class="more-chapters-modal__title">你觉得这次阅读怎么样？</p>
        <p class="more-chapters-modal__subtitle">告诉我们你的感受（可选）</p>
        <textarea
          v-model="feedback"
          rows="3"
          class="more-chapters-modal__textarea"
          placeholder="可选反馈"
        ></textarea>
        <div class="more-chapters-modal__actions">
          <button class="more-chapters-modal__btn" type="button" :disabled="submitting" @click="onNoDone">
            提交并返回
          </button>
        </div>
      </template>

      <template v-else-if="step === 'thanks'">
        <p class="more-chapters-modal__title">谢谢阅读！😊</p>
        <p class="more-chapters-modal__subtitle">希望你喜欢爱丽丝的冒险。</p>
        <p class="more-chapters-modal__subtitle">下次想读更多随时回来哦！</p>
      </template>
      <template v-else>
        <p class="more-chapters-modal__title">已收到你的信息</p>
        <p class="more-chapters-modal__subtitle">我们有新章节会第一时间通知你。</p>
      </template>

      <p v-if="error" class="more-chapters-modal__error">{{ error }}</p>
    </div>
  </div>
</template>

<style scoped lang="less">
.more-chapters-modal {
  position: absolute;
  inset: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.more-chapters-modal__mask {
  position: absolute;
  inset: 0;
  background: rgba(47, 78, 11, 0.32);
}

.more-chapters-modal__dialog {
  position: relative;
  z-index: 1;
  width: min(90vw, 340px);
  min-height: 120px;
  border-radius: 18px;
  background: #f8f6e6;
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.16);
  padding: 18px 16px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  text-align: center;
}

.more-chapters-modal__title {
  margin: 0;
  color: var(--accent-strong);
  font-size: 22px;
  font-weight: 700;
}

.more-chapters-modal__subtitle {
  margin: 0;
  color: #32570f;
  font-size: 14px;
  line-height: 1.4;
}

.more-chapters-modal__actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 4px;
}

.more-chapters-modal__actions--inline {
  flex-direction: row;
}

.more-chapters-modal__actions--inline .more-chapters-modal__btn {
  flex: 1;
}

.more-chapters-modal__btn {
  border: none;
  border-radius: 999px;
  background: var(--accent-strong);
  color: #f8f6e6;
  padding: 10px 14px;
  font-size: 14px;
  font-weight: 700;
}

.more-chapters-modal__btn--ghost {
  background: rgba(var(--accent-rgb), 0.15);
  color: #2d5110;
}

.more-chapters-modal__btn:disabled {
  opacity: 0.55;
}

.more-chapters-modal__input,
.more-chapters-modal__textarea {
  width: 100%;
  border-radius: 10px;
  border: 1px solid rgba(var(--accent-rgb), 0.25);
  padding: 10px;
  font-size: 14px;
  line-height: 1.4;
}

.more-chapters-modal__input:focus,
.more-chapters-modal__textarea:focus {
  outline: none;
  border-color: rgba(var(--accent-rgb), 0.25);
}

.more-chapters-modal__error {
  margin: 0;
  color: #bf3d1f;
  font-size: 12px;
}
</style>
