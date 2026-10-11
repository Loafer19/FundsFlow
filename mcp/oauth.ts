import { createHash, randomBytes } from 'node:crypto'
import type { Express, Request, Response } from 'express'

const CLIENT_ID = process.env.OAUTH_CLIENT_ID || 'fundsflow'
const CLIENT_SECRET = process.env.OAUTH_CLIENT_SECRET || ''
const PUBLIC_BASE = (process.env.MCP_PUBLIC_URL || 'https://mcp.fundsflow.fun').replace(/\/$/, '')
const API_URL = (process.env.FUNDSFLOW_API_URL || 'https://api.fundsflow.fun/api').replace(/\/$/, '')
const CODE_TTL_MS = 5 * 60 * 1000

// Callbacks of the hosted MCP clients we know about. Exact match only: a prefix or
// host match would let any path on those hosts (or an open redirect there) receive codes.
// Sources are listed in README.md ("OAuth redirect URIs"). Loopback clients (Claude Code,
// Cursor desktop :8787, VS Code :33418, MCP Inspector, Windsurf...) are handled separately.
export const DEFAULT_REDIRECT_ALLOWLIST = [
    // Claude hosted apps (web, desktop, mobile, Cowork)
    'https://claude.ai/api/mcp/auth_callback',
    'https://claude.com/api/mcp/auth_callback',
    // ChatGPT / Codex: stable callback (per-connection form is matched below)
    'https://chatgpt.com/connector_platform_oauth_redirect',
    // Cursor web / Cursor Agents
    'https://www.cursor.com/agents/mcp/oauth/callback',
    // Cursor desktop, legacy custom-scheme callback still sent by some builds
    'cursor://anysphere.cursor-mcp/oauth/callback',
    // VS Code / GitHub Copilot (web redirector; desktop uses loopback)
    'https://vscode.dev/redirect',
    'https://insiders.vscode.dev/redirect',
    // Grok custom connectors (grok.com 308s the slash form to the bare one)
    'https://grok.com/connectors-oauth-exchange-code/',
    'https://grok.com/connectors-oauth-exchange-code',
]

// ChatGPT uses https://chatgpt.com/connector/oauth/{callback_id} when the authorization
// server does not return `iss` on the redirect (we don't). One opaque path segment only.
const CHATGPT_CALLBACK_ID = /^\/connector\/oauth\/[A-Za-z0-9_-]{1,128}$/

const REDIRECT_ALLOWLIST = new Set([
    ...DEFAULT_REDIRECT_ALLOWLIST,
    ...(process.env.OAUTH_REDIRECT_ALLOWLIST || '')
        .split(',')
        .map((v) => v.trim())
        .filter(Boolean),
])

const LOOPBACK_HOSTS = new Set(['127.0.0.1', 'localhost', '[::1]'])

/** Parsed redirect URI when allowed, otherwise null. */
export function allowedRedirect(redirectUri: string): URL | null {
    let url: URL
    try {
        url = new URL(redirectUri)
    } catch {
        return null
    }
    if (url.hash || url.username || url.password) return null
    // RFC 8252 §7.3: native apps listen on an ephemeral loopback port, so any port is fine,
    // but only over plain http to the loopback host itself.
    if (url.protocol === 'http:' && LOOPBACK_HOSTS.has(url.hostname)) return url
    if (
        url.protocol === 'https:' &&
        url.host === 'chatgpt.com' &&
        !url.search &&
        CHATGPT_CALLBACK_ID.test(url.pathname) &&
        redirectUri === url.origin + url.pathname
    ) {
        return url
    }
    // Exact string match on the raw value (works for custom schemes like cursor://, whose
    // WHATWG parse has an opaque-ish host, without relying on normalisation).
    return REDIRECT_ALLOWLIST.has(redirectUri) ? url : null
}

type AuthCode = {
    accessToken: string
    redirectUri: string
    clientId: string
    codeChallenge: string
    expiresAt: number
}

const authCodes = new Map<string, AuthCode>()

function b64url(buf: Buffer) {
    return buf.toString('base64url')
}

function escapeHtml(value: string) {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;')
}

function verifyPkce(verifier: string, challenge: string) {
    // S256 only; `plain` would let anyone who saw the authorize URL redeem the code.
    if (!/^[A-Za-z0-9._~-]{43,128}$/.test(verifier)) return false
    const digest = createHash('sha256').update(verifier).digest()
    return b64url(digest) === challenge
}

/** Error text for invalid authorize params, or '' when they are fine. */
function authorizeParamsError(redirectUri: string, codeChallenge: string, codeChallengeMethod: string) {
    if (!redirectUri) return 'redirect_uri is required.'
    if (!allowedRedirect(redirectUri)) return 'redirect_uri is not allowed for this server.'
    if (!codeChallenge) return 'code_challenge (PKCE) is required.'
    if (codeChallengeMethod !== 'S256') return 'Only code_challenge_method=S256 is supported.'
    if (!/^[A-Za-z0-9_-]{43}$/.test(codeChallenge)) return 'code_challenge is malformed.'
    return ''
}

function cleanupCodes() {
    const now = Date.now()
    for (const [code, row] of authCodes) {
        if (row.expiresAt <= now) authCodes.delete(code)
    }
}

function authorizePage(params: Record<string, string>, error = '') {
    const redirectHost = params.redirect_uri ? allowedRedirect(params.redirect_uri) : null
    const redirectLabel = redirectHost ? (redirectHost.host || redirectHost.protocol + '//' + redirectHost.hostname) : ''
    const err = error
        ? `<p class="err">${escapeHtml(error)}</p>`
        : ''
    const hidden = ['client_id', 'redirect_uri', 'response_type', 'state', 'scope', 'code_challenge', 'code_challenge_method']
        .map((name) => {
            const value = params[name] || (name === 'client_id' ? CLIENT_ID : name === 'response_type' ? 'code' : name === 'scope' ? 'mcp' : '')
            return `<input type="hidden" name="${name}" value="${escapeHtml(value)}" />`
        })
        .join('\n')

    return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="referrer" content="no-referrer" />
  <title>Authorize FundsFlow MCP</title>
  <style>
    body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
    .card{width:100%;max-width:420px;background:#1e293b;border:1px solid #334155;border-radius:1rem;padding:1.5rem}
    h1{font-size:1.25rem;margin:0 0 0.35rem}
    p{color:#94a3b8;font-size:0.9rem;line-height:1.4}
    .err{color:#fca5a5;margin:0 0 1rem;font-size:0.9rem}
    label{display:block;font-size:0.8rem;margin:0.75rem 0 0.35rem;color:#cbd5e1}
    input[type=email],input[type=password]{width:100%;box-sizing:border-box;padding:0.65rem 0.75rem;border-radius:0.5rem;border:1px solid #475569;background:#0f172a;color:#f8fafc}
    button{margin-top:1rem;width:100%;padding:0.75rem;border:0;border-radius:0.5rem;background:#38bdf8;color:#0f172a;font-weight:700;cursor:pointer}
    .meta{font-size:0.75rem;color:#64748b;margin-top:1rem;word-break:break-all}
  </style>
</head>
<body>
  <div class="card">
    <h1>Authorize FundsFlow MCP</h1>
    <p>Sign in with your FundsFlow email and password. Keep this tab open until you are redirected back to your MCP client.</p>

    ${err}
    <form method="post" action="/oauth/authorize" autocomplete="on">
      ${hidden}
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required autocomplete="username" autofocus />
      <label for="password">Password</label>
      <input id="password" name="password" type="password" required minlength="8" autocomplete="current-password" />
      <button type="submit">Authorize</button>
    </form>
    <p class="meta">Client: ${escapeHtml(params.client_id || CLIENT_ID)}</p>
    ${redirectLabel ? `<p class="meta">After sign-in you will be sent to: <strong>${escapeHtml(redirectLabel)}</strong></p>` : ''}
  </div>
</body>
</html>`
}

export function registerOAuthRoutes(app: Express) {
    app.get('/.well-known/oauth-authorization-server', (_req, res) => {
        res.json({
            issuer: PUBLIC_BASE,
            authorization_endpoint: `${PUBLIC_BASE}/oauth/authorize`,
            token_endpoint: `${PUBLIC_BASE}/oauth/token`,
            response_types_supported: ['code'],
            grant_types_supported: ['authorization_code'],
            code_challenge_methods_supported: ['S256'],
            token_endpoint_auth_methods_supported: CLIENT_SECRET
                ? ['client_secret_post', 'client_secret_basic']
                : ['none', 'client_secret_post', 'client_secret_basic'],
            scopes_supported: ['mcp'],
        })
    })

    app.get('/.well-known/oauth-protected-resource', (_req, res) => {
        res.json({
            resource: `${PUBLIC_BASE}/mcp`,
            authorization_servers: [PUBLIC_BASE],
            scopes_supported: ['mcp'],
            bearer_methods_supported: ['header'],
        })
    })

    app.get('/.well-known/oauth-protected-resource/mcp', (_req, res) => {
        res.json({
            resource: `${PUBLIC_BASE}/mcp`,
            authorization_servers: [PUBLIC_BASE],
            scopes_supported: ['mcp'],
            bearer_methods_supported: ['header'],
        })
    })

    app.get('/oauth/authorize', (req: Request, res: Response) => {
        const clientId = String(req.query.client_id || CLIENT_ID)
        const redirectUri = String(req.query.redirect_uri || '')
        const responseType = String(req.query.response_type || 'code')
        const state = String(req.query.state || '')
        const scope = String(req.query.scope || 'mcp')
        const codeChallenge = String(req.query.code_challenge || '')
        const codeChallengeMethod = String(req.query.code_challenge_method || 'S256')

        console.log('oauth authorize GET', { clientId, redirectUri: redirectUri.slice(0, 80), responseType })

        if (responseType !== 'code') {
            res.status(400).type('html').send(authorizePage({ client_id: clientId }, 'Only response_type=code is supported.'))
            return
        }
        // Reject before rendering the form: never collect a password for a foreign redirect.
        const paramsError = authorizeParamsError(redirectUri, codeChallenge, codeChallengeMethod)
        if (paramsError) {
            res.status(400).type('html').set('Cache-Control', 'no-store').send(authorizePage({ client_id: clientId }, paramsError))
            return
        }

        res
            .type('html')
            .set('Cache-Control', 'no-store')
            .set('Cross-Origin-Opener-Policy', 'unsafe-none')
            .send(
                authorizePage({
                    client_id: clientId || CLIENT_ID,
                    redirect_uri: redirectUri,
                    response_type: responseType,
                    state,
                    scope,
                    code_challenge: codeChallenge,
                    code_challenge_method: codeChallengeMethod,
                }),
            )
    })

    app.post('/oauth/authorize', async (req: Request, res: Response) => {
        cleanupCodes()

        const clientId = String(req.body?.client_id || req.query.client_id || CLIENT_ID)
        const redirectUri = String(req.body?.redirect_uri || req.query.redirect_uri || '')
        const state = String(req.body?.state || req.query.state || '')
        const codeChallenge = String(req.body?.code_challenge || req.query.code_challenge || '')
        const codeChallengeMethod = String(
            req.body?.code_challenge_method || req.query.code_challenge_method || 'S256',
        )
        const email = String(req.body?.email || '')
        const password = String(req.body?.password || '')

        const params = {
            client_id: clientId,
            redirect_uri: redirectUri,
            response_type: 'code',
            state,
            scope: 'mcp',
            code_challenge: codeChallenge,
            code_challenge_method: codeChallengeMethod,
        }

        console.log('oauth authorize POST', { clientId, email, redirectUri: redirectUri.slice(0, 80) })

        const paramsError = authorizeParamsError(redirectUri, codeChallenge, codeChallengeMethod)
        if (paramsError) {
            res.status(400).type('html').set('Cache-Control', 'no-store').send(authorizePage({ client_id: clientId }, paramsError))
            return
        }
        if (clientId !== CLIENT_ID) {
            res.status(400).type('html').set('Cache-Control', 'no-store').send(authorizePage({ client_id: clientId }, 'Unknown client_id.'))
            return
        }
        // Validated above, so building the target cannot throw after a backend token exists.
        const target = allowedRedirect(redirectUri) as URL

        if (!email || !password) {
            res.status(400).type('html').send(authorizePage(params, 'Email and password are required.'))
            return
        }

        try {
            const login = await fetch(`${API_URL}/auth/login`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password }),
            })
            const payload = (await login.json().catch(() => ({}))) as { token?: string; message?: string }
            if (!login.ok || !payload.token) {
                res
                    .status(401)
                    .type('html')
                    .set('Cache-Control', 'no-store')
                    .send(authorizePage(params, payload.message || 'Invalid email or password.'))
                return
            }

            const code = b64url(randomBytes(24))
            authCodes.set(code, {
                accessToken: payload.token,
                redirectUri,
                clientId,
                codeChallenge,
                expiresAt: Date.now() + CODE_TTL_MS,
            })

            target.searchParams.set('code', code)
            if (state) target.searchParams.set('state', state)

            // Must be a real 302 to redirect_uri?code=... — some MCP clients watch for that URL.

            // An HTML interstitial breaks detection and the tab just closes as "failed".
            res.set('Cache-Control', 'no-store').redirect(302, target.toString())
        } catch (error) {
            console.error('oauth authorize failed', error)
            res.status(500).type('html').send(authorizePage(params, 'Login failed. Try again.'))
        }
    })

    app.post('/oauth/token', async (req: Request, res: Response) => {
        cleanupCodes()

        const body = (req.body || {}) as Record<string, unknown>
        res.set('Cache-Control', 'no-store').set('Pragma', 'no-cache')

        const code = String(body.code || '')
        const row = authCodes.get(code)
        // Single attempt per code: any failure below burns it, so a leaked code can't be retried.
        authCodes.delete(code)

        let clientId = String(body.client_id || '')
        let clientSecret = String(body.client_secret || '')
        const basic = req.header('authorization')
        if (basic?.toLowerCase().startsWith('basic ')) {
            try {
                const decoded = Buffer.from(basic.slice(6), 'base64').toString('utf8')
                const idx = decoded.indexOf(':')
                if (idx >= 0) {
                    clientId = decoded.slice(0, idx)
                    clientSecret = decoded.slice(idx + 1)
                }
            } catch {
            }

        }
        if (clientId && clientId !== CLIENT_ID) {
            res.status(401).json({ error: 'invalid_client' })
            return
        }
        if (CLIENT_SECRET) {
            if (clientSecret !== CLIENT_SECRET) {
                res.status(401).json({ error: 'invalid_client', error_description: 'client_secret mismatch' })
                return
            }
        }

        const grantType = String(body.grant_type || '')
        if (grantType !== 'authorization_code') {
            res.status(400).json({ error: 'unsupported_grant_type' })
            return
        }

        const redirectUri = String(body.redirect_uri || '')
        const codeVerifier = String(body.code_verifier || '')

        console.log('oauth token', {
            hasCode: !!row,
            redirectUri: redirectUri.slice(0, 120),
            storedRedirect: row?.redirectUri?.slice(0, 120),
            hasVerifier: Boolean(codeVerifier),
            hasChallenge: Boolean(row?.codeChallenge),
        })

        if (!row || row.expiresAt <= Date.now()) {
            res.status(400).json({ error: 'invalid_grant', error_description: 'code unknown or expired' })
            return
        }
        if (row.clientId !== (clientId || CLIENT_ID)) {
            res.status(400).json({ error: 'invalid_grant', error_description: 'client_id mismatch' })
            return
        }
        if (row.redirectUri !== redirectUri) {
            res.status(400).json({
                error: 'invalid_grant',
                error_description: 'redirect_uri mismatch',
            })
            return
        }
        if (!codeVerifier || !verifyPkce(codeVerifier, row.codeChallenge)) {
            res.status(400).json({ error: 'invalid_grant', error_description: 'pkce failed' })
            return
        }

        res.json({
            access_token: row.accessToken,
            token_type: 'Bearer',
            expires_in: 60 * 60 * 24 * 30,
            scope: 'mcp',
        })
    })
}

export function unauthorizedMcp(res: Response, id: unknown = null) {
    res
        .status(401)
        .set(
            'WWW-Authenticate',
            `Bearer realm="FundsFlow MCP", resource_metadata="${PUBLIC_BASE}/.well-known/oauth-protected-resource/mcp", scope="mcp"`,
        )
        .json({
            jsonrpc: '2.0',
            error: {
                code: -32001,
                message: 'Authentication required. Complete OAuth or send Authorization: Bearer <token>.',
            },
            id,
        })
}

export const oauthClientId = CLIENT_ID
export const oauthClientSecret = CLIENT_SECRET
export const oauthPublicBase = PUBLIC_BASE
