<script setup lang="ts">
import WordTapText from '@/components/WordTapText.vue';

defineProps<{
  zh: string[];
  en: string[];
  interactiveSet: Set<string>;
}>();

const emit = defineEmits<{
  (e: 'interactive-click', canonical: string): void;
}>();

const onInteractiveClick = (canonical: string) => {
  emit('interactive-click', canonical);
};
</script>

<template>
  <div class="bilingual">
    <div class="bilingual__zh">
      <p v-for="(line, index) in zh" :key="`zh-${index}`" class="bilingual__line">
        {{ line }}
      </p>
    </div>
    <div class="bilingual__divider" aria-hidden="true"></div>
    <div class="bilingual__en">
      <WordTapText
        v-for="(line, index) in en"
        :key="`en-${index}`"
        :text="line"
        :interactiveSet="interactiveSet"
        @interactive-click="onInteractiveClick"
      />
    </div>
  </div>
</template>

<style scoped lang="less">
.bilingual {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.bilingual__zh {
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 0.08em;
  font-family: "FZCuYuan", "Gotham Rounded";
}

.bilingual__divider {
  width: 70%;
  border-top: 2px dashed rgba(var(--accent-rgb), 0.4);
}

.bilingual__en {
  font-size: 18px;
  font-weight: 500;
  color: var(--plain-text-color);
  letter-spacing: 0.04em;
  font-family: "Gotham Rounded", "FZCuYuan";
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.bilingual__line {
  margin: 0;
}
</style>
