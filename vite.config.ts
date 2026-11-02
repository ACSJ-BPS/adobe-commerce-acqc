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
    rolldownOptions: {
      output: {
        codeSplitting: {
          groups: [
            { name: 'vendor', test: /[\\/]node_modules[\\/](react|react-dom|scheduler)[\\/]/, priority: 20 },
            { name: 'utils', test: /[\\/]node_modules[\\/](lodash|date-fns|axios)[\\/]/, priority: 15 },
            { name: 'ui', test: /[\\/]node_modules[\\/](@mui[\\/]material|@mui[\\/]icons-material)[\\/]/, priority: 10 },
            { name: 'charts', test: /[\\/]node_modules[\\/](recharts|chart\.js|react-chartjs-2)[\\/]/, priority: 10 }
          ]
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