<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
  submitAnalyticsParentFeedback,
  type ParentFeedbackChoice
} from '@/analytics/manager';

const props = withDefaults(
  defineProps<{
    visible: boolean;
    bookId?: string;
    chapterNo?: number | null;
    title?: string;
    question?: string;
    submitLabel?: string;
  }>(),
  {
    bookId: '',
    chapterNo: null,
    title: '家长反馈',
    question: '孩子喜欢这个章节吗？是否想继续听下一个故事？',
    submitLabel: '提交反馈'
  }
);

const emit = defineEmits<{
  (e: 'submitted'): void;
}>();

const choice = ref<ParentFeedbackChoice | ''>('');
const comment = ref('');
const submitting = ref(false);
const error = ref('');
const canSubmit = computed(() => choice.value !== '' && !submitting.value);

watch(
  () => props.visible,
  (visible, prevVisible) => {
    if (visible && !prevVisible) {
      choice.value = '';
      comment.value = '';
      error.value = '';
    }
  }
);

const onSubmit = async () => {
  const normalizedChoice = choice.value;
  if (normalizedChoice !== 'yes' && normalizedChoice !== 'no') return;
  if (!props.bookId || !props.chapterNo) return;
  if (submitting.value) return;

  submitting.value = true;
  error.value = '';
  const ok = await submitAnalyticsParentFeedback(
    props.bookId,
    props.chapterNo,
    normalizedChoice,
    comment.value
  );
  submitting.value = false;

  if (!ok) {
    error.value = '提交失败，请稍后重试。';
    return;
  }

  emit('submitted');
};
</script>

<template>
  <div v-if="visible" class="parent-feedback-modal">
    <div class="parent-feedback-modal__mask" aria-hidden="true"></div>
    <div class="parent-feedback-modal__dialog" role="dialog" aria-modal="true">
      <p class="parent-feedback-modal__title">{{ title }}</p>
      <p class="parent-feedback-modal__question">{{ question }}</p>

      <div class="parent-feedback-modal__choices">
        <button
          type="button"
          class="parent-feedback-modal__choice"
          :class="{ 'parent-feedback-modal__choice--active': choice === 'yes' }"
          @click="choice = 'yes'"
        >
          Yes
        </button>
        <button
          type="button"
          class="parent-feedback-modal__choice"
          :class="{ 'parent-feedback-modal__choice--active': choice === 'no' }"
          @click="choice = 'no'"
        >
          No
        </button>
      </div>

      <textarea
        v-model="comment"
        class="parent-feedback-modal__textarea"
        rows="3"
        placeholder="可填写简短评论（选填）"
      ></textarea>

      <p v-if="error" class="parent-feedback-modal__error">{{ error }}</p>
      <button
        type="button"
        class="parent-feedback-modal__submit"
        :disabled="!canSubmit"
        @click="onSubmit"
      >
        {{ submitting ? '提交中...' : submitLabel }}
      </button>
    </div>
  </div>
</template>

<style scoped lang="less">
.parent-feedback-modal {
  position: absolute;
  inset: 0;
  z-index: 15;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.parent-feedback-modal__mask {
  position: absolute;
  inset: 0;
  background: rgba(47, 78, 11, 0.32);
}

.parent-feedback-modal__dialog {
  position: relative;
  z-index: 1;
  width: min(90vw, 340px);
  border-radius: 18px;
  background: #f8f6e6;
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.16);
  padding: 18px 16px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.parent-feedback-modal__title {
  margin: 0;
  color: var(--accent-strong);
  font-size: 20px;
  font-weight: 700;
}

.parent-feedback-modal__question {
  margin: 0;
  color: #32570f;
  font-size: 14px;
  line-height: 1.4;
}

.parent-feedback-modal__choices {
  display: flex;
  gap: 10px;
}

.parent-feedback-modal__choice {
  flex: 1;
  border-radius: 999px;
  border: 2px solid rgba(var(--accent-rgb), 0.45);
  background: #fff;
  color: #32570f;
  padding: 8px 10px;
  font-size: 14px;
  font-weight: 600;
}

.parent-feedback-modal__choice--active {
  border-color: var(--accent-strong);
  background: rgba(var(--accent-rgb), 0.12);
}

.parent-feedback-modal__textarea {
  width: 100%;
  border-radius: 12px;
  border: 1px solid rgba(var(--accent-rgb), 0.25);
  padding: 10px;
  font-size: 14px;
  line-height: 1.5;
  resize: none;
}

.parent-feedback-modal__textarea:focus {
  outline: none;
  border-color: rgba(var(--accent-rgb), 0.25);
}

.parent-feedback-modal__error {
  margin: 0;
  color: #bf3d1f;
  font-size: 12px;
}

.parent-feedback-modal__submit {
  border: none;
  border-radius: 999px;
  background: var(--accent-strong);
  color: #f8f6e6;
  padding: 10px;
  font-size: 15px;
  font-weight: 700;
}

.parent-feedback-modal__submit:disabled {
  opacity: 0.5;
}
</style>
