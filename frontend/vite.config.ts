import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Laravel dev server (php artisan serve).
const backend = process.env.BACKEND_URL ?? 'http://127.0.0.1:8000'

// https://vite.dev/config/
export default defineConfig(({ command }) => ({
  plugins: [react()],

  // Production: Laravel serves the build from backend/public/app (same origin).
  base: command === 'build' ? '/app/' : '/',
 
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// Laravel dev server (php artisan serve).
const backend = process.env.BACKEND_URL ?? 'http://127.0.0.1:8000'

// Detect Vercel deployment.
const isVercel = process.env.VERCEL === '1'

// https://vite.dev/config/
export default defineConfig(({ command }) => ({
  plugins: [react()],

  // Laravel uses /app/, while Vercel serves from the root.
  base: command === 'build' && !isVercel ? '/app/' : '/',

  build: {
    // Vercel expects frontend/dist.
    // Laravel expects backend/public/app.
    outDir: isVercel ? 'dist' : '../backend/public/app',
    emptyOutDir: true,
  },

  // Development: proxy API calls to Laravel.
  server: {
    watch: process.platform === 'win32'
      ? { usePolling: true, interval: 300 }
      : undefined,

    proxy: {
      '/api': { target: backend, changeOrigin: false },
      '/health': { target: backend, changeOrigin: false },
      '/up': { target: backend, changeOrigin: false },
    },
  },
}))
