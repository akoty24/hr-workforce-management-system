import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Laravel dev server (php artisan serve).
const backend = process.env.BACKEND_URL ?? 'http://127.0.0.1:8000'

// https://vite.dev/config/
export default defineConfig(({ command }) => ({
  plugins: [react()],

  // Production: Laravel serves the build from backend/public/app (same origin).
  base: command === 'build' ? '/app/' : '/',
  build: {
    outDir: '../backend/public/app',
    emptyOutDir: true,
  },

  // Development: proxy API calls to Laravel so the browser sees one origin
  // (session cookies and CSRF work exactly as in production).
  server: {
    proxy: {
      '/api': backend,
      '/health': backend,
      '/up': backend,
    },
  },
}))
