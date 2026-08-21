import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Two ways to run the UI:
//
//  - Dev:   `npm run dev` on :5173, with /api proxied to Apache on :8888.
//           Proxying avoids adding CORS headers to PHP just for development.
//  - Built: `npm run build` emits into ../public, so Apache serves the app
//           and the API from a single origin and no proxy is involved.
export default defineConfig({
  plugins: [react()],
  // Relative asset URLs, so the build works under a subdirectory.
  base: './',
  build: {
    outDir: '../public',
    emptyOutDir: false,
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8888/Moodle Developer Coding Challenge - PHP/public',
        changeOrigin: true,
      },
    },
  },
})
