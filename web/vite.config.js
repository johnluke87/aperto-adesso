import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Built straight into the project root, served by XAMPP at /aperto-adesso/.
export default defineConfig({
  base: '/aperto-adesso/',
  plugins: [react()],
  build: {
    outDir: '..',
    emptyOutDir: false,
    assetsDir: 'assets',
  },
  server: {
    proxy: {
      '/aperto-adesso/api': 'http://localhost',
    },
  },
})
