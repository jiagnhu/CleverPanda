<script setup lang="ts">
const emit = defineEmits<{
  (e: 'next'): void;
}>();

const onStart = () => {
  emit('next');
};
</script>

<template>
  <section class="screen start-screen" aria-label="Intro">
    <div class="start-screen__content">
      <div class="start-screen__intro">
        <p class="start-screen__line">名词为这世间万物命名。</p>
        <p class="start-screen__line">它们是让故事变得真实可感的词语。</p>
      </div>
    </div>
    <div class="start-screen__footer">
      <button class="start-screen__cta" type="button" @click="onStart">
        <span class="start-screen__cta-main">GO</span>
        <span class="start-screen__cta-sub">立即体验</span>
      </button>
      <div class="start-screen__footer-text">
        <p class="start-screen__line">Nouns give names to the world around us.</p>
        <p class="start-screen__line">They are the words that make stories feel real.</p>
      </div>
    </div>
  </section>
</template>

<style scoped lang="less">
.start-screen {
  background: radial-gradient(circle at 18% 15%, #f7f9b8 0%, #d7e57c 45%, #a4c737 100%);
  color: var(--accent-strong);
  justify-content: flex-start;
}

.start-screen__content {
  margin-top: 30vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 28px;
}

.start-screen__intro {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  line-height: 1.7;
  letter-spacing: 0.05em;
  font-family: "FZCuYuan", "Gotham Rounded";
}

.start-screen__line {
  margin: 0;
}

.start-screen__cta {
  border: none;
  border-radius: 999px;
  padding: 8px 22px;
  margin-bottom: 40px;
  background: #f2fb9c;
  color: var(--accent-strong);
  font-weight: 700;
  font-size: 18px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  box-shadow: 0 8px 0 #7eb034;
  cursor: pointer;
  transition: transform 120ms ease, box-shadow 120ms ease;
}

.start-screen__cta:active {
  transform: translateY(2px);
  box-shadow: 0 6px 0 #6e9a2d;
}

.start-screen__cta-main {
  font-size: 28px;
  font-family: "Gotham Rounded", "FZCuYuan";
}

.start-screen__cta-sub {
  font-size: 16px;
  font-weight: 700;
  font-family: "FZCuYuan", "Gotham Rounded";
}

.start-screen__footer {
  margin: 0;
  margin-top: auto;
  padding-bottom: 12px;
}

.start-screen__footer-text {
  font-size: 12px;
  line-height: 1.6;
  color: rgba(255, 255, 255, 0.75);
  font-family: "Gotham Rounded", "FZCuYuan";
}
</style>
