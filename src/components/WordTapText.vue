<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { playWord } from '@/audio/player';
import { canonicalize, tokenize } from '@/utils/tokenize';
import { parseEnRichLine, type WordColor } from '@/utils/enRich';

const props = defineProps<{
  text: string;
  interactiveSet: Set<string>;
}>();

const emit = defineEmits<{
  (e: 'interactive-click', canonical: string): void;
}>();

type TokenItem = {
  token: string;
  canonical: string;
  interactive: boolean;
  color: WordColor | null;
};

const tokenItems = computed<TokenItem[]>(() => {
  const hasRichMarkers = props.text.includes('{g|') || props.text.includes('{b|');
  if (hasRichMarkers) {
    return parseEnRichLine(props.text).map((segment) => {
      if (segment.kind === 'word') {
        return {
          token: segment.text,
          canonical: segment.canonical,
          interactive: true,
          color: segment.color
        };
      }
      return {
        token: segment.text,
        canonical: '',
        interactive: false,
        color: null
      };
    });
  }

  return tokenize(props.text).map((token) => {
    const canonical = canonicalize(token);
    const interactive = canonical !== '' && props.interactiveSet.has(canonical);
    return {
      token,
      canonical,
      interactive,
      color: interactive ? 'green' : null
    };
  });
});

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
        :class="[
          item.color ? `word--${item.color}` : '',
          flashIndex === index ? 'flash' : '',
          flashIndex === index && item.color ? `flash--${item.color}` : ''
        ]"
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

.word--green {
  color: var(--accent-strong);
}

.word--blue {
  color: var(--accent-blue);
}

.word:active {
  transform: translateY(1px) scale(0.98);
}

.word--green:active {
  background: rgba(var(--accent-rgb), 0.16);
}

.word--blue:active {
  background: rgba(var(--accent-blue-rgb), 0.16);
}

.word:focus-visible {
  outline: 2px solid rgba(var(--accent-rgb), 0.6);
  outline-offset: 2px;
}

.word.flash {
  animation-duration: 250ms;
  animation-timing-function: ease-out;
  animation-fill-mode: both;
}

.word.flash--green {
  animation-name: flashGlowGreen;
}

.word.flash--blue {
  animation-name: flashGlowBlue;
}

@keyframes flashGlowGreen {
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

@keyframes flashGlowBlue {
  0% {
    background: rgba(var(--accent-blue-rgb), 0.35);
    box-shadow: 0 0 0 0 rgba(var(--accent-blue-rgb), 0.4);
  }
  70% {
    background: rgba(var(--accent-blue-rgb), 0.2);
    box-shadow: 0 0 0 6px rgba(var(--accent-blue-rgb), 0.25);
  }
  100% {
    background: rgba(var(--accent-blue-rgb), 0);
    box-shadow: 0 0 0 0 rgba(var(--accent-blue-rgb), 0);
  }
}
</style>
