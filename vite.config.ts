import { defineConfig, loadEnv } from 'vite';
import vue from '@vitejs/plugin-vue';
import pxtorem from 'postcss-pxtorem';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const devProxyTarget = env.VITE_DEV_API_PROXY_TARGET?.trim();

  return {
    plugins: [vue()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url))
      }
    },
    server: devProxyTarget
      ? {
          proxy: {
            /**
             * 本地开发时浏览器只请求同源 /api，
             * 由 Vite dev server 转发到真实 PHP 域名，从根上绕开浏览器 CORS。
             */
            '/api': {
              target: devProxyTarget,
              changeOrigin: true,
              secure: true
            }
          }
        }
      : undefined,
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
    define: {
      __BUILD_TIME__: JSON.stringify(new Date().toISOString())
    }
  };
});
