<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { getBookEntry, DEMO_BOOK_ID } from '@/data/books';

type BookMetaPayload = {
  titleEnglish?: string;
  titleMandarin?: string;
};

const route = useRoute();
const router = useRouter();

const bookId = computed(() => String(route.params.bookId || DEMO_BOOK_ID));
const bookEntry = computed(() => getBookEntry(bookId.value));
const titleEnglish = ref("Alice's Adventures in Wonderland");
const titleMandarin = ref('爱丽丝梦游仙境');
const loading = ref(false);

const goReading = () => {
  if (!bookEntry.value) {
    void router.replace({ path: '/' });
    return;
  }
  void router.push({
    name: 'book-reading',
    params: {
      bookId: bookEntry.value.bookId,
      chapterNo: String(bookEntry.value.chapterNumber)
    }
  });
};

onMounted(async () => {
  if (!bookEntry.value) {
    void router.replace({ path: '/' });
    return;
  }
  loading.value = true;
  try {
    const response = await fetch(bookEntry.value.chapterUrl, { cache: 'no-store' });
    if (!response.ok) return;
    const payload = (await response.json()) as BookMetaPayload;
    if (payload.titleEnglish) titleEnglish.value = payload.titleEnglish;
    if (payload.titleMandarin) titleMandarin.value = payload.titleMandarin;
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section
    class="screen title-screen"
    aria-label="Book title"
    role="button"
    tabindex="0"
    @click="goReading"
    @keydown.enter.prevent="goReading"
    @keydown.space.prevent="goReading"
  >
    <div class="title-screen__content">
      <p class="title-screen__label">Chapter 1</p>
      <h1 class="title-screen__en">{{ titleEnglish }}</h1>
      <p class="title-screen__zh">{{ titleMandarin }}</p>
      <p class="title-screen__hint">{{ loading ? 'Loading...' : 'Tap anywhere to begin Chapter 1' }}</p>
    </div>
  </section>
</template>

<style scoped lang="less">
.title-screen {
  background: var(--bg);
  color: var(--accent-strong);
  cursor: pointer;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
}

.title-screen__content {
  width: min(90vw, 360px);
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 14px;
}

.title-screen__label {
  margin: 0;
  font-size: 14px;
  color: var(--accent);
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.title-screen__en {
  margin: 0;
  font-size: 34px;
  line-height: 1.2;
  font-weight: 700;
  font-family: "Gotham Rounded", "FZCuYuan";
}

.title-screen__zh {
  margin: 0;
  font-size: 28px;
  color: var(--accent);
  font-family: "FZCuYuan", "Gotham Rounded";
}

.title-screen__hint {
  margin: 6px 0 0;
  color: var(--accent);
  font-size: 15px;
  line-height: 1.4;
  opacity: 0.92;
}
</style>
