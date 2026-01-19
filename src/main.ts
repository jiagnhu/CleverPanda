import { createApp } from 'vue';
import App from '@/App.vue';
import '@/style.css';
import router from '@/router';

createApp(App).use(router).mount('#app');

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    const buildStamp = typeof __BUILD_TIME__ === 'string' ? __BUILD_TIME__ : '';
    const cacheBust = buildStamp ? `?v=${encodeURIComponent(buildStamp)}` : '';
    navigator.serviceWorker.register(`/sw.js${cacheBust}`).catch(() => {});
  });
}

if (typeof __BUILD_TIME__ === 'string') {
  console.info(`[build] ${__BUILD_TIME__}`);
}
