<script setup lang="ts">
import { onMounted } from 'vue';
import { initAnalyticsAppSession } from '@/analytics/manager';
import SurveyModal from '@/components/SurveyModal.vue';
import { initSurveySource } from '@/utils/surveySource';

onMounted(() => {
  initSurveySource();
  initAnalyticsAppSession();
});
</script>

<template>
  <RouterView />
  <SurveyModal />
</template>
