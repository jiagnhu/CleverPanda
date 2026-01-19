<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
  progress: number;
}>();

const percent = computed(() => {
  const value = Number.isFinite(props.progress) ? props.progress : 0;
  return Math.min(100, Math.max(0, Math.round(value * 100)));
});
</script>

<template>
  <div
    class="progress-bar"
    role="progressbar"
    :aria-valuenow="percent"
    aria-valuemin="0"
    aria-valuemax="100"
  >
    <span class="progress-bar__fill" :style="{ width: `${percent}%` }"></span>
  </div>
</template>

<style scoped lang="less">
.progress-bar {
  position: absolute;
  left: 24px;
  right: 24px;
  bottom: calc(12px + env(safe-area-inset-bottom));
  height: 6px;
  background: rgba(var(--accent-rgb), 0.18);
  border-radius: 999px;
  overflow: hidden;
  pointer-events: none;
  z-index: 10;
}

.progress-bar__fill {
  display: block;
  height: 100%;
  width: 0;
  background: var(--accent-strong);
  border-radius: 999px;
  transition: width 220ms ease;
}
</style>
