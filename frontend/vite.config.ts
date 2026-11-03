/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  // A SPA é servida por um único catch-all do Laravel em `/`, mas os
  // arquivos vivem em `public/spa`. O `base` garante que o HTML gerado
  // referencie `/spa/assets/...` (sem ele, os assets dariam 404).
  base: '/spa/',
  // A SPA roda no MESMO domínio do Laravel:
  // - em dev, o proxy encaminha /api e /sanctum para o backend;
  // - o build vai para backend/public, servido pelo próprio Laravel.
  server: {
    port: 5173,
    proxy: {
      '/api': { target: 'http://localhost:8000', changeOrigin: true },
      '/sanctum': { target: 'http://localhost:8000', changeOrigin: true },
    },
  },
  build: {
    outDir: '../backend/public/spa',
    emptyOutDir: true,
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: './src/test/setup.ts',
    css: false,
  },
})
