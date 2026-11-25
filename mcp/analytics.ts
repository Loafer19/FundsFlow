// Pure helpers for MCP analytics tools. Kept free of I/O so agent-test can check them
// against fixtures. Period math uses calendar days (UTC date arithmetic) and a
// precomputed "today" in the user's timezone — the same whole-day idea as PeriodRange.

export const PERIOD_PRESETS = [
    'this_week',
    'last_week',
    'this_month',
    'last_month',
    'last_7_days',
    'last_30_days',
    'this_quarter',
    'last_quarter',
    'this_year',
    'last_year',
] as const

export type PeriodPreset = (typeof PERIOD_PRESETS)[number]

export type TagRecord = {
    id: number
    parent_id: number | null
    title: string
    emoji?: string | null
    calc_balance?: boolean
    archived_at?: string | null
}

export type TransactionRecord = {
    id: number
    at: string
    amount: number
    transaction_type_id?: number | null
    tags?: { id: number }[] | null
}

export type TagBalanceRow = {
    id: number
    parent_id: number | null
    title: string
    emoji: string | null
    income: number
    expense: number
    net: number
    transactions: number
}

export type FlowTotals = {
    income: number
    expense: number
    net: number
    transactions: number
}

export type PeriodBounds = {
    from: string
    to: string
    label: string
    preset: PeriodPreset | null
    previousFrom: string
    previousTo: string
}

const YMD = /^\d{4}-\d{2}-\d{2}/

export const money = (value: number): number => Math.round(value * 100) / 100

export const transactionDay = (at: unknown): string => {
    if (typeof at === 'string' && YMD.test(at)) return at.slice(0, 10)
    if (at instanceof Date && !Number.isNaN(at.getTime())) return at.toISOString().slice(0, 10)

    return ''
}

const parseYmd = (value: string): Date => {
    const parts = value.split('-').map(Number)
    const year = parts[0] ?? 0
    const month = parts[1] ?? 1
    const day = parts[2] ?? 1

    return new Date(Date.UTC(year, month - 1, day))
}

const formatYmd = (date: Date): string => date.toISOString().slice(0, 10)

const addDays = (date: Date, days: number): Date => {
    const next = new Date(date)
    next.setUTCDate(next.getUTCDate() + days)

    return next
}

const startOfWeek = (date: Date): Date => {
    // ISO / Carbon default: Monday.
    const day = date.getUTCDay()
    const fromMonday = day === 0 ? 6 : day - 1

    return addDays(date, -fromMonday)
}

const startOfMonth = (date: Date): Date => new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1))

const endOfMonth = (date: Date): Date => new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0))

const startOfQuarter = (date: Date): Date => {
    const month = Math.floor(date.getUTCMonth() / 3) * 3

    return new Date(Date.UTC(date.getUTCFullYear(), month, 1))
}

const endOfQuarter = (date: Date): Date => {
    const month = Math.floor(date.getUTCMonth() / 3) * 3

    return new Date(Date.UTC(date.getUTCFullYear(), month + 3, 0))
}

const startOfYear = (date: Date): Date => new Date(Date.UTC(date.getUTCFullYear(), 0, 1))

const endOfYear = (date: Date): Date => new Date(Date.UTC(date.getUTCFullYear(), 11, 31))

const monthLabel = (date: Date): string =>
    date.toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' })

const quarterLabel = (date: Date): string => `Q${Math.floor(date.getUTCMonth() / 3) + 1} ${date.getUTCFullYear()}`

export const todayInTimeZone = (timeZone: string, now = new Date()): string => {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(now)
    } catch {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: 'UTC',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(now)
    }
}

export const resolvePeriod = (
    todayYmd: string,
    args: { period?: PeriodPreset | string; from?: string; to?: string },
    defaultPreset: PeriodPreset = 'this_month',
): PeriodBounds => {
    if ((args.from && YMD.test(args.from)) || (args.to && YMD.test(args.to))) {
        const today = parseYmd(todayYmd)
        let from = args.from && YMD.test(args.from) ? parseYmd(args.from.slice(0, 10)) : startOfMonth(today)
        let to = args.to && YMD.test(args.to) ? parseYmd(args.to.slice(0, 10)) : today
        if (from > to) [from, to] = [to, from]
        const days = Math.round((to.getTime() - from.getTime()) / 86_400_000) + 1
        const previousTo = addDays(from, -1)
        const previousFrom = addDays(previousTo, -(days - 1))

        return {
            from: formatYmd(from),
            to: formatYmd(to),
            label: 'Custom period',
            preset: null,
            previousFrom: formatYmd(previousFrom),
            previousTo: formatYmd(previousTo),
        }
    }

    const today = parseYmd(todayYmd)
    const preset = (PERIOD_PRESETS as readonly string[]).includes(args.period ?? '')
        ? (args.period as PeriodPreset)
        : defaultPreset

    const of = (from: Date, to: Date, label: string, previousFrom: Date, previousTo: Date): PeriodBounds => ({
        from: formatYmd(from),
        to: formatYmd(to),
        label,
        preset,
        previousFrom: formatYmd(previousFrom),
        previousTo: formatYmd(previousTo),
    })

    switch (preset) {
        case 'this_week': {
            const start = startOfWeek(today)
            const prev = addDays(start, -7)

            return of(start, today, 'This week', prev, addDays(prev, 6))
        }
        case 'last_week': {
            const start = addDays(startOfWeek(today), -7)
            const prev = addDays(start, -7)

            return of(start, addDays(start, 6), 'Last week', prev, addDays(prev, 6))
        }
        case 'this_month': {
            const start = startOfMonth(today)
            const prev = startOfMonth(addDays(start, -1))

            return of(start, today, `This month (${monthLabel(start)})`, prev, endOfMonth(prev))
        }
        case 'last_month': {
            const start = startOfMonth(addDays(startOfMonth(today), -1))
            const prev = startOfMonth(addDays(start, -1))

            return of(start, endOfMonth(start), `Last month (${monthLabel(start)})`, prev, endOfMonth(prev))
        }
        case 'last_7_days':
            return of(addDays(today, -6), today, 'Last 7 days', addDays(today, -13), addDays(today, -7))
        case 'last_30_days':
            return of(addDays(today, -29), today, 'Last 30 days', addDays(today, -59), addDays(today, -30))
        case 'this_quarter': {
            const start = startOfQuarter(today)
            const prev = startOfQuarter(addDays(start, -1))

            return of(start, today, `This quarter (${quarterLabel(start)})`, prev, endOfQuarter(prev))
        }
        case 'last_quarter': {
            const start = startOfQuarter(addDays(startOfQuarter(today), -1))
            const prev = startOfQuarter(addDays(start, -1))

            return of(start, endOfQuarter(start), `Last quarter (${quarterLabel(start)})`, prev, endOfQuarter(prev))
        }
        case 'this_year': {
            const start = startOfYear(today)
            const prev = startOfYear(addDays(start, -1))

            return of(start, today, `This year (${start.getUTCFullYear()})`, prev, endOfYear(prev))
        }
        case 'last_year': {
            const start = startOfYear(addDays(startOfYear(today), -1))
            const prev = startOfYear(addDays(start, -1))

            return of(start, endOfYear(start), `Last year (${start.getUTCFullYear()})`, prev, endOfYear(prev))
        }
    }
}

export const descendantIds = (tags: TagRecord[], rootId: number): Set<number> => {
    const ids = new Set([rootId])
    let grew = true

    while (grew) {
        grew = false
        for (const tag of tags) {
            if (tag.parent_id != null && ids.has(tag.parent_id) && !ids.has(tag.id)) {
                ids.add(tag.id)
                grew = true
            }
        }
    }

    return ids
}

export const expandTagIds = (tags: TagRecord[], ids: number[]): Set<number> => {
    const out = new Set<number>()
    for (const id of ids) for (const descendant of descendantIds(tags, id)) out.add(descendant)

    return out
}

export const activeTagTree = (tags: TagRecord[]): TagRecord[] => {
    const live = tags.filter((tag) => !tag.archived_at)
    const walk = (parentId: number | null): TagRecord[] => {
        const children = live
            .filter((tag) => tag.parent_id === parentId)
            .sort((a, b) => a.title.localeCompare(b.title))
        const rows: TagRecord[] = []
        for (const child of children) {
            rows.push(child, ...walk(child.id))
        }

        return rows
    }

    return walk(null)
}

export const resolveTypeId = (
    types: { id: number; name: string }[],
    type: string | number | undefined,
): number | undefined => {
    if (type == null || type === '') return undefined
    if (typeof type === 'number' || /^\d+$/.test(String(type))) {
        const id = Number(type)
        if (types.some((item) => item.id === id)) return id
        throw new Error(`Unknown transaction type id ${id}. Use list_transaction_types.`)
    }
    const needle = String(type).trim().toLowerCase()
    const matches = types.filter((item) => item.name.toLowerCase() === needle)
    if (matches.length === 1) return matches[0]?.id
    if (matches.length > 1) {
        throw new Error(`Several transaction types are named "${type}". Pass the id from list_transaction_types.`)
    }
    throw new Error(`Unknown transaction type "${type}". Use list_transaction_types.`)
}

export const filterTransactions = (
    transactions: TransactionRecord[],
    opts: { from?: string; to?: string; typeId?: number; tagIds?: number[]; tags?: TagRecord[] } = {},
): TransactionRecord[] => {
    const selected = opts.tagIds?.length ? expandTagIds(opts.tags ?? [], opts.tagIds) : null

    return transactions.filter((transaction) => {
        const day = transactionDay(transaction.at)
        if (opts.from && day < opts.from) return false
        if (opts.to && day > opts.to) return false
        if (opts.typeId != null && transaction.transaction_type_id !== opts.typeId) return false
        if (selected) {
            const tagList = transaction.tags ?? []
            if (!tagList.some((tag) => selected.has(tag.id))) return false
        }

        return true
    })
}

export const flowTotals = (transactions: TransactionRecord[]): FlowTotals => {
    let income = 0
    let expense = 0
    for (const transaction of transactions) {
        if (transaction.amount > 0) income += transaction.amount
        else expense += transaction.amount
    }

    return {
        income: money(income),
        expense: money(expense),
        net: money(income + expense),
        transactions: transactions.length,
    }
}

// Same rules as the Analytics "Balances Per Tag" card: only tags with Calculate
// balance, a parent includes its children, each transaction is counted once per row.
export const computeTagBalances = (
    tags: TagRecord[],
    transactions: TransactionRecord[],
    opts: { tagIds?: number[] } = {},
): TagBalanceRow[] => {
    const grouped = new Map<number, TransactionRecord[]>()
    for (const transaction of transactions) {
        for (const tag of transaction.tags ?? []) {
            const list = grouped.get(tag.id) ?? []
            list.push(transaction)
            grouped.set(tag.id, list)
        }
    }

    const wanted = opts.tagIds?.length ? new Set(opts.tagIds) : null
    const rows: TagBalanceRow[] = []

    for (const tag of activeTagTree(tags)) {
        if (!tag.calc_balance) continue
        if (wanted && !wanted.has(tag.id)) continue

        const seen = new Set<number>()
        let income = 0
        let expense = 0

        for (const id of descendantIds(tags, tag.id)) {
            for (const transaction of grouped.get(id) ?? []) {
                if (seen.has(transaction.id)) continue
                seen.add(transaction.id)
                if (transaction.amount > 0) income += transaction.amount
                else expense += transaction.amount
            }
        }

        rows.push({
            id: tag.id,
            parent_id: tag.parent_id,
            title: tag.title,
            emoji: tag.emoji ?? null,
            income: money(income),
            expense: money(expense),
            net: money(income + expense),
            transactions: seen.size,
        })
    }

    return rows
}
