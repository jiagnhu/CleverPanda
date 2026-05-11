<script setup lang="ts">
const emit = defineEmits<{ (e: 'start'): void }>();
</script>

<template>
  <section class="alice-title-page screen">
    <div class="alice-title-page__content">
      <p class="alice-title-page__subtitle">机灵小熊猫</p>
      <h1 class="alice-title-page__title-en">Alice's Adventures<br />in Wonderland</h1>
      <p class="alice-title-page__title-zh">爱丽丝梦游仙境</p>
      <div class="alice-title-page__divider" aria-hidden="true"></div>
      <p class="alice-title-page__sentence">
        Alice was beginning to get very tired of sitting by her sister on the river bank.
      </p>
    </div>
    <button type="button" class="alice-title-page__btn" @click="emit('start')">
      <span class="alice-title-page__btn-main">开始阅读</span>
      <span class="alice-title-page__btn-sub">Start Reading</span>
    </button>
  </section>
</template>

<style scoped lang="less">
.alice-title-page {
  background: #f0f9a0;
  // background: radial-gradient(circle at 18% 15%, #f7f9b8 0%, #d7e57c 45%, #a4c737 100%);
  color: var(--accent-strong);
  font-family: 'FZCuYuan', 'Gotham Rounded';
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0;
}

.alice-title-page__content {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 0 32px;
  gap: 10px;
}

.alice-title-page__subtitle {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.12em;
  opacity: 0.7;
}

.alice-title-page__title-en {
  margin: 0;
  font-family: 'Gotham Rounded', 'FZCuYuan';
  font-size: clamp(26px, 6vw, 34px);
  font-weight: 700;
  line-height: 1.25;
  letter-spacing: 0.02em;
}

.alice-title-page__title-zh {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  letter-spacing: 0.1em;
}

.alice-title-page__divider {
  width: 60%;
  border-top: 2px dashed rgba(var(--accent-rgb), 0.35);
  margin: 4px 0;
}

.alice-title-page__sentence {
  margin: 0;
  font-family: 'Gotham Rounded', 'FZCuYuan';
  font-size: 15px;
  font-weight: 400;
  line-height: 1.65;
  color: rgba(var(--accent-rgb), 0.75);
  max-width: 280px;
  font-style: italic;
}

.alice-title-page__btn {
  margin-top: 40px;
  border: none;
  border-radius: 999px;
  background: var(--accent-strong);
  color: #f8f6e6;
  padding: 14px 36px 13px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  box-shadow: 0 8px 20px rgba(var(--accent-rgb), 0.25);
  cursor: pointer;
  touch-action: manipulation;
  transition: transform 120ms ease, box-shadow 120ms ease;
}

.alice-title-page__btn:active {
  transform: translateY(2px);
  box-shadow: 0 4px 10px rgba(var(--accent-rgb), 0.2);
}

.alice-title-page__btn-main {
  font-family: 'FZCuYuan', 'Gotham Rounded';
  font-size: 18px;
  font-weight: 700;
  letter-spacing: 0.06em;
}

.alice-title-page__btn-sub {
  font-family: 'Gotham Rounded', 'FZCuYuan';
  font-size: 12px;
  font-weight: 500;
  opacity: 0.78;
}
</style>
