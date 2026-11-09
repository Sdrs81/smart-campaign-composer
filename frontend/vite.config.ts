import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  server: {
    // The API is only reachable through the compose `web` container (nginx on :8080); this lets
    // `npm run dev` call /api the same way the built app does. Its nginx also keeps SSE unbuffered.
    proxy: { '/api': 'http://localhost:8080' },
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})
