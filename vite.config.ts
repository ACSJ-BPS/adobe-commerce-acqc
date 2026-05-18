import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [react()],
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
        manualChunks: {
          vendor: ['react', 'react-dom'],
          ui: ['@mui/material', '@mui/icons-material'],
          charts: ['recharts', 'chart.js', 'react-chartjs-2'],
          utils: ['lodash', 'date-fns', 'axios']
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