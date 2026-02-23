<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { getBookEntry, DEMO_BOOK_ID } from '@/data/books';

const route = useRoute();
const router = useRouter();

const bookId = computed(() => String(route.params.bookId || DEMO_BOOK_ID));
const bookEntry = computed(() => getBookEntry(bookId.value));

const goToTitle = () => {
  if (!bookEntry.value) {
    void router.replace({ path: '/' });
    return;
  }
  void router.push({ name: 'book-title', params: { bookId: bookEntry.value.bookId } });
};
</script>

<template>
  <section class="screen success-screen" aria-label="Success">
    <div class="success-screen__content">
      <div class="success-screen__animation" aria-hidden="true">
        <svg class="success-screen__confetti" viewBox="0 0 300 140">
          <g class="confetti-group confetti-group--a">
            <circle cx="46" cy="28" r="6" />
            <rect x="86" y="18" width="10" height="10" rx="2" />
            <rect x="120" y="34" width="12" height="12" rx="2" />
          </g>
          <g class="confetti-group confetti-group--b">
            <circle cx="250" cy="24" r="6" />
            <rect x="214" y="16" width="12" height="12" rx="2" />
            <rect x="176" y="30" width="10" height="10" rx="2" />
          </g>
        </svg>
        <svg class="success-screen__panda" viewBox="0 0 220 180">
          <circle cx="70" cy="52" r="22" class="panda-ear" />
          <circle cx="150" cy="52" r="22" class="panda-ear" />
          <ellipse cx="110" cy="96" rx="66" ry="58" class="panda-face" />
          <ellipse cx="84" cy="92" rx="14" ry="18" class="panda-eye-patch" />
          <ellipse cx="136" cy="92" rx="14" ry="18" class="panda-eye-patch" />
          <circle cx="84" cy="94" r="5" class="panda-eye" />
          <circle cx="136" cy="94" r="5" class="panda-eye" />
          <ellipse cx="110" cy="114" rx="8" ry="6" class="panda-nose" />
          <path d="M 96 128 Q 110 140 124 128" class="panda-mouth" />
          <path d="M 54 124 Q 34 130 22 146" class="panda-arm panda-arm--left" />
          <path d="M 166 124 Q 186 130 198 146" class="panda-arm panda-arm--right" />
        </svg>
      </div>
      <h1 class="success-screen__title">Great reading together!</h1>
      <p class="success-screen__subtitle">Ready for a real adventure?</p>
      <button class="success-screen__cta" type="button" @click="goToTitle">
        <span class="success-screen__cta-main">{{
          bookEntry?.ctaLabel || "Start Alice's Adventures in Wonderland"
        }}</span>
        <span v-if="bookEntry?.ctaSubtitle" class="success-screen__cta-sub">
          {{ bookEntry.ctaSubtitle }}
        </span>
      </button>
    </div>
  </section>
</template>

<style scoped lang="less">
.success-screen {
  background: var(--bg);
  color: var(--accent-strong);
  overflow: hidden;
}

.success-screen__content {
  width: min(90vw, 360px);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  text-align: center;
}

.success-screen__animation {
  width: min(82vw, 300px);
  position: relative;
  margin-bottom: 8px;
}

.success-screen__confetti {
  width: 100%;
  display: block;
}

.confetti-group {
  fill: #7fc92f;
  transform-origin: center;
}

.confetti-group--a {
  animation: confettiFloatA 1.8s ease-in-out infinite alternate;
}

.confetti-group--b {
  animation: confettiFloatB 1.9s ease-in-out infinite alternate;
}

.success-screen__panda {
  width: 72%;
  margin: -10px auto 0;
  display: block;
  animation: pandaBob 2s ease-in-out infinite;
}

.panda-ear,
.panda-eye-patch,
.panda-eye,
.panda-nose,
.panda-arm {
  fill: var(--accent-strong);
}

.panda-face {
  fill: #f8f6e6;
}

.panda-mouth {
  stroke: var(--accent-strong);
  stroke-width: 5;
  fill: none;
  stroke-linecap: round;
}

.panda-arm--left {
  transform-origin: 54px 124px;
  animation: waveLeft 1.2s ease-in-out infinite;
}

.panda-arm--right {
  transform-origin: 166px 124px;
  animation: waveRight 1.4s ease-in-out infinite;
}

.success-screen__title {
  margin: 0;
  font-size: 34px;
  line-height: 1.2;
  font-family: "Gotham Rounded", "FZCuYuan";
  font-weight: 700;
}

.success-screen__subtitle {
  margin: 0;
  font-size: 24px;
  color: var(--accent);
  font-family: "Gotham Rounded", "FZCuYuan";
}

.success-screen__cta {
  margin-top: 12px;
  border: 0;
  border-radius: 24px;
  padding: 14px 18px 12px;
  background: var(--accent-strong);
  color: #f8f6e6;
  font-size: 17px;
  line-height: 1.25;
  font-weight: 600;
  font-family: "Gotham Rounded", "FZCuYuan";
  box-shadow: 0 8px 20px rgba(var(--accent-rgb), 0.25);
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.success-screen__cta:active {
  transform: translateY(1px);
}

.success-screen__cta-main {
  font-size: 16px;
  font-weight: 700;
}

.success-screen__cta-sub {
  font-size: 13px;
  opacity: 0.92;
  letter-spacing: 0.02em;
}

@keyframes confettiFloatA {
  0% {
    transform: translateY(0) rotate(-2deg);
  }
  100% {
    transform: translateY(8px) rotate(6deg);
  }
}

@keyframes confettiFloatB {
  0% {
    transform: translateY(3px) rotate(2deg);
  }
  100% {
    transform: translateY(-6px) rotate(-5deg);
  }
}

@keyframes pandaBob {
  0% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-4px);
  }
  100% {
    transform: translateY(0);
  }
}

@keyframes waveLeft {
  0% {
    transform: rotate(0deg);
  }
  50% {
    transform: rotate(-9deg);
  }
  100% {
    transform: rotate(0deg);
  }
}

@keyframes waveRight {
  0% {
    transform: rotate(0deg);
  }
  50% {
    transform: rotate(8deg);
  }
  100% {
    transform: rotate(0deg);
  }
}
</style>
