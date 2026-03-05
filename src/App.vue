<script setup lang="ts">
import { onMounted } from 'vue';
import SurveyModal from '@/components/SurveyModal.vue';
import { initSurveySource } from '@/utils/surveySource';
import { trackReturnVisitOnOpen } from '@/tracking/behaviorTracker';

onMounted(() => {
  initSurveySource();
  void trackReturnVisitOnOpen();
});
</script>

<template>
  <RouterView />
  <SurveyModal />
</template>
