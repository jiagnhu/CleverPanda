<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ReadingPage from '@/pages/ReadingPage.vue';
import { getBookEntry, DEMO_BOOK_ID } from '@/data/books';

const route = useRoute();
const router = useRouter();

const bookId = computed(() => String(route.params.bookId || DEMO_BOOK_ID));
const chapterNo = computed(() => Number(route.params.chapterNo || 1));
const bookEntry = computed(() => getBookEntry(bookId.value));
const contentUrl = computed(() => {
  if (!bookEntry.value) return '';
  if (chapterNo.value !== bookEntry.value.chapterNumber) return '';
  return bookEntry.value.chapterUrl;
});

onMounted(() => {
  if (!bookEntry.value || !contentUrl.value) {
    void router.replace({ path: '/' });
  }
});
</script>

<template>
  <ReadingPage
    v-if="contentUrl"
    :active="true"
    :content-url="contentUrl"
    :show-chapter-header-on-first-page="true"
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
