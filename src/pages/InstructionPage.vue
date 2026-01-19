<script setup lang="ts">
const emit = defineEmits<{
  (e: 'next'): void;
}>();

const onEnter = () => {
  emit('next');
};
</script>

<template>
  <section
    class="screen instruction-screen"
    role="button"
    tabindex="0"
    aria-label="Reading tips"
    @click="onEnter"
    @keydown.enter.prevent="onEnter"
    @keydown.space.prevent="onEnter"
  >
    <div class="instruction-screen__content">
      <div class="instruction-screen__block">
        <p class="instruction-screen__line">
          请点击一个<span class="instruction-screen__highlight">绿色的</span>单词
        </p>
        <p class="instruction-screen__line">听一听它的发音</p>
        <p class="instruction-screen__line">大声跟读</p>
      </div>
      <div class="instruction-screen__block">
        <p class="instruction-screen__line">
          接着请点击下一个<span class="instruction-screen__highlight">绿色的</span>单词
        </p>
        <p class="instruction-screen__line">听一下它的发音</p>
        <p class="instruction-screen__line">再跟着大声读出</p>
      </div>
      <div class="instruction-screen__block">
        <p class="instruction-screen__line">每一句都这样读</p>
        <p class="instruction-screen__line">一页接着一页往下读</p>
      </div>
    </div>
    <div class="instruction-screen__icon" aria-hidden="true">
      <img class="instruction-screen__icon-speaker" src="/svgs/Speaker.svg" alt="" />
      <span class="instruction-screen__green-wrap">
        <span class="instruction-screen__green">GREEN</span>
        <img class="instruction-screen__icon-hand" src="/svgs/hand.svg" alt="" />
      </span>
    </div>
  </section>
</template>

<style scoped lang="less">
.instruction-screen {
  background: radial-gradient(circle at 15% 12%, #fbfbc2 0%, #e4ef8a 48%, #b6d34a 100%);
  color: var(--accent);
  font-family: "FZCuYuan", "Gotham Rounded";
  justify-content: center;
  gap: 36px;
  cursor: pointer;
}

.instruction-screen__content {
  display: flex;
  flex-direction: column;
  gap: 22px;
  align-items: center;
}

.instruction-screen__block {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 18px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-align: center;
}

.instruction-screen__line {
  margin: 0;
}

.instruction-screen__highlight {
  color: var(--accent-strong);
}

.instruction-screen__icon {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 18px;
  color: var(--accent-strong);
}

.instruction-screen__green-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.instruction-screen__icon-speaker {
  width: 46px;
  height: 46px;
  display: block;
}

.instruction-screen__icon-hand {
  width: 70px;
  height: 70px;
  position: absolute;
  left: 55%;
  top: 90%;
  transform: translate(-50%, -50%) rotate(-10deg);
  display: block;
  pointer-events: none;
}

.instruction-screen__green {
  font-family: "Gotham Rounded", "FZCuYuan";
  font-size: 46px;
  font-weight: 700;
  // letter-spacing: 0.04em;
  color: var(--accent-strong);
}
</style>
