import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js'
import { hostHeaderValidation } from '@modelcontextprotocol/sdk/server/middleware/hostHeaderValidation.js'
import { createHash, timingSafeEqual } from 'node:crypto'
import express, { type Request, type Response } from 'express'
import { z } from 'zod'
import { formatApiError, nameFromContentDisposition, unwrapList } from './util.ts'
import { oauthClientId, oauthClientSecret, oauthPublicBase, registerOAuthRoutes, unauthorizedMcp } from './oauth.ts'

/** 8 MB file → ~10.7 MB base64; leave headroom for JSON-RPC envelope. */
const JSON_BODY_LIMIT = '12mb'
const MAX_ATTACHMENT_BYTES = 8 * 1024 * 1024
const MAX_ATTACHMENT_BASE64_CHARS = Math.ceil((MAX_ATTACHMENT_BYTES * 4) / 3) + 4096
const BULK_MAX_ITEMS = 50
const ATTACHMENT_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'] as const
type AttachmentMime = (typeof ATTACHMENT_MIMES)[number]

const MCP_VERSION = '1.3.1'
const MCP_WEBSITE_URL = 'https://fundsflow.fun'
const MCP_ICONS = [
    {
        src: `${MCP_WEBSITE_URL}/apple-touch-icon.png`,
        mimeType: 'image/png' as const,
        sizes: ['180x180'],
    },
    {
        src: `${MCP_WEBSITE_URL}/favicon-32.png`,
        mimeType: 'image/png' as const,
        sizes: ['32x32'],
    },
]



const PORT = Number(process.env.PORT || 8787)
const HOST = process.env.HOST || '127.0.0.1'
const API_URL = (process.env.FUNDSFLOW_API_URL || 'https://api.fundsflow.fun/api').replace(/\/$/, '')
const ENV_TOKEN = process.env.FUNDSFLOW_TOKEN || ''
const MCP_KEY = process.env.MCP_KEY || ''
const API_TIMEOUT_MS = 30_000
// Dokploy puts Traefik in front (one hop); req.ip must be the client for rate limiting.
const TRUST_PROXY = process.env.TRUST_PROXY ?? '1'

// Column limits: transactions decimal(15,2), budget_periods/recurring decimal(10,2).
// The backend has no explicit max, so larger values would only fail at the DB.
const TX_AMOUNT_MAX = 9_999_999_999_999.99
const RULE_AMOUNT_MAX = 99_999_999.99
/** Savings goal deadline, YYYY-MM-DD (BudgetStoreRequest: date_format:Y-m-d). */
const DATE_YMD = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use YYYY-MM-DD')

const ALLOWED_HOSTS = (process.env.ALLOWED_HOSTS || '127.0.0.1,localhost,mcp.fundsflow.fun')
    .split(',')
    .map((h) => h.trim())
    .filter(Boolean)


async function api<T = unknown>(token: string | null, path: string, init: RequestInit = {}): Promise<T> {
    if (!token) {
        throw new Error(
            'Authentication required. In Settings → Accounts → MCP generate a token, then use Authorization: Bearer <token> (or ?token= for clients that cannot set headers).',
        )
    }


    const response = await fetch(`${API_URL}${path}`, {
        ...init,
        signal: init.signal ?? AbortSignal.timeout(API_TIMEOUT_MS),
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
        throw new Error(formatApiError(response.status, body, text, response.statusText))
    }

    return body as T
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

const LOOPBACK_ADDRESSES = new Set(['127.0.0.1', '::1', '::ffff:127.0.0.1'])
const FORWARDING_HEADERS = ['x-forwarded-for', 'forwarded', 'x-real-ip']

// Direct local connection only: a reverse proxy on the same host also
// connects from loopback, but it adds forwarding headers.
function isDirectLoopback(req: Request): boolean {
    const remote = req.socket.remoteAddress ?? ''
    return LOOPBACK_ADDRESSES.has(remote) && FORWARDING_HEADERS.every((name) => !req.header(name))
}

function safeEqual(a: string, b: string): boolean {
    const left = Buffer.from(a)
    const right = Buffer.from(b)
    return left.length === right.length && timingSafeEqual(left, right)
}

// Gate for the FUNDSFLOW_TOKEN fallback. Without MCP_KEY it serves only
// direct local clients — never the public internet.
function hasMcpKey(req: Request): boolean {
    if (!MCP_KEY) return isDirectLoopback(req)
    const key = req.header('x-fundsflow-mcp-key') || req.query.key
    return typeof key === 'string' && safeEqual(key, MCP_KEY)
}

const signedAmount = (max: number) =>
    z.number().min(-max).max(max).refine((value) => value !== 0, { message: 'amount must not be 0' })
const isoDate = () => z.iso.date()
const uniqueIds = (schema: z.ZodArray<z.ZodNumber>) =>
    schema.refine((ids) => new Set(ids).size === ids.length, { message: 'ids must be unique' })

function createServer(token: string | null, onTool?: (name: string) => void) {
    const server = new McpServer({
        name: 'fundsflow',
        version: MCP_VERSION,
        title: 'FundsFlow',
        description: 'Personal finance via FundsFlow: transactions, tags, budgets, recurring, attachments, and account export.',
        websiteUrl: MCP_WEBSITE_URL,
        icons: MCP_ICONS,
    })

    if (onTool) {
        // Lets the discovery endpoint list tools from registration instead of a copied list.
        const register = server.registerTool.bind(server) as (...args: unknown[]) => unknown
        server.registerTool = ((name: string, ...rest: unknown[]) => {
            onTool(name)
            return register(name, ...rest)
        }) as typeof server.registerTool
    }

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
                    // Omitted parent_id must not reset the tag to top level.
                    body: JSON.stringify(compactPayload(body)),
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
            description: 'List all transactions, newest first (by date).',
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
        amount: signedAmount(TX_AMOUNT_MAX),
        at: isoDate(),
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
                    body: JSON.stringify(
                        compactPayload({
                            amount,
                            at,
                            note,
                            tags: tags ?? [],
                        }),
                    ),
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
                            amount: signedAmount(TX_AMOUNT_MAX),
                            at: isoDate(),
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
                        transactions: transactions.map((row) =>
                            compactPayload({
                                amount: row.amount,
                                at: row.at,
                                note: row.note,
                                tags: row.tags ?? [],
                            }),
                        ),
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
                amount: signedAmount(TX_AMOUNT_MAX),
                at: isoDate(),
                note: z.string().max(255).optional(),
                tags: z.array(z.number().int()).optional(),
            },
        },
        async ({ id, amount, at, note, tags }) =>
            textResult(
                await api(token, `/transactions/${id}`, {
                    // PATCH + omitted tags keeps the current tags (backend syncs only when sent).
                    method: 'PATCH',
                    body: JSON.stringify(
                        compactPayload({
                            amount,
                            at,
                            note,
                            tags,
                        }),
                    ),
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
                            amount: signedAmount(TX_AMOUNT_MAX),
                            at: isoDate(),
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
                        transactions: transactions.map((row) =>
                            compactPayload({
                                id: row.id,
                                amount: row.amount,
                                at: row.at,
                                note: row.note,
                                tags: row.tags ?? [],
                            }),
                        ),
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
                ids: uniqueIds(z.array(z.number().int()).min(1).max(BULK_MAX_ITEMS)),

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
                'Attach a JPEG/PNG/WebP/PDF to a transaction (max 8 MB, max 5 files per transaction). Pass exactly one of content_base64 (raw base64, no data: URL prefix) or source_url (https URL; MCP downloads then uploads). Optional name/mime: derived from URL / Content-Type / magic bytes when omitted.',
            inputSchema: {
                transaction_id: z.number().int(),
                name: z.string().max(255).optional(),
                mime: z.enum(ATTACHMENT_MIMES).optional(),
                content_base64: z.string().min(1).optional(),
                source_url: z.string().url().optional(),
            },
        },
        async ({ transaction_id, name, mime, content_base64, source_url }) => {
            const hasBase64 = Boolean(content_base64?.trim())
            const hasUrl = Boolean(source_url?.trim())
            if (hasBase64 === hasUrl) {
                throw new Error('Provide exactly one of content_base64 or source_url.')
            }

            let content: string
            let finalName = name?.trim() || ''
            let finalMime: AttachmentMime | undefined = mime

            if (hasUrl) {
                const downloaded = await fetchAttachmentFromUrl(source_url!.trim())
                content = downloaded.buffer.toString('base64')
                if (!finalName) finalName = downloaded.name
                if (!finalMime) finalMime = downloaded.mime
            } else {
                content = normalizeBase64(content_base64!)
                if (!finalName) {
                    throw new Error('name is required when using content_base64.')
                }
                if (!finalMime) {
                    throw new Error('mime is required when using content_base64.')
                }
            }

            if (content.length > MAX_ATTACHMENT_BASE64_CHARS) {
                throw new Error('File too large! Max 8 MB decoded.')
            }

            return textResult(
                await api(token, `/transactions/${transaction_id}/attachments/base64`, {
                    method: 'POST',
                    body: JSON.stringify({
                        name: finalName,
                        mime: finalMime,
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
            description:
                'Download an attachment. Images come back as image content plus metadata; other files as JSON with content_base64.',
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
                    signal: AbortSignal.timeout(API_TIMEOUT_MS),
                },
            )

            if (!response.ok) {
                const message = await response.text()
                throw new Error(`FundsFlow API ${response.status}: ${message || response.statusText}`)
            }

            const buffer = Buffer.from(await response.arrayBuffer())
            const mime = response.headers.get('content-type') || 'application/octet-stream'
            const name =
                nameFromContentDisposition(response.headers.get('content-disposition'))
                || `attachment-${attachment_id}`
            const meta = { id: attachment_id, transaction_id, name, mime, size: buffer.length }

            // Images go out as MCP image content so clients can show them / models can see them.
            const imageMime = mime.split(';', 1)[0]?.trim().toLowerCase() || ''
            if (imageMime.startsWith('image/')) {
                return {
                    content: [
                        { type: 'text' as const, text: JSON.stringify(meta, null, 2) },
                        { type: 'image' as const, data: buffer.toString('base64'), mimeType: imageMime },
                    ],
                }
            }

            return textResult({ ...meta, content_base64: buffer.toString('base64') })
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
            description:
                'List all budgets: spending limits (type "limit") and savings goals (type "goal"). Goals include a progress object: saved, target, remaining, percent, status (completed|paused|on_track|behind|in_progress), days_left, required_amount per week|month, projected_date.',
            inputSchema: {},
        },
        async () => textResult(unwrapList(await api(token, '/budgets'))),
    )

    server.registerTool(
        'create_budget',
        {
            title: 'Create budget',
            description:
                'Create a spending limit (type "limit", default; length week|month|year required; optional start_date YYYY-MM-DD, today or earlier, default today: the limit starts that day and its periods repeat from it, e.g. monthly from the 15th runs 15th → 14th; legacy align_to_calendar=true starts it at the beginning of the current calendar week/month/year) or a savings goal (type "goal"; amount is the target, length is ignored). tag_ids required (at least one). Goal progress = start_amount + net of transactions with these tags since start_date (YYYY-MM-DD, default today, may be in the past, not after target_date): expenses (transfers into savings) add, income (withdrawals) subtracts.',
            inputSchema: {
                type: z.enum(['limit', 'goal']).optional(),
                amount: z.number().min(0.01).max(RULE_AMOUNT_MAX),
                length: z.enum(['week', 'month', 'year']).optional(),
                tag_ids: z.array(z.number().int()).min(1),
                title: z.string().max(255).optional(),
                align_to_calendar: z.boolean().optional(),
                start_date: DATE_YMD.nullable().optional(),
                target_date: DATE_YMD.nullable().optional(),
                start_amount: z.number().min(0).max(RULE_AMOUNT_MAX).nullable().optional(),
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
            description:
                'Update a budget by id. The type cannot change. Limits (length required): a changed amount/length/tags closes the current period today and starts a new one on the same schedule (history kept); start_date (today or earlier, not before the previous settings ended) moves the start of the current period in place and its periods then repeat from that day; omitted = keep the current start; on a paused limit saving resumes it from start_date (default today); legacy align_to_calendar=true puts it on calendar weeks/months/years. Goals: edited in place, progress is kept; amount is the target; start_date (today or earlier) moves the day progress counts from and is kept when omitted; target_date/start_amount are left as is when omitted and cleared when null.',
            inputSchema: {
                id: z.number().int(),
                amount: z.number().min(0.01).max(RULE_AMOUNT_MAX),
                length: z.enum(['week', 'month', 'year']).optional(),
                tag_ids: z.array(z.number().int()).min(1),
                title: z.string().max(255).optional(),
                align_to_calendar: z.boolean().optional(),
                start_date: DATE_YMD.nullable().optional(),
                target_date: DATE_YMD.nullable().optional(),
                start_amount: z.number().min(0).max(RULE_AMOUNT_MAX).nullable().optional(),
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
                amount: signedAmount(RULE_AMOUNT_MAX),
                frequency: z.enum(['daily', 'weekly', 'monthly', 'yearly']),
                starts_at: isoDate(),
                note: z.string().max(255).optional(),
                ends_at: isoDate().nullable().optional(),
                active: z.boolean().optional(),
                tags: z.array(z.number().int()).optional(),
            },
        },
        async (args) =>
            textResult(
                await api(token, '/recurring-transactions', {
                    method: 'POST',
                    body: JSON.stringify(
                        compactPayload({
                            ...args,
                            note: args.note,
                            ends_at: args.ends_at,
                            tags: args.tags ?? [],
                        }),
                    ),
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
                amount: signedAmount(RULE_AMOUNT_MAX),
                frequency: z.enum(['daily', 'weekly', 'monthly', 'yearly']),
                starts_at: isoDate(),
                note: z.string().max(255).optional(),
                ends_at: isoDate().nullable().optional(),
                active: z.boolean().optional(),
                tags: z.array(z.number().int()).optional(),
            },
        },
        async ({ id, ...args }) =>
            textResult(
                await api(token, `/recurring-transactions/${id}`, {
                    // PATCH + omitted keys (tags especially) leave stored values untouched.
                    method: 'PATCH',
                    body: JSON.stringify(compactPayload(args)),
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
            description:
                'Update preferences (moneyFormat locale, dateFormat key, decimals, IANA timezone). Only the fields you pass change.',
            inputSchema: {
                moneyFormat: z.string().max(32).optional(),
                dateFormat: z.string().max(64).optional(),
                decimals: z.boolean().optional(),
                timezone: z.string().max(64).optional(),
            },
        },
        async (args) =>
            textResult(
                await api(token, '/account/preferences', {
                    method: 'PATCH',
                    body: JSON.stringify(compactPayload(args)),
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
app.set('trust proxy', /^\d+$/.test(TRUST_PROXY) ? Number(TRUST_PROXY) : TRUST_PROXY === 'false' ? false : TRUST_PROXY)

app.use(express.json({ limit: JSON_BODY_LIMIT }))
app.use(express.urlencoded({ extended: false, limit: JSON_BODY_LIMIT }))
app.use(hostHeaderValidation(ALLOWED_HOSTS))
registerOAuthRoutes(app)

/** Drop keys whose value is undefined so JSON.stringify omits them (no null). */
function compactPayload<T extends Record<string, unknown>>(payload: T): Record<string, unknown> {
    const out: Record<string, unknown> = {}
    for (const [key, value] of Object.entries(payload)) {
        if (value !== undefined) out[key] = value
    }
    return out
}

function normalizeBase64(input: string): string {
    const trimmed = input.trim()
    const dataUrl = /^data:[^;]+;base64,([\s\S]+)$/.exec(trimmed)
    return (dataUrl?.[1] ?? trimmed).replace(/\s+/g, '')
}

function isBlockedAttachmentHost(hostname: string): boolean {
    const host = hostname.toLowerCase().replace(/\.$/, '')
    if (
        host === 'localhost'
        || host.endsWith('.localhost')
        || host === 'metadata.google.internal'
        || host.endsWith('.internal')
        || host.endsWith('.local')
    ) {
        return true
    }

    const ipv4 = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(host)
    if (ipv4) {
        const parts = ipv4.slice(1).map(Number)
        if (parts.some((n) => n > 255)) return true
        const [a = 0, b = 0] = parts
        if (a === 10 || a === 127 || a === 0) return true
        if (a === 169 && b === 254) return true
        if (a === 172 && b >= 16 && b <= 31) return true
        if (a === 192 && b === 168) return true
        if (a === 100 && b >= 64 && b <= 127) return true // carrier-grade NAT
        return false
    }

    // Bracketed or bare IPv6 literals — block loopback / ULA / link-local conservatively.
    if (host.includes(':')) {
        const h = host.replace(/^\[|\]$/g, '')
        if (h === '::1' || h.startsWith('fc') || h.startsWith('fd') || h.startsWith('fe80')) return true
        return true // reject all raw IPv6 for attach downloads
    }

    return false
}

function assertSafeAttachmentUrl(raw: string, base?: URL): URL {
    let url: URL
    try {
        url = base ? new URL(raw, base) : new URL(raw)
    } catch {
        throw new Error('Invalid source_url.')
    }
    if (url.protocol !== 'https:') {
        throw new Error('source_url must use https.')
    }
    if (url.username || url.password) {
        throw new Error('source_url must not include credentials.')
    }
    if (isBlockedAttachmentHost(url.hostname)) {
        throw new Error('source_url host is not allowed.')
    }
    return url
}

function sniffAttachmentMime(buf: Buffer): AttachmentMime | null {
    if (buf.length >= 3 && buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) return 'image/jpeg'
    if (
        buf.length >= 8
        && buf[0] === 0x89
        && buf[1] === 0x50
        && buf[2] === 0x4e
        && buf[3] === 0x47
        && buf[4] === 0x0d
        && buf[5] === 0x0a
        && buf[6] === 0x1a
        && buf[7] === 0x0a
    ) {
        return 'image/png'
    }
    if (
        buf.length >= 12
        && buf.toString('ascii', 0, 4) === 'RIFF'
        && buf.toString('ascii', 8, 12) === 'WEBP'
    ) {
        return 'image/webp'
    }
    if (buf.length >= 5 && buf.toString('ascii', 0, 5) === '%PDF-') return 'application/pdf'
    return null
}

function mimeFromContentType(header: string | null): AttachmentMime | null {
    if (!header) return null
    const raw = header.split(';', 1)[0]?.trim().toLowerCase()
    return (ATTACHMENT_MIMES as readonly string[]).includes(raw || '')
        ? (raw as AttachmentMime)
        : null
}

function nameFromUrlPath(url: URL): string {
    const base = url.pathname.split('/').filter(Boolean).pop() || 'attachment'
    try {
        return decodeURIComponent(base).slice(0, 255) || 'attachment'
    } catch {
        return base.slice(0, 255) || 'attachment'
    }
}

async function fetchAttachmentFromUrl(sourceUrl: string): Promise<{
    buffer: Buffer
    name: string
    mime: AttachmentMime
}> {
    let url = assertSafeAttachmentUrl(sourceUrl)

    for (let hop = 0; hop < 4; hop++) {
        const response = await fetch(url, {
            method: 'GET',
            redirect: 'manual',
            signal: AbortSignal.timeout(60_000),
            headers: {
                Accept: 'image/jpeg,image/png,image/webp,application/pdf,*/*',
            },
        })

        if ([301, 302, 303, 307, 308].includes(response.status)) {
            const location = response.headers.get('location')
            if (!location) throw new Error('source_url redirect missing Location.')
            url = assertSafeAttachmentUrl(location, url)
            continue
        }

        if (!response.ok) {
            throw new Error(`Failed to download source_url (HTTP ${response.status}).`)
        }

        const contentLength = response.headers.get('content-length')
        if (contentLength && Number(contentLength) > MAX_ATTACHMENT_BYTES) {
            throw new Error('File too large! Max 8 MB decoded.')
        }

        const ab = await response.arrayBuffer()
        if (ab.byteLength === 0) throw new Error('source_url returned an empty file.')
        if (ab.byteLength > MAX_ATTACHMENT_BYTES) {
            throw new Error('File too large! Max 8 MB decoded.')
        }

        const buffer = Buffer.from(ab)
        const nameHint = nameFromContentDisposition(response.headers.get('content-disposition'))
        const typeHint = mimeFromContentType(response.headers.get('content-type'))

        const sniffed = sniffAttachmentMime(buffer)
        const mime = sniffed || typeHint
        if (!mime) {
            throw new Error('Could not detect file type. Only JPEG, PNG, WebP, and PDF are allowed.')
        }

        const name = (nameHint || nameFromUrlPath(url)).slice(0, 255)
        return { buffer, name, mime }
    }

    throw new Error('source_url redirected too many times.')
}


const TOOL_NAMES: string[] = []
createServer(null, (name) => TOOL_NAMES.push(name)).close()

app.get('/', (_req, res) => {
    res.json({
        name: 'fundsflow-mcp',
        version: MCP_VERSION,
        title: 'FundsFlow',
        description:
            'Personal finance via FundsFlow: transactions, tags, budgets, recurring, attachments, and account export.',
        websiteUrl: MCP_WEBSITE_URL,
        icons: MCP_ICONS,
        mcp: '/mcp',
        tools: TOOL_NAMES,
        oauth: {
            client_id: oauthClientId,
            authorization_endpoint: `${oauthPublicBase}/oauth/authorize`,
            token_endpoint: `${oauthPublicBase}/oauth/token`,
            scopes: ['mcp'],
            token_endpoint_auth_method: oauthClientSecret ? 'client_secret_post' : 'none',
        },
    })
})

const TOKEN_CHECK_TTL_MS = 60_000
const tokenChecks = new Map<string, { valid: boolean; expiresAt: number }>()

async function isTokenValid(token: string): Promise<boolean> {
    const key = createHash('sha256').update(token).digest('hex')
    const now = Date.now()
    const cached = tokenChecks.get(key)
    if (cached && cached.expiresAt > now) return cached.valid

    let valid: boolean
    try {
        const response = await fetch(`${API_URL}/auth/me`, {
            headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
            signal: AbortSignal.timeout(10_000),
        })
        // Only a definite 401 means "re-auth"; backend hiccups should not log users out.
        valid = response.status !== 401
        if (!valid || response.ok) tokenChecks.set(key, { valid, expiresAt: now + TOKEN_CHECK_TTL_MS })
    } catch {
        return true
    }

    if (tokenChecks.size > 5000) {
        for (const [k, row] of tokenChecks) if (row.expiresAt <= now) tokenChecks.delete(k)
    }
    return valid
}

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


    // A revoked/expired token must surface as HTTP 401 so clients re-run OAuth,
    // instead of every tool call failing with an opaque tool error.
    if (userToken && !isPublicMcpMethod(req.body) && !(await isTokenValid(userToken))) {
        unauthorizedMcp(res, req.body?.id ?? null)
        return
    }

    const server = createServer(token)
    try {
        const transport = new StreamableHTTPServerTransport({
            sessionIdGenerator: undefined,
        })
        // Register before handling: a client that disconnects mid-request would
        // otherwise never trigger cleanup.
        res.on('close', () => {
            transport.close()
            server.close()
        })
        await server.connect(transport)
        await transport.handleRequest(req, res, req.body)
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
    if (ENV_TOKEN && !MCP_KEY) {
        console.warn('FUNDSFLOW_TOKEN fallback is limited to direct loopback clients; set MCP_KEY to use it remotely.')
    }
})
