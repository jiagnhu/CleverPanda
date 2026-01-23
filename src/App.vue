<script setup lang="ts">
import { onMounted } from 'vue';
import SurveyModal from '@/components/SurveyModal.vue';
import { initSurveySource } from '@/utils/surveySource';

onMounted(() => {
  initSurveySource();
});
</script>

<template>
  <RouterView />
  <SurveyModal />
</template>
