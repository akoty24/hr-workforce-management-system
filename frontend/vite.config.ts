
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

const backend = process.env.BACKEND_URL ?? 'http://127.0.0.1:8000'
const isVercel = process.env.VERCEL === '1'

export default defineConfig(({ command }) => {
  return {
    plugins: [react()],

    base: command === 'build' && !isVercel ? '/app/' : '/',

    build: {
      outDir: isVercel ? 'dist' : '../backend/public/app',
      emptyOutDir: true,
    },

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
  }
})
