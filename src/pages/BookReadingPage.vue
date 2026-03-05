<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ReadingPage from '@/pages/ReadingPage.vue';
import { getBookChapterEntry } from '@/data/books';

const route = useRoute();
const router = useRouter();

const bookId = computed(() => String(route.params.bookId || ''));
const chapterNo = computed(() => Number(route.params.chapterNo || 1));
const contentUrl = ref('');

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

onMounted(async () => {
  if (!bookId.value) {
    void router.replace({ path: '/' });
    return;
  }

  const chapterEntry = await getBookChapterEntry(bookId.value, chapterNo.value);
  if (!chapterEntry?.contentUrl || chapterEntry.available === false) {
    void router.replace({ path: '/' });
    return;
  }

  contentUrl.value = chapterEntry.contentUrl;
});
</script>

<template>
  <ReadingPage
    v-if="contentUrl"
    :active="true"
    :content-url="contentUrl"
    :book-id="bookId"
    :chapter-no="chapterNo"
    @edge-prev="goTitlePage"
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
