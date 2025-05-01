import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'
import compression from 'vite-plugin-compression'

export default defineConfig({
    plugins: [
        vue(),
        tailwindcss(),
        compression({
            algorithm: 'gzip',
        }),
    ],
})
