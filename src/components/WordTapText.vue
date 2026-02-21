<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { playWord } from '@/audio/player';
import { canonicalize, tokenize } from '@/utils/tokenize';

const props = defineProps<{
  text: string;
  interactiveSet: Set<string>;
}>();

const emit = defineEmits<{
  (e: 'interactive-click', canonical: string): void;
}>();

const tokenItems = computed(() =>
  tokenize(props.text).map((token) => {
    const canonical = canonicalize(token);
    return {
      token,
      canonical,
      interactive: canonical !== '' && props.interactiveSet.has(canonical)
    };
  })
);

const flashIndex = ref<number | null>(null);
let flashTimer: number | null = null;

const triggerFlash = (index: number) => {
  flashIndex.value = index;
  if (flashTimer) {
    window.clearTimeout(flashTimer);
  }
  flashTimer = window.setTimeout(() => {
    flashIndex.value = null;
    flashTimer = null;
  }, 250);
};

const onActivate = (canonical: string, index: number) => {
  if (!canonical) return;
  triggerFlash(index);
  emit('interactive-click', canonical);
  playWord(canonical);
};

onBeforeUnmount(() => {
  if (flashTimer) {
    window.clearTimeout(flashTimer);
  }
});
</script>

<template>
  <span class="word-tap-text">
    <template v-for="(item, index) in tokenItems" :key="index">
      <span
        v-if="item.interactive"
        class="word"
        :class="{ flash: flashIndex === index }"
        role="button"
        tabindex="0"
        @click="onActivate(item.canonical, index)"
        @keydown.enter.prevent="onActivate(item.canonical, index)"
        @keydown.space.prevent="onActivate(item.canonical, index)"
        >{{ item.token }}</span
      ><span v-else>{{ item.token }}</span>
    </template>
  </span>
</template>

<style scoped lang="less">
.word {
  color: var(--accent-strong);
  font-weight: 700;
  text-transform: uppercase;
  display: inline-block;
  padding: 2px 4px;
  margin: 0 1px;
  border-radius: 10px;
  touch-action: manipulation;
  cursor: pointer;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
  transition: transform 120ms ease, background-color 120ms ease, box-shadow 120ms ease;
}

.word:active {
  transform: translateY(1px) scale(0.98);
  background: rgba(var(--accent-rgb), 0.16);
}

.word:focus-visible {
  outline: 2px solid rgba(var(--accent-rgb), 0.6);
  outline-offset: 2px;
}

.word.flash {
  animation: flashGlow 250ms ease-out;
}

@keyframes flashGlow {
  0% {
    background: rgba(var(--accent-rgb), 0.35);
    box-shadow: 0 0 0 0 rgba(var(--accent-rgb), 0.4);
  }
  70% {
    background: rgba(var(--accent-rgb), 0.2);
    box-shadow: 0 0 0 6px rgba(var(--accent-rgb), 0.25);
  }
  100% {
    background: rgba(var(--accent-rgb), 0);
    box-shadow: 0 0 0 0 rgba(var(--accent-rgb), 0);
  }
}
</style>
