import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { execSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import process from 'node:process'
import { defineConfig } from 'vite'

// module NodeNext tidak mengizinkan import JSON langsung — baca manual.
const pkg = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf-8')) as {
  version: string
}

// Diperbarui: tanggal commit terakhir bila repo git tersedia (dev), fallback tanggal build (image produksi mengecualikan .git).
let commitDate = ''
let commitHash = ''
try {
  commitDate = execSync('git log -1 --format=%cI', { encoding: 'utf-8' }).trim()
  commitHash = execSync('git rev-parse --short HEAD', { encoding: 'utf-8' }).trim()
} catch {
  commitDate = new Date().toISOString()
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  define: {
    __APP_VERSION__: JSON.stringify(pkg.version),
    __APP_UPDATED__: JSON.stringify(commitDate),
    __APP_COMMIT__: JSON.stringify(commitHash),
  },
  server: {
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
