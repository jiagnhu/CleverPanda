<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ReadingPage from '@/pages/ReadingPage.vue';
import { getBookChapterEntry, getBookEntry } from '@/data/books';

const route = useRoute();
const router = useRouter();

const bookId = computed(() => String(route.params.bookId || ''));
const chapterNo = computed(() => Number(route.params.chapterNo || 1));
const contentUrl = ref('');
const nextChapterNo = ref<number | null>(null);
const nextChapterLabel = computed(() =>
  nextChapterNo.value ? `下一章（第${nextChapterNo.value}章）` : '下一章'
);

const goTitlePage = () => {
  if (!bookId.value) {
    void router.replace({ path: '/' });
    return;
  }
  void router.push({
    name: 'book-title',
    params: { bookId: bookId.value }
  });
};

const goNextChapter = () => {
  if (!bookId.value || !nextChapterNo.value) return;
  void router.push({
    name: 'book-reading',
    params: {
      bookId: bookId.value,
      chapterNo: String(nextChapterNo.value)
    }
  });
};

const goChapterList = () => {
  goTitlePage();
};

const loadChapterMeta = async () => {
  if (!bookId.value) {
    void router.replace({ path: '/' });
    return;
  }

  const bookEntry = await getBookEntry(bookId.value);
  const chapterEntry = await getBookChapterEntry(bookId.value, chapterNo.value);
  if (!chapterEntry?.contentUrl || chapterEntry.available === false) {
    void router.replace({ path: '/' });
    return;
  }

  if (bookEntry?.chapters?.length) {
    const next = bookEntry.chapters
      .filter((chapter) => chapter.available !== false && chapter.number > chapterNo.value)
      .sort((a, b) => a.number - b.number)[0];
    nextChapterNo.value = next?.number ?? null;
  } else {
    nextChapterNo.value = null;
  }

  contentUrl.value = chapterEntry.contentUrl;
};

watch(
  [bookId, chapterNo],
  () => {
    contentUrl.value = '';
    nextChapterNo.value = null;
    void loadChapterMeta();
  },
  { immediate: true }
);
</script>

<template>
  <ReadingPage
    v-if="contentUrl"
    :active="true"
    :content-url="contentUrl"
    :book-id="bookId"
    :chapter-no="chapterNo"
    :show-next-chapter-button="nextChapterNo !== null"
    :next-chapter-label="nextChapterLabel"
    :show-back-to-chapters-button="nextChapterNo === null"
    @edge-prev="goTitlePage"
    @next-chapter="goNextChapter"
    @exit-home="goChapterList"
  />
  <section v-else class="screen reading-fallback">
    <p class="reading-fallback__text">Content not found.</p>
  </section>
</template>

<style scoped lang="less">
.reading-fallback {
  background: var(--bg);
}

.reading-fallback__text {
  margin: 0;
  color: var(--accent-strong);
}
</style>
