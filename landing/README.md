# FundsFlow landing

Astro site (static, zero client JS) for the marketing page at `https://fundsflow.fun/landing/`.
Routing on fundsflow.fun: Vue app at `/`, Starlight docs at `/docs/`, landing at `/landing/`.
The frontend image builds it like the docs (`landing` stage in `docker/frontend`) and Caddy serves it from
`/srv/landing` (`docker/Caddyfile.frontend`), so a Dokploy deploy needs no extra setup.

```bash
bun install
bun run dev      # http://localhost:4321/landing/
bun run build    # -> dist/ (static HTML + one CSS file + optimized logo webp)
bun run preview
```

## Config (`src/config.ts`, overridable with `PUBLIC_*` env at build time)

| Constant | Default | Notes |
| --- | --- | --- |
| Astro `site` / `base` (`LANDING_SITE`, `LANDING_BASE`, in `astro.config.mjs`) | `https://fundsflow.fun`, `/landing` | Canonical, `og:url`, `og:image`, sitemap and asset paths follow them. A different base also needs the `/landing` routes in `docker/Caddyfile.frontend` changed. |
| `APP_URL` (`PUBLIC_APP_URL`) | `/` | "Open app" CTAs. On a separate domain use the full URL, e.g. `https://fundsflow.fun/`. |
| `DOCS_URL` (`PUBLIC_DOCS_URL`) | `/docs/` | Same: full URL on a separate domain. |
| `TELEGRAM_BOT_USERNAME` (`PUBLIC_TELEGRAM_BOT_USERNAME`) | `funds_flow_bot` | The Docker build passes the app's `VITE_TELEGRAM_BOT_USERNAME`. |
| `REDIRECT_LOGGED_IN` (`PUBLIC_REDIRECT_LOGGED_IN=true`) | off | Renders the only inline script: if `localStorage.token` exists → `location.replace(APP_URL)`. The Docker build adds its hash to the CSP (`LANDING_SCRIPT_HASHES`). |
| `VERSION` (`PUBLIC_VERSION`) | `2.0` | Footer placeholder. |

## Notes

- Theme: DaisyUI 5 `bumblebee` (same overrides as `frontend/src/assets/css/main.css`) + a custom `fundsflow-dark`
  theme applied via `prefers-color-scheme: dark`.
- CSS is emitted as an external file (`inlineStylesheets: 'never'`) and there are no `style=""` attributes, so a strict
  CSP needs no `unsafe-inline`. `application/ld+json` is a data block, not executed.
- Product visuals: the Assistant section and the Features gallery use real web app screenshots with demo data (`src/assets/app-*-{light,dark}.webp`, rendered by `ThemedShot.astro`, which picks the variant by `prefers-color-scheme`). The Hero Telegram chat is still an HTML/CSS mock, marked `<!-- screenshot: ... -->`.
- FAQ copy is condensed from `docs/src/content/docs/help/faq.md`; keep in sync.
- `public/og.png`, `favicon-32.png`, `apple-touch-icon.png` and `src/assets/logo.png` are copies from `frontend/public`.
- No `robots.txt` here: robots is per host, so the app's `frontend/public/robots.txt` lists the landing sitemap
  (`/landing/sitemap-index.xml`). Add one if the landing gets its own domain.
