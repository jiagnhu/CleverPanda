<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import FullScreenBurst from '@/components/FullScreenBurst.vue';
import { fetchBookContent, getBookEntry } from '@/data/books';

const route = useRoute();
const router = useRouter();

const bookId = computed(() => String(route.params.bookId || ''));
const ctaLabel = ref('Start reading');
const ctaSubtitle = ref('');

const goToTitle = () => {
  if (!bookId.value) {
    void router.replace({ path: '/' });
    return;
  }
  void router.push({ name: 'book-title', params: { bookId: bookId.value } });
};

onMounted(async () => {
  if (!bookId.value) {
    void router.replace({ path: '/' });
    return;
  }

  const bookEntry = await getBookEntry(bookId.value);
  const firstChapter = bookEntry?.chapters.find((chapter) => chapter.available !== false) ?? bookEntry?.chapters[0];
  if (!firstChapter) {
    void router.replace({ path: '/' });
    return;
  }

  const payload = await fetchBookContent(firstChapter.contentUrl);
  const titleEnglish = payload?.titleEnglish?.trim() || '';
  const titleMandarin = payload?.titleMandarin?.trim() || '';

  if (titleEnglish) {
    ctaLabel.value = `Start ${titleEnglish}`;
  }
  if (titleMandarin) {
    ctaSubtitle.value = `开始${titleMandarin}`;
  }
});
</script>

<template>
  <section class="screen success-screen" aria-label="Success">
    <FullScreenBurst />
    <div class="success-screen__content">
      <div class="success-screen__animation" aria-hidden="true">
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
      <h1 class="success-screen__title">一起阅读真是太棒了！</h1>
      <p class="success-screen__subtitle">准备好来一场真正的冒险了吗？</p>
      <button class="success-screen__cta" type="button" @click="goToTitle">
        <!-- <span class="success-screen__cta-main">{{ ctaLabel }}</span> -->
        <span v-if="ctaSubtitle" class="success-screen__cta-sub">{{ ctaSubtitle }}</span>
      </button>
    </div>
  </section>
</template>

<style scoped lang="less">
.success-screen {
  background: var(--bg);
  color: var(--accent-strong);
  overflow: hidden;
  position: relative;
}

.success-screen__content {
  width: min(90vw, 360px);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  text-align: center;
  position: relative;
  z-index: 3;
}

.success-screen__animation {
  width: min(82vw, 300px);
  margin-bottom: 8px;
  height: 170px;
}

.success-screen__panda {
  width: 72%;
  margin: 10px auto 0;
  display: block;
  position: relative;
  z-index: 1;
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
  font-size: 30px;
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
  height: 45px;
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
