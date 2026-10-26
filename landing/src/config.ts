// Central links and flags for the landing page. Override at build time with PUBLIC_* env vars.
const env = import.meta.env

// Path the landing is served under (Astro `base`, LANDING_BASE at build time), always with a trailing slash: '/landing/'.
export const BASE: string = `${env.BASE_URL.replace(/\/+$/, '')}/`

// Absolute URL of the landing itself (canonical, og:url, JSON-LD): https://fundsflow.fun/landing/
export const SITE_URL: string = new URL(BASE, env.SITE).href

// Absolute URL of a file in public/ (og.png, ...), so OG tags work wherever the landing is served.
export const asset = (file: string) => new URL(file.replace(/^\//, ''), SITE_URL).href

// Where the Vue app lives (the site root). If the landing moves to its own domain, set the full URL,
// e.g. PUBLIC_APP_URL=https://fundsflow.fun/ (and PUBLIC_DOCS_URL=https://fundsflow.fun/docs/).
export const APP_URL: string = env.PUBLIC_APP_URL || '/'

// Starlight docs, served at /docs/ by the same container.
export const DOCS_URL: string = env.PUBLIC_DOCS_URL || '/docs/'

// Telegram bot: the Docker build passes VITE_TELEGRAM_BOT_USERNAME (the app's value) as this.
export const TELEGRAM_BOT_USERNAME: string = env.PUBLIC_TELEGRAM_BOT_USERNAME || 'funds_flow_bot'
export const TELEGRAM_BOT_URL = `https://t.me/${TELEGRAM_BOT_USERNAME}`

export const GITHUB_URL = 'https://github.com/Loafer19/FundsFlow'
export const MCP_ENDPOINT = 'https://mcp.fundsflow.fun/mcp'
export const FEEDBACK_URL = 'https://t.me/Loafer19'

// Signed-in visitors (localStorage 'token' set by the app) skip the landing and go straight to the app.
// Off: the landing is a separate page (/landing/) and the app at / doesn't send anyone here, so there's nothing to skip.
// When enabled this renders the page's only inline <script>; the Docker build adds its hash to the CSP automatically.
export const REDIRECT_LOGGED_IN: boolean = env.PUBLIC_REDIRECT_LOGGED_IN === 'true'

// Shown in the footer. Placeholder until releases are versioned.
export const VERSION: string = env.PUBLIC_VERSION || '2.0'

export const docs = (path = '') => `${DOCS_URL.replace(/\/$/, '')}/${path.replace(/^\//, '')}`
