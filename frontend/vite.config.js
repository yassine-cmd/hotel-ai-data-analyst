import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  base: process.env.VITE_BASE_PATH || '/',
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      '/sanctum': {
        target: 'http://127.0.0.1:80',
        changeOrigin: true
      },
      '/api': {
        target: 'http://127.0.0.1:80',
        changeOrigin: true
      }
    }
  }
});
