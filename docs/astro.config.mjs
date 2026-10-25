// @ts-check
import starlight from '@astrojs/starlight'
import { defineConfig } from 'astro/config'

// Served by the frontend nginx container at https://fundsflow.fun/docs/
// (see docker/frontend and docker/nginx.conf).
export default defineConfig({
    site: 'https://fundsflow.fun',
    base: '/docs',
    trailingSlash: 'ignore',
    // Responsive srcset for <Image> (the splash hero is 400px wide, so 2x screens get the 800px variant).
    image: { layout: 'constrained' },
    integrations: [
        starlight({
            title: 'FundsFlow Docs',
            description:
                'How to use FundsFlow: the web app, Telegram bot, Mini App, and MCP server for AI assistants.',
            logo: { src: './src/assets/logo-header.webp', alt: 'FundsFlow' },
            favicon: '/favicon-32.png',
            head: [
                // Sharper tab/bookmark icon on high-DPI screens (generated from frontend/public/logo-hires.png).
                { tag: 'link', attrs: { rel: 'icon', type: 'image/png', sizes: '96x96', href: '/docs/favicon-96.png' } },
                { tag: 'link', attrs: { rel: 'apple-touch-icon', href: '/docs/apple-touch-icon.png', sizes: '180x180' } },
                { tag: 'meta', attrs: { property: 'og:image', content: 'https://fundsflow.fun/og.png' } },
            ],
            customCss: ['./src/styles/brand.css'],
            sidebar: [
                {
                    label: 'Getting started',
                    items: [
                        { slug: 'getting-started/introduction' },
                        { slug: 'getting-started/sign-in' },
                        { slug: 'getting-started/first-steps' },
                    ],
                },
                {
                    label: 'Telegram bot',
                    items: [
                        { slug: 'telegram/overview' },
                        { slug: 'telegram/quick-add' },
                        { slug: 'telegram/receipts' },
                        { slug: 'telegram/voice' },
                        { slug: 'telegram/ai-assistant' },
                        { slug: 'telegram/commands' },
                        { slug: 'telegram/notifications' },
                    ],
                },
                { label: 'Mini App', items: [{ slug: 'mini-app' }] },
                {
                    label: 'Web app',
                    items: [
                        { slug: 'web/overview' },
                        { slug: 'web/transactions' },
                        { slug: 'web/tabs' },
                        { slug: 'web/tags' },
                        { slug: 'web/budgets' },
                        { slug: 'web/recurring' },
                        { slug: 'web/settings' },
                        { slug: 'web/reports' },
                    ],
                },
                {
                    label: 'AI & MCP',
                    items: [{ slug: 'ai/mcp' }, { slug: 'ai/connect-clients' }, { slug: 'ai/tools' }],
                },
                {
                    label: 'Help',
                    items: [{ slug: 'help/faq' }, { slug: 'help/privacy' }],
                },
            ],
        }),
    ],
})
