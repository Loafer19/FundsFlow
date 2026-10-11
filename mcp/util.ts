// Pure helpers shared by index.ts; kept side-effect free so they can be unit-checked.

export function nameFromContentDisposition(header: string | null): string | null {
    if (!header) return null
    const star = /filename\*=(?:UTF-8''|utf-8'')([^;]+)/i.exec(header)
    if (star?.[1]) {
        try {
            return decodeURIComponent(star[1].trim().replace(/^"|"$/g, ''))
        } catch {
            /* fall back to plain filename */
        }
    }
    const plain = /filename="([^"]+)"|filename=([^;]+)/i.exec(header)
    const value = (plain?.[1] || plain?.[2] || '').trim().replace(/^"|"$/g, '')
    return value || null
}

/**
 * Laravel 422 bodies carry per-field `errors` (e.g. transactions.3.amount); keeping them
 * lets the model see which bulk row failed instead of only the summary message.
 */
export function formatApiError(status: number, body: unknown, text: string, statusText: string): string {
    let message = text || statusText
    const lines: string[] = []
    if (body && typeof body === 'object') {
        const { message: msg, errors } = body as { message?: unknown; errors?: unknown }
        if (msg !== undefined) message = String(msg)
        if (errors && typeof errors === 'object') {
            for (const [field, value] of Object.entries(errors as Record<string, unknown>)) {
                const list = Array.isArray(value) ? value.map(String) : [String(value)]
                lines.push(`- ${field}: ${list.join(' ')}`)
            }
        }
    }
    return [`FundsFlow API ${status}: ${message}`, ...lines].join('\n')
}

export function unwrapList(payload: unknown): unknown[] {
    if (Array.isArray(payload)) return payload
    if (payload && typeof payload === 'object' && 'data' in payload) {
        const data = (payload as { data: unknown }).data
        if (Array.isArray(data)) return data
    }
    // Silently returning [] would read as "no records" and mislead the model.
    throw new Error('FundsFlow API returned an unexpected payload (expected a list).')
}
