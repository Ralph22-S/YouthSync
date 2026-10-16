import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: true,
    open: true,
    proxy: {
      // Same-origin in development so the PHP HttpOnly session cookie is
      // first-party. Cross-origin fetch to http://localhost/YouthSync/api
      // from :5173 is what made Chrome drop the session on refresh.
      '/YouthSync/api': {
        target: 'http://localhost',
        changeOrigin: true,
        secure: false,
      },
    },
  },
});
