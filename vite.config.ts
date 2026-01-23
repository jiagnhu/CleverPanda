import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import pxtorem from 'postcss-pxtorem';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  css: {
    postcss: {
      plugins: [
        tailwindcss(),
        autoprefixer(),
        pxtorem({
          rootValue: 16,
          propList: ['*'],
          unitPrecision: 5,
          minPixelValue: 1,
          replace: true,
          mediaQuery: false
        })
      ]
    }
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true
      }
    }
  },
  define: {
    __BUILD_TIME__: JSON.stringify(new Date().toISOString())
  }
});
