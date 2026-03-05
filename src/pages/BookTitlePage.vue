<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  collectContentChapters,
  fetchBookContent,
  getBookEntry,
  type BookCatalogChapterRef,
  type BookContentChapter
} from '@/data/books';

type ChapterItem = {
  number: number;
  titleEnglish: string;
  titleMandarin: string;
  totalPages: number | null;
  available: boolean;
};

const route = useRoute();
const router = useRouter();

const bookId = computed(() => String(route.params.bookId || ''));
const titleEnglish = ref('');
const titleMandarin = ref('');
const loading = ref(false);
const chapters = ref<ChapterItem[]>([]);

const normalizeChapterItem = (
  chapterRef: BookCatalogChapterRef,
  chapterMeta: BookContentChapter | undefined
): ChapterItem => ({
  number:
    typeof chapterMeta?.number === 'number'
      ? chapterMeta.number
      : chapterRef.number,
  titleEnglish: chapterMeta?.titleEnglish?.trim() || `Chapter ${chapterRef.number}`,
  titleMandarin: chapterMeta?.titleMandarin?.trim() || '',
  totalPages:
    typeof chapterMeta?.totalPages === 'number'
      ? chapterMeta.totalPages
      : Array.isArray(chapterMeta?.pages)
        ? chapterMeta.pages.length
        : null,
  available: chapterRef.available !== false
});

const goReading = (chapterNo: number) => {
  const chapter = chapters.value.find((item) => item.number === chapterNo);
  if (!bookId.value) {
    void router.replace({ path: '/' });
    return;
  }
  if (!chapter?.available) return;
  void router.push({
    name: 'book-reading',
    params: {
      bookId: bookId.value,
      chapterNo: String(chapterNo)
    }
  });
};

onMounted(async () => {
  if (!bookId.value) {
    void router.replace({ path: '/' });
    return;
  }

  loading.value = true;
  try {
    const bookEntry = await getBookEntry(bookId.value);
    if (!bookEntry || !bookEntry.chapters.length) {
      void router.replace({ path: '/' });
      return;
    }

    const payloads = await Promise.all(
      bookEntry.chapters.map(async (chapter) => ({
        chapter,
        payload: await fetchBookContent(chapter.contentUrl)
      }))
    );

    const firstPayload = payloads.find((item) => item.payload)?.payload ?? null;
    if (firstPayload?.titleEnglish) titleEnglish.value = firstPayload.titleEnglish;
    if (firstPayload?.titleMandarin) titleMandarin.value = firstPayload.titleMandarin;

    chapters.value = payloads.map(({ chapter, payload }) => {
      const chapterMeta = payload ? collectContentChapters(payload)[0] : undefined;
      return normalizeChapterItem(chapter, chapterMeta);
    });
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section class="screen title-screen" aria-label="Book chapters">
    <div class="title-screen__content">
      <h1 class="title-screen__en">{{ titleEnglish }}</h1>
      <p class="title-screen__zh">{{ titleMandarin }}</p>

      <div v-if="chapters.length > 0" class="chapter-list" role="list" aria-label="Chapter list">
        <button
          v-for="chapter in chapters"
          :key="chapter.number"
          class="chapter-item"
          :class="{ 'chapter-item--disabled': !chapter.available }"
          type="button"
          :disabled="!chapter.available"
          @click="goReading(chapter.number)"
        >
          <div class="chapter-item__line chapter-item__line--en">
            <p class="chapter-item__index">Chapter {{ chapter.number }}</p>
            <p class="chapter-item__title-en">{{ chapter.titleEnglish }}</p>
          </div>
          <span class="chapter-item__line chapter-item__line--zh">
            <span v-if="chapter.titleMandarin" class="chapter-item__title-zh">{{ chapter.titleMandarin }}</span>
          </span>
          <span v-if="!chapter.available" class="chapter-item__status">Coming soon</span>
        </button>
      </div>
      <p class="title-screen__hint">
        {{ loading ? 'Loading...' : chapters.length > 0 ? 'Select a chapter to begin' : 'No chapters available' }}
      </p>
    </div>
  </section>
</template>

<style scoped lang="less">
.title-screen {
  background: var(--bg);
  color: var(--accent-strong);
  justify-content: flex-start;
  padding-top: calc(60px + env(safe-area-inset-top));
}

.title-screen__content {
  width: min(90vw, 360px);
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 12px;
}


.title-screen__en {
  margin: 0;
  font-size: 24px;
  line-height: 1.05;
  font-weight: 700;
  font-family: "Gotham Rounded", "FZCuYuan";
  max-width: 100%;
}

.title-screen__zh {
  margin: 0;
  font-size: 28px;
  color: var(--accent);
  font-family: "FZCuYuan", "Gotham Rounded";
  line-height: 1.16;
}

.chapter-list {
  width: 100%;
  margin-top: 10px;
  display: flex;
  flex-direction: column;
  gap: 0;
}

.chapter-item {
  width: 100%;
  border: 0;
  background: transparent;
  color: var(--accent-strong);
  text-align: center;
  padding: 14px 6px 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  border-bottom: 1px solid rgba(var(--accent-rgb), 0.18);
}

.chapter-item:first-of-type {
  border-top: 1px solid rgba(var(--accent-rgb), 0.18);
}

.chapter-item:active {
  background: rgba(var(--accent-rgb), 0.08);
}

.chapter-item__index {
  font-size: 14px;
  letter-spacing: 0.05em;
}

.chapter-item__title-en {
  font-size: 20px;
  line-height: 1.2;
  font-weight: 500;
  margin-left: 8px;
}

.chapter-item__title-zh {
  font-size: 18px;
  color: var(--accent);
}

.chapter-item__line {
  // display: flex;
  // align-items: baseline;
  // justify-content: center;
  // flex-wrap: wrap;
  gap: 0;
  line-height: 1.2;
}

.chapter-item__line--zh {
  margin-top: 2px;
}

.chapter-item__status {
  margin-top: 4px;
  font-size: 12px;
  font-weight: 700;
  color: #d68c00;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.chapter-item--disabled {
  opacity: 0.5;
}

.title-screen__hint {
  margin: 20px 0 0;
  color: var(--accent);
  font-size: 18px;
  line-height: 1.4;
  opacity: 0.9;
}
</style>
