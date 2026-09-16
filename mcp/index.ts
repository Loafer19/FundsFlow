import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js'
import { hostHeaderValidation } from '@modelcontextprotocol/sdk/server/middleware/hostHeaderValidation.js'
import express, { type Request, type Response } from 'express'
import { z } from 'zod'
import { oauthClientId, oauthClientSecret, oauthPublicBase, registerOAuthRoutes, unauthorizedMcp } from './oauth.ts'

/** 8 MB file → ~10.7 MB base64; leave headroom for JSON-RPC envelope. */
const JSON_BODY_LIMIT = '12mb'
const MAX_ATTACHMENT_BASE64_CHARS = Math.ceil((8 * 1024 * 1024 * 4) / 3) + 4096
const BULK_MAX_ITEMS = 50



const PORT = Number(process.env.PORT || 8787)
const HOST = process.env.HOST || '127.0.0.1'
const API_URL = (process.env.FUNDSFLOW_API_URL || 'https://api.fundsflow.fun/api').replace(/\/$/, '')
const ENV_TOKEN = process.env.FUNDSFLOW_TOKEN || ''
const MCP_KEY = process.env.MCP_KEY || ''
const ALLOWED_HOSTS = (process.env.ALLOWED_HOSTS || '127.0.0.1,localhost,mcp.fundsflow.fun')
    .split(',')
    .map((h) => h.trim())
    .filter(Boolean)

const TOOL_NAMES = [
    'get_me',
    'get_bootstrap',
    'list_tags',
    'create_tag',
    'update_tag',
    'delete_tag',
    'list_transactions',
    'list_recent_transactions',
    'create_transaction',
    'create_transactions',
    'update_transaction',
    'update_transactions',
    'delete_transaction',
    'delete_transactions',
    'attach_transaction_file',

    'list_transaction_attachments',
    'get_transaction_attachment',
    'delete_transaction_attachment',
    'list_budgets',

    'create_budget',
    'update_budget',
    'delete_budget',
    'pause_budget',
    'resume_budget',
    'list_recurring',
    'create_recurring',
    'update_recurring',
    'delete_recurring',
    'update_preferences',
    'export_account',
] as const

async function api<T = unknown>(token: string | null, path: string, init: RequestInit = {}): Promise<T> {
    if (!token) {
        throw new Error(
            'Authentication required. In Settings → Accounts → MCP generate a token, then use Authorization: Bearer <token> (or ?token= for clients that cannot set headers).',
        )
    }


    const response = await fetch(`${API_URL}${path}`, {
        ...init,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
            'X-FundsFlow-Source': 'mcp',
            ...(init.headers || {}),
        },
    })

    const text = await response.text()
    let body: unknown = null
    if (text) {
        try {
            body = JSON.parse(text)
        } catch {
            body = text
        }
    }

    if (!response.ok) {
        const message =
            typeof body === 'object' && body && 'message' in body
                ? String((body as { message: unknown }).message)
                : text || response.statusText
        throw new Error(`FundsFlow API ${response.status}: ${message}`)
    }

    return body as T
}

function unwrapList(payload: unknown): unknown[] {
    if (Array.isArray(payload)) return payload
    if (payload && typeof payload === 'object' && 'data' in payload) {
        const data = (payload as { data: unknown }).data
        if (Array.isArray(data)) return data
    }
    return []
}

function textResult(data: unknown) {
    return {
        content: [
            {
                type: 'text' as const,
                text: typeof data === 'string' ? data : JSON.stringify(data, null, 2),
            },
        ],
    }
}

function resolveUserToken(req: Request): string | null {
    const header = req.header('authorization')
    if (header?.toLowerCase().startsWith('bearer ')) {
        const value = header.slice(7).trim()
        if (value) return value
    }

    const custom = req.header('x-fundsflow-token')
    if (typeof custom === 'string' && custom.trim()) return custom.trim()

    const queryToken = req.query.token
    if (typeof queryToken === 'string' && queryToken.trim()) return queryToken.trim()

    return null
}

function hasMcpKey(req: Request): boolean {
    if (!MCP_KEY) return true
    const key = req.header('x-fundsflow-mcp-key') || req.query.key
    return typeof key === 'string' && key === MCP_KEY
}

function createServer(token: string | null) {
    const server = new McpServer({
        name: 'fundsflow',
        version: '1.1.0',
    })

    server.registerTool(
        'get_me',
        {
            title: 'Get account',
            description: 'Return the authenticated FundsFlow user and linked identities.',
            inputSchema: {},
        },
        async () => textResult(await api(token, '/auth/me')),
    )

    server.registerTool(
        'get_bootstrap',
        {
            title: 'Bootstrap data',
            description: 'Load tags, transactions, budgets, and recurring rules in one request.',
            inputSchema: {},
        },
        async () => textResult(await api(token, '/bootstrap')),
    )

    server.registerTool(
        'list_tags',
        {
            title: 'List tags',
            description: 'List all tags for the account.',
            inputSchema: {},
        },
        async () => textResult(unwrapList(await api(token, '/tags'))),
    )

    server.registerTool(
        'create_tag',
        {
            title: 'Create tag',
            description: 'Create a tag. emoji is required (can be a single emoji character).',
            inputSchema: {
                title: z.string().max(255),
                emoji: z.string().max(255),
                calc_balance: z.boolean().default(true),
                parent_id: z.number().int().nullable().optional(),
            },
        },
        async (args) =>
            textResult(
                await api(token, '/tags', {
                    method: 'POST',
                    body: JSON.stringify({
                        title: args.title,
                        emoji: args.emoji,
                        calc_balance: args.calc_balance,
                        parent_id: args.parent_id ?? null,
                    }),
                }),
            ),
    )

    server.registerTool(
        'update_tag',
        {
            title: 'Update tag',
            description: 'Update an existing tag by id.',
            inputSchema: {
                id: z.number().int(),
                title: z.string().max(255),
                emoji: z.string().max(255),
                calc_balance: z.boolean(),
                parent_id: z.number().int().nullable().optional(),
            },
        },
        async ({ id, ...body }) =>
            textResult(
                await api(token, `/tags/${id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        ...body,
                        parent_id: body.parent_id ?? null,
                    }),
                }),
            ),
    )

    server.registerTool(
        'delete_tag',
        {
            title: 'Delete tag',
            description: 'Soft-delete a tag by id.',
            inputSchema: { id: z.number().int() },
        },
        async ({ id }) => textResult(await api(token, `/tags/${id}`, { method: 'DELETE' })),
    )

    server.registerTool(
        'list_transactions',
        {
            title: 'List transactions',
            description: 'List all transactions (newest first in typical UI; API order as returned).',
            inputSchema: {},
        },
        async () => textResult(unwrapList(await api(token, '/transactions'))),
    )

    server.registerTool(
        'list_recent_transactions',
        {
            title: 'List recent transactions',
            description: 'Return the newest N transactions.',
            inputSchema: {
                limit: z.number().int().min(1).max(100).default(10),
            },
        },
        async ({ limit }) => textResult(unwrapList(await api(token, '/transactions')).slice(0, limit)),
    )

    const transactionInputSchema = {
        amount: z.number(),
        at: z.string(),
        note: z.string().max(255).optional(),
        tags: z.array(z.number().int()).optional(),
    }

    server.registerTool(
        'create_transaction',
        {
            title: 'Create transaction',
            description:
                'Create a transaction. Amount is signed: negative expense, positive income. Date YYYY-MM-DD. Source will be recorded as mcp. For many rows prefer create_transactions.',
            inputSchema: transactionInputSchema,
        },
        async ({ amount, at, note, tags }) =>
            textResult(
                await api(token, '/transactions', {
                    method: 'POST',
                    body: JSON.stringify({
                        amount,
                        at,
                        note: note ?? null,
                        tags: tags ?? [],
                    }),
                }),
            ),
    )

    server.registerTool(
        'create_transactions',
        {
            title: 'Create transactions (bulk)',
            description:
                'Create up to 50 transactions in one request (all-or-nothing). Amount is signed: negative expense, positive income. Date YYYY-MM-DD. Source will be recorded as mcp.',
            inputSchema: {
                transactions: z
                    .array(
                        z.object({
                            amount: z.number(),
                            at: z.string(),
                            note: z.string().max(255).optional(),
                            tags: z.array(z.number().int()).optional(),
                        }),
                    )
                    .min(1)
                    .max(BULK_MAX_ITEMS),
            },
        },
        async ({ transactions }) =>
            textResult(
                await api(token, '/transactions/bulk', {
                    method: 'POST',

                    body: JSON.stringify({
                        transactions: transactions.map((row) => ({
                            amount: row.amount,
                            at: row.at,
                            note: row.note ?? null,
                            tags: row.tags ?? [],
                        })),
                    }),
                }),
            ),
    )

    server.registerTool(
        'update_transaction',
        {
            title: 'Update transaction',
            description: 'Update a transaction by id. For many rows prefer update_transactions.',
            inputSchema: {
                id: z.number().int(),
                amount: z.number(),
                at: z.string(),
                note: z.string().max(255).optional(),
                tags: z.array(z.number().int()).optional(),
            },
        },
        async ({ id, amount, at, note, tags }) =>
            textResult(
                await api(token, `/transactions/${id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        amount,
                        at,
                        note: note ?? null,
                        tags: tags ?? [],
                    }),
                }),
            ),
    )

    server.registerTool(
        'update_transactions',
        {
            title: 'Update transactions (bulk)',
            description:
                'Update up to 50 transactions in one request (all-or-nothing). Each item needs id, amount, at; note and tags optional (omit tags to clear them).',
            inputSchema: {
                transactions: z
                    .array(
                        z.object({
                            id: z.number().int(),
                            amount: z.number(),
                            at: z.string(),
                            note: z.string().max(255).optional(),
                            tags: z.array(z.number().int()).optional(),
                        }),
                    )
                    .min(1)
                    .max(BULK_MAX_ITEMS),
            },
        },
        async ({ transactions }) =>
            textResult(
                await api(token, '/transactions/bulk', {
                    method: 'PUT',

                    body: JSON.stringify({
                        transactions: transactions.map((row) => ({
                            id: row.id,
                            amount: row.amount,
                            at: row.at,
                            note: row.note ?? null,
                            tags: row.tags ?? [],
                        })),
                    }),
                }),
            ),
    )

    server.registerTool(
        'delete_transaction',
        {
            title: 'Delete transaction',
            description: 'Delete a transaction by id. For many rows prefer delete_transactions.',
            inputSchema: { id: z.number().int() },
        },
        async ({ id }) => textResult(await api(token, `/transactions/${id}`, { method: 'DELETE' })),
    )

    server.registerTool(
        'delete_transactions',
        {
            title: 'Delete transactions (bulk)',
            description:
                'Delete up to 50 transactions by id in one request (all-or-nothing). Unknown or foreign ids fail the whole batch.',
            inputSchema: {
                ids: z.array(z.number().int()).min(1).max(BULK_MAX_ITEMS),

            },
        },
        async ({ ids }) =>
            textResult(
                await api(token, '/transactions/bulk', {
                    method: 'DELETE',
                    body: JSON.stringify({ ids }),
                }),
            ),
    )


    server.registerTool(
        'attach_transaction_file',
        {
            title: 'Attach file to transaction',
            description:
                'Attach a JPEG/PNG/WebP/PDF to a transaction (max 8 MB decoded, max 5 files per transaction). Pass raw base64 only (no data: URL prefix).',
            inputSchema: {
                transaction_id: z.number().int(),
                name: z.string().max(255),
                mime: z.enum(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']),
                content_base64: z.string().min(1),
            },
        },
        async ({ transaction_id, name, mime, content_base64 }) => {
            const content = normalizeBase64(content_base64)

            if (content.length > MAX_ATTACHMENT_BASE64_CHARS) {
                throw new Error('File too large! Max 8 MB decoded.')
            }

            return textResult(
                await api(token, `/transactions/${transaction_id}/attachments/base64`, {
                    method: 'POST',
                    body: JSON.stringify({
                        name,
                        mime,
                        content,
                    }),
                    signal: AbortSignal.timeout(120_000),
                }),
            )
        },
    )


    server.registerTool(
        'list_transaction_attachments',
        {
            title: 'List transaction attachments',
            description: 'List attachment metadata for a transaction.',
            inputSchema: { transaction_id: z.number().int() },
        },
        async ({ transaction_id }) => {
            const list = unwrapList(await api(token, '/transactions')) as Array<{
                id: number
                attachments?: unknown[]
            }>
            const tx = list.find((row) => row.id === transaction_id)
            if (!tx) throw new Error(`Transaction ${transaction_id} not found`)
            return textResult(tx.attachments ?? [])
        },
    )

    server.registerTool(
        'get_transaction_attachment',
        {
            title: 'Get transaction attachment',
            description: 'Download an attachment as base64 (name, mime, content_base64).',
            inputSchema: {
                transaction_id: z.number().int(),
                attachment_id: z.number().int(),
            },
        },
        async ({ transaction_id, attachment_id }) => {
            if (!token) {
                throw new Error('Authentication required.')
            }

            const response = await fetch(
                `${API_URL}/transactions/${transaction_id}/attachments/${attachment_id}`,
                {
                    headers: {
                        Authorization: `Bearer ${token}`,
                        'X-FundsFlow-Source': 'mcp',
                    },
                },
            )

            if (!response.ok) {
                const message = await response.text()
                throw new Error(`FundsFlow API ${response.status}: ${message || response.statusText}`)
            }

            const buffer = Buffer.from(await response.arrayBuffer())
            const mime = response.headers.get('content-type') || 'application/octet-stream'
            const disposition = response.headers.get('content-disposition') || ''
            const star = /filename\*=UTF-8''([^;]+)/i.exec(disposition)
            const quoted = /filename="([^"]+)"/.exec(disposition)
            const name = star?.[1]
                ? decodeURIComponent(star[1])
                : quoted?.[1] || `attachment-${attachment_id}`

            return textResult({
                id: attachment_id,
                transaction_id,
                name,
                mime,
                size: buffer.length,
                content_base64: buffer.toString('base64'),
            })
        },
    )


    server.registerTool(
        'delete_transaction_attachment',
        {
            title: 'Delete transaction attachment',
            description: 'Delete one attachment from a transaction.',
            inputSchema: {
                transaction_id: z.number().int(),
                attachment_id: z.number().int(),
            },
        },
        async ({ transaction_id, attachment_id }) =>
            textResult(
                await api(token, `/transactions/${transaction_id}/attachments/${attachment_id}`, {
                    method: 'DELETE',
                }),
            ),
    )


    server.registerTool(
        'list_budgets',
        {
            title: 'List budgets',
            description: 'List all budgets.',
            inputSchema: {},
        },
        async () => textResult(unwrapList(await api(token, '/budgets'))),
    )

    server.registerTool(
        'create_budget',
        {
            title: 'Create budget',
            description: 'Create a budget. length is week|month|year. tag_ids required (at least one).',
            inputSchema: {
                amount: z.number().positive(),
                length: z.enum(['week', 'month', 'year']),
                tag_ids: z.array(z.number().int()).min(1),
                title: z.string().max(255).optional(),
                align_to_calendar: z.boolean().optional(),
            },
        },
        async (args) =>
            textResult(
                await api(token, '/budgets', {
                    method: 'POST',
                    body: JSON.stringify(args),
                }),
            ),
    )

    server.registerTool(
        'update_budget',
        {
            title: 'Update budget',
            description: 'Update a budget by id (creates a new period version as the API defines).',
            inputSchema: {
                id: z.number().int(),
                amount: z.number().positive(),
                length: z.enum(['week', 'month', 'year']),
                tag_ids: z.array(z.number().int()).min(1),
                title: z.string().max(255).optional(),
                align_to_calendar: z.boolean().optional(),
            },
        },
        async ({ id, ...body }) =>
            textResult(
                await api(token, `/budgets/${id}`, {
                    method: 'PUT',
                    body: JSON.stringify(body),
                }),
            ),
    )

    server.registerTool(
        'delete_budget',
        {
            title: 'Delete budget',
            description: 'Delete a budget by id.',
            inputSchema: { id: z.number().int() },
        },
        async ({ id }) => textResult(await api(token, `/budgets/${id}`, { method: 'DELETE' })),
    )

    server.registerTool(
        'pause_budget',
        {
            title: 'Pause budget',
            description: 'Pause a budget by id.',
            inputSchema: { id: z.number().int() },
        },
        async ({ id }) => textResult(await api(token, `/budgets/${id}/pause`, { method: 'POST' })),
    )

    server.registerTool(
        'resume_budget',
        {
            title: 'Resume budget',
            description: 'Resume a paused budget by id.',
            inputSchema: { id: z.number().int() },
        },
        async ({ id }) => textResult(await api(token, `/budgets/${id}/resume`, { method: 'POST' })),
    )

    server.registerTool(
        'list_recurring',
        {
            title: 'List recurring',
            description: 'List recurring transaction rules.',
            inputSchema: {},
        },
        async () => textResult(unwrapList(await api(token, '/recurring-transactions'))),
    )

    server.registerTool(
        'create_recurring',
        {
            title: 'Create recurring',
            description: 'Create a recurring rule. frequency: daily|weekly|monthly|yearly.',
            inputSchema: {
                amount: z.number(),
                frequency: z.enum(['daily', 'weekly', 'monthly', 'yearly']),
                starts_at: z.string(),
                note: z.string().max(255).optional(),
                ends_at: z.string().optional(),
                active: z.boolean().optional(),
                tags: z.array(z.number().int()).optional(),
            },
        },
        async (args) =>
            textResult(
                await api(token, '/recurring-transactions', {
                    method: 'POST',
                    body: JSON.stringify({
                        ...args,
                        note: args.note ?? null,
                        ends_at: args.ends_at ?? null,
                        tags: args.tags ?? [],
                    }),
                }),
            ),
    )

    server.registerTool(
        'update_recurring',
        {
            title: 'Update recurring',
            description: 'Update a recurring rule by id.',
            inputSchema: {
                id: z.number().int(),
                amount: z.number(),
                frequency: z.enum(['daily', 'weekly', 'monthly', 'yearly']),
                starts_at: z.string(),
                note: z.string().max(255).optional(),
                ends_at: z.string().optional(),
                active: z.boolean().optional(),
                tags: z.array(z.number().int()).optional(),
            },
        },
        async ({ id, ...args }) =>
            textResult(
                await api(token, `/recurring-transactions/${id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        ...args,
                        note: args.note ?? null,
                        ends_at: args.ends_at ?? null,
                        tags: args.tags ?? [],
                    }),
                }),
            ),
    )

    server.registerTool(
        'delete_recurring',
        {
            title: 'Delete recurring',
            description: 'Delete a recurring rule by id.',
            inputSchema: { id: z.number().int() },
        },
        async ({ id }) => textResult(await api(token, `/recurring-transactions/${id}`, { method: 'DELETE' })),
    )

    server.registerTool(
        'update_preferences',
        {
            title: 'Update preferences',
            description: 'Update money/date formatting preferences (moneyFormat locale, dateFormat key, decimals).',
            inputSchema: {
                moneyFormat: z.string().max(32),
                dateFormat: z.string().max(64),
                decimals: z.boolean(),
            },
        },
        async (args) =>
            textResult(
                await api(token, '/account/preferences', {
                    method: 'PATCH',
                    body: JSON.stringify(args),
                }),
            ),
    )

    server.registerTool(
        'export_account',
        {
            title: 'Export account',
            description: 'Download the full account JSON export (profile, tags, transactions, budgets, recurring).',
            inputSchema: {},
        },
        async () => textResult(await api(token, '/account/export')),
    )

    return server
}


// Manual Express setup (not createMcpExpressApp): default express.json() is 100kb,
// which rejects base64 attachment tool calls with 413 and looks like a hang in the client.
const app = express()

app.use(express.json({ limit: JSON_BODY_LIMIT }))
app.use(express.urlencoded({ extended: false, limit: JSON_BODY_LIMIT }))
app.use(hostHeaderValidation(ALLOWED_HOSTS))
registerOAuthRoutes(app)

function normalizeBase64(input: string): string {
    const trimmed = input.trim()
    const dataUrl = /^data:[^;]+;base64,([\s\S]+)$/.exec(trimmed)
    return (dataUrl?.[1] ?? trimmed).replace(/\s+/g, '')
}


app.get('/', (_req, res) => {
    res.json({
        name: 'fundsflow-mcp',
        version: '1.2.0',
        mcp: '/mcp',
        tools: TOOL_NAMES,
        oauth: {
            client_id: oauthClientId,
            client_secret: oauthClientSecret || null,
            authorization_endpoint: `${oauthPublicBase}/oauth/authorize`,
            token_endpoint: `${oauthPublicBase}/oauth/token`,
            scopes: ['mcp'],
            token_endpoint_auth_method: oauthClientSecret ? 'client_secret_post' : 'none',
        },
    })
})

function isPublicMcpMethod(body: unknown): boolean {
    if (!body || typeof body !== 'object' || !('method' in body)) return false
    const method = String((body as { method: unknown }).method)
    return (
        method === 'initialize' ||
        method === 'notifications/initialized' ||
        method === 'tools/list' ||
        method === 'ping'
    )
}

app.post('/mcp', async (req: Request, res: Response) => {
    const userToken = resolveUserToken(req)
    let token: string | null = userToken
    if (!token && ENV_TOKEN && hasMcpKey(req)) {
        token = ENV_TOKEN
    }

    // Keep discovery public so adding the MCP URL does not force OAuth.
    // Tool calls still need Authorization: Bearer or ?token= (Settings → Copy connection URL).
    if (!token && !isPublicMcpMethod(req.body)) {
        unauthorizedMcp(res, req.body?.id ?? null)
        return
    }


    const server = createServer(token)
    try {
        const transport = new StreamableHTTPServerTransport({
            sessionIdGenerator: undefined,
        })
        await server.connect(transport)
        await transport.handleRequest(req, res, req.body)
        res.on('close', () => {
            transport.close()
            server.close()
        })
    } catch (error) {
        console.error('MCP request failed:', error)
        if (!res.headersSent) {
            res.status(500).json({
                jsonrpc: '2.0',
                error: { code: -32603, message: 'Internal server error' },
                id: null,
            })
        }
    }
})

app.get('/mcp', (_req, res) => {
    res.status(405).json({
        jsonrpc: '2.0',
        error: { code: -32000, message: 'Method not allowed. Use POST.' },
        id: null,
    })
})

app.delete('/mcp', (_req, res) => {
    res.status(405).json({
        jsonrpc: '2.0',
        error: { code: -32000, message: 'Method not allowed.' },
        id: null,
    })
})

app.listen(PORT, HOST, () => {
    console.log(`FundsFlow MCP listening on http://${HOST}:${PORT}/mcp`)
    console.log(`OAuth authorize: ${oauthPublicBase}/oauth/authorize`)
    console.log(`API: ${API_URL}`)
    console.log(`Tools: ${TOOL_NAMES.length}`)
})
