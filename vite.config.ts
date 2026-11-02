/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [react()],
  test: {
    // Only the dashboard; abqc/ and mcp/ have their own test runners
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
    exclude: ['**/node_modules/**', 'abqc/**', 'mcp/**', 'vendor/**', 'server/**', 'var/**'],
    environment: 'jsdom',
    passWithNoTests: true
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
      '@dashboard': resolve(__dirname, 'src/Dashboard'),
      '@components': resolve(__dirname, 'src/Dashboard/components'),
      '@pages': resolve(__dirname, 'src/Dashboard/pages'),
      '@hooks': resolve(__dirname, 'src/Dashboard/hooks'),
      '@utils': resolve(__dirname, 'src/Dashboard/utils'),
      '@types': resolve(__dirname, 'src/Dashboard/types'),
      '@stores': resolve(__dirname, 'src/Dashboard/stores')
    }
  },
  server: {
    port: 3000,
    open: true,
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      }
    }
  },
  build: {
    outDir: 'dist',
    sourcemap: true,
    rollupOptions: {
      output: {
        // Vite 8 (Rolldown) requires manualChunks to be a function
        manualChunks(id: string) {
          if (!id.includes('node_modules')) return undefined;
          const chunks: Record<string, string[]> = {
            vendor: ['/react/', '/react-dom/', '/scheduler/'],
            ui: ['/@mui/material/', '/@mui/icons-material/'],
            charts: ['/recharts/', '/chart.js/', '/react-chartjs-2/'],
            utils: ['/lodash/', '/date-fns/', '/axios/']
          };
          for (const [name, pkgs] of Object.entries(chunks)) {
            if (pkgs.some((p) => id.includes(`/node_modules${p}`))) return name;
          }
          return undefined;
        }
      }
    }
  },
  optimizeDeps: {
    include: [
      'react',
      'react-dom',
      '@mui/material',
      '@mui/icons-material',
      'recharts',
      'chart.js',
      'react-chartjs-2',
      'framer-motion',
      'zustand',
      '@tanstack/react-query'
    ]
  }
}); 