import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/main.jsx'],
      refresh: true,
    }),
    tailwindcss(),
    react(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './resources/js'),
    },
  },
  server: {
    host: '127.0.0.1',
    port: 3000,
  },
})
