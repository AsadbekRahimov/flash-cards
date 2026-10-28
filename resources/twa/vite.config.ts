import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

// Vite config for the LexiFlow Telegram Web App.
// Output is emitted to ../../public/twa so Nginx can serve it from /twa/
// with SPA fallback (see docker/nginx/default.conf).
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  base: '/twa/',
  build: {
    outDir: fileURLToPath(new URL('../../public/twa', import.meta.url)),
    emptyOutDir: true,
    sourcemap: false,
    target: 'es2020',
    rollupOptions: {
      output: {
        // Hashed asset filenames so /twa/assets/* can be long-cached safely.
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
        manualChunks(id) {
          // The vendored icon paths (~11 KB gzip) almost never change: a chunk of their own stays in the browser
          // cache across releases instead of being re-downloaded inside the entry chunk each time.
          return id.replace(/\\/g, '/').endsWith('/src/components/ui/icons.ts') ? 'icons' : undefined
        },
      },
    },
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    host: true,
  },
  test: {
    environment: 'happy-dom',
    globals: true,
  },
})
