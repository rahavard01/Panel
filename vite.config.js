import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import pkg from './package.json' assert { type: 'json' }

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/app.js'],
      buildDirectory: 'build',      // خروجی همچنان public/build
      hotFile: 'public/hot',
      refresh: true,
    }),
    vue(),
  ],
  define: {
    __APP_VERSION__: JSON.stringify(pkg.version),
    __BUILD_TIME__: JSON.stringify(new Date().toISOString()),
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    origin: 'http://127.0.0.1:5173',
    hmr: { host: '127.0.0.1', port: 5173 },
    cors: true,
  },
})
