import { createRouter, createWebHistory } from 'vue-router';
const routes = [
  { path: '/', name: 'module', component: () => import('@/pages/SwipeModulePageMvp.vue') },
  { path: '/mvp', name: 'module-mvp', component: () => import('@/pages/SwipeModulePage.vue') },
  { path: '/legacy', name: 'module-legacy', component: () => import('@/pages/SwipeModulePage.vue') },
  {
    path: '/books/:bookId/success',
    name: 'book-success',
    component: () => import('@/pages/SuccessPage.vue')
  },
  {
    path: '/books/:bookId/title',
    name: 'book-title',
    component: () => import('@/pages/BookTitlePage.vue')
  },
  {
    path: '/books/:bookId/chapters/:chapterNo',
    name: 'book-reading',
    component: () => import('@/pages/BookReadingPage.vue')
  }
];

const router = createRouter({
  history: createWebHistory(),
  routes
});

export default router;
