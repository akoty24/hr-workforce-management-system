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
  // changeOrigin: false keeps the browser's Host header (acme.localhost:5173),
  // which is how Laravel picks the company in subdomain mode.
  server: {
    // On Windows the native file watcher sometimes misses quick successive
    // saves and keeps serving a stale module; polling is reliable there.
    watch: process.platform === 'win32' ? { usePolling: true, interval: 300 } : undefined,
    proxy: {
      '/api': { target: backend, changeOrigin: false },
      '/health': { target: backend, changeOrigin: false },
      '/up': { target: backend, changeOrigin: false },
    },
  },
}))
