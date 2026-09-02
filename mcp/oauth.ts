import { createHash, randomBytes } from 'node:crypto'
import type { Express, Request, Response } from 'express'

const CLIENT_ID = process.env.OAUTH_CLIENT_ID || 'fundsflow'
const PUBLIC_BASE = (process.env.MCP_PUBLIC_URL || 'https://mcp.fundsflow.fun').replace(/\/$/, '')
const API_URL = (process.env.FUNDSFLOW_API_URL || 'https://api.fundsflow.fun/api').replace(/\/$/, '')
const CODE_TTL_MS = 5 * 60 * 1000

type AuthCode = {
    accessToken: string
    redirectUri: string
    clientId: string
    codeChallenge?: string
    codeChallengeMethod?: string
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

function verifyPkce(verifier: string, challenge: string, method = 'S256') {
    if (method === 'plain') return verifier === challenge
    const digest = createHash('sha256').update(verifier).digest()
    return b64url(digest) === challenge
}

function cleanupCodes() {
    const now = Date.now()
    for (const [code, row] of authCodes) {
        if (row.expiresAt <= now) authCodes.delete(code)
    }
}

function authorizePage(params: Record<string, string>, error = '') {
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
    <p>Sign in with your FundsFlow email and password. Keep this tab open until you see the redirect back to Grok.</p>
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
  </div>
</body>
</html>`
}

function successPage(redirectUrl: string) {
    return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Authorized — FundsFlow MCP</title>
  <style>
    body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
    .card{max-width:420px;background:#1e293b;border:1px solid #334155;border-radius:1rem;padding:1.5rem;text-align:center}
  </style>
</head>
<body>
  <div class="card">
    <h1>Authorized</h1>
    <p>Returning to Grok… If this tab does not continue, <a href="${escapeHtml(redirectUrl)}" style="color:#38bdf8">click here</a>.</p>
  </div>
  <script>
    // Give the user a beat to see success, then continue the OAuth redirect.
    setTimeout(function () { window.location.replace(${JSON.stringify(redirectUrl)}); }, 600);
  </script>
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
            code_challenge_methods_supported: ['S256', 'plain'],
            token_endpoint_auth_methods_supported: ['none', 'client_secret_post', 'client_secret_basic'],
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
        if (!redirectUri) {
            res.status(400).type('html').send(authorizePage({ client_id: clientId }, 'redirect_uri is required.'))
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

        if (!redirectUri || !email || !password) {
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
                codeChallenge: codeChallenge || undefined,
                codeChallengeMethod: codeChallenge ? codeChallengeMethod : undefined,
                expiresAt: Date.now() + CODE_TTL_MS,
            })

            const target = new URL(redirectUri)
            target.searchParams.set('code', code)
            if (state) target.searchParams.set('state', state)

            // Intermediate page so the popup does not look like it "just closed".
            res
                .type('html')
                .set('Cache-Control', 'no-store')
                .set('Cross-Origin-Opener-Policy', 'unsafe-none')
                .send(successPage(target.toString()))
        } catch (error) {
            console.error('oauth authorize failed', error)
            res.status(500).type('html').send(authorizePage(params, 'Login failed. Try again.'))
        }
    })

    app.post('/oauth/token', async (req: Request, res: Response) => {
        cleanupCodes()

        const grantType = String(req.body?.grant_type || '')
        if (grantType !== 'authorization_code') {
            res.status(400).json({ error: 'unsupported_grant_type' })
            return
        }

        const code = String(req.body?.code || '')
        const redirectUri = String(req.body?.redirect_uri || '')
        const codeVerifier = String(req.body?.code_verifier || '')
        const row = authCodes.get(code)

        console.log('oauth token', { hasCode: !!row, redirectUri: redirectUri.slice(0, 80) })

        if (!row || row.expiresAt <= Date.now()) {
            authCodes.delete(code)
            res.status(400).json({ error: 'invalid_grant' })
            return
        }
        if (row.redirectUri !== redirectUri) {
            res.status(400).json({ error: 'invalid_grant', error_description: 'redirect_uri mismatch' })
            return
        }
        if (row.codeChallenge) {
            if (!codeVerifier || !verifyPkce(codeVerifier, row.codeChallenge, row.codeChallengeMethod)) {
                res.status(400).json({ error: 'invalid_grant', error_description: 'pkce failed' })
                return
            }
        }

        authCodes.delete(code)

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
export const oauthPublicBase = PUBLIC_BASE
