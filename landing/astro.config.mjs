// @ts-check
import sitemap from '@astrojs/sitemap'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'astro/config'

// Marketing page, served by the frontend Caddy container at https://fundsflow.fun/landing/
// (see docker/frontend and docker/Caddyfile.frontend), next to the Vue app at / and the docs at /docs/.
// LANDING_SITE / LANDING_BASE move it elsewhere at build time (e.g. its own domain: LANDING_BASE=/);
// a different base also needs the /landing routes in docker/Caddyfile.frontend changed.
const site = process.env.LANDING_SITE || 'https://fundsflow.fun'
const base = process.env.LANDING_BASE || '/landing'

export default defineConfig({
    site,
    base,
    output: 'static',
    // Caddy redirects /landing to /landing/, so every URL (canonical, sitemap) ends with a slash.
    trailingSlash: 'always',
    // External CSS only (no inline <style>), keeps the CSP style-src 'self'.
    build: { inlineStylesheets: 'never' },
    image: { layout: 'constrained' },
    integrations: [sitemap()],
    vite: { plugins: [tailwindcss()] },
})
