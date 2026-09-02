import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js'
import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js'
import express, { type Request, type Response } from 'express'
import { z } from 'zod'
import { oauthClientId, oauthPublicBase, registerOAuthRoutes, unauthorizedMcp } from './oauth.ts'

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
    'update_transaction',
    'delete_transaction',
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
            'Authentication required. In Settings → Accounts → MCP generate a token, then add header Authorization: Bearer <token> (do not put the token in the URL).',
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

    // —— Tags ——
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

    // —— Transactions ——
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

    server.registerTool(
        'create_transaction',
        {
            title: 'Create transaction',
            description:
                'Create a transaction. Amount is signed: negative expense, positive income. Date YYYY-MM-DD. Source will be recorded as mcp.',
            inputSchema: {
                amount: z.number(),
                at: z.string(),
                note: z.string().max(255).optional(),
                tags: z.array(z.number().int()).optional(),
            },
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
        'update_transaction',
        {
            title: 'Update transaction',
            description: 'Update a transaction by id.',
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
        'delete_transaction',
        {
            title: 'Delete transaction',
            description: 'Delete a transaction by id.',
            inputSchema: { id: z.number().int() },
        },
        async ({ id }) => textResult(await api(token, `/transactions/${id}`, { method: 'DELETE' })),
    )

    // —— Budgets ——
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

    // —— Recurring ——
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

    // —— Account ——
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

    // Keep old name as alias for create_transaction
    server.registerTool(
        'add_transaction',
        {
            title: 'Add transaction (alias)',
            description: 'Alias of create_transaction.',
            inputSchema: {
                amount: z.number(),
                at: z.string(),
                note: z.string().max(255).optional(),
                tags: z.array(z.number().int()).optional(),
            },
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

    return server
}

const app = createMcpExpressApp({
    host: HOST,
    allowedHosts: ALLOWED_HOSTS,
})

app.use(express.urlencoded({ extended: false }))
registerOAuthRoutes(app)

app.get('/', (_req, res) => {
    res.json({
        name: 'fundsflow-mcp',
        version: '1.2.0',
        mcp: '/mcp',
        tools: TOOL_NAMES,
        oauth: {
            client_id: oauthClientId,
            authorization_endpoint: `${oauthPublicBase}/oauth/authorize`,
            token_endpoint: `${oauthPublicBase}/oauth/token`,
            scopes: ['mcp'],
            token_endpoint_auth_method: 'none',
        },
    })
})

app.post('/mcp', async (req: Request, res: Response) => {
    const userToken = resolveUserToken(req)
    let token: string | null = userToken
    if (!token && ENV_TOKEN && hasMcpKey(req)) {
        token = ENV_TOKEN
    }

    if (!token) {
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

app.get('/mcp', (req, res) => {
    if (!resolveUserToken(req) && !(ENV_TOKEN && hasMcpKey(req))) {
        unauthorizedMcp(res)
        return
    }
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
