// Spending-limit maths for the Budgets tab (mirrors BudgetLength::bucketBounds,
// CalculateBudgetPeriodSpentAction and AgentPlanningTools::budgetPeriodStatus). All dates
// are 'YYYY-MM-DD' strings in the user's timezone. Buckets of all of a budget's periods
// repeat from budget.anchor_date (monthly from the 15th runs 15th → 14th); a null anchor
// (older limits) means calendar weeks (Mon–Sun), months and years.
import { nowDateStr, parseLocalDate, toLocalDateStr } from './formatters.js'

export const NEAR_LIMIT_RATIO = 0.8

// A Monday and a January 1st: anchoring on it gives calendar weeks, months and years.
export const CALENDAR_ANCHOR = '2001-01-01'

const daysInMonth = (year, month) => new Date(year, month + 1, 0).getDate()

// anchor + n weeks/months/years; months and years never overflow (Jan 31 + 1 month = Feb 28/29).
const shift = (anchorStr, length, n) => {
    const d = parseLocalDate(anchorStr)

    if (length === 'week') {
        d.setDate(d.getDate() + 7 * n)

        return toLocalDateStr(d)
    }

    const day = d.getDate()
    d.setDate(1)
    d.setMonth(d.getMonth() + (length === 'year' ? 12 * n : n))
    d.setDate(Math.min(day, daysInMonth(d.getFullYear(), d.getMonth())))

    return toLocalDateStr(d)
}

const dayBefore = (dateStr) => {
    const d = parseLocalDate(dateStr)
    d.setDate(d.getDate() - 1)

    return toLocalDateStr(d)
}

export const daysBetween = (start, end) => Math.round((parseLocalDate(end) - parseLocalDate(start)) / 86400000)

// { start, end, next } of the bucket containing dateStr on the grid repeating from anchorStr.
export const bucketBounds = (dateStr, length, anchorStr = null) => {
    const anchor = anchorStr || CALENDAR_ANCHOR
    const [dy, dm] = dateStr.split('-').map(Number)
    const [ay, am] = anchor.split('-').map(Number)
    let n =
        length === 'week'
            ? Math.floor(daysBetween(anchor, dateStr) / 7)
            : length === 'month'
              ? (dy - ay) * 12 + dm - am
              : dy - ay

    while (shift(anchor, length, n) > dateStr) n--
    while (shift(anchor, length, n + 1) <= dateStr) n++

    const next = shift(anchor, length, n + 1)

    return { start: shift(anchor, length, n), end: dayBefore(next), next }
}

const periodBucket = (period, anchor, dateStr) => bucketBounds(dateStr, period.length, anchor)

export const currentBucketStart = (period, anchor = null, today = nowDateStr()) =>
    // The later of the bucket boundary and the period start (ISO date strings sort as dates).
    [periodBucket(period, anchor, today).start, period.starts_at].sort()[1]

export const currentBucketEnd = (period, anchor = null, today = nowDateStr()) => periodBucket(period, anchor, today).end

// Slices a closed period's whole [starts_at, ends_at] range into week/month/year buckets
// on the budget's grid, clamped at both ends, so a budget that ran unedited for months
// shows one comparable row per bucket instead of one misleading total.
export const historyBuckets = (period, anchor = null) => {
    const buckets = []
    let cursor = period.starts_at

    while (cursor <= period.ends_at) {
        const { next } = periodBucket(period, anchor, cursor)
        const end = next <= period.ends_at ? dayBefore(next) : period.ends_at

        buckets.push({ start: cursor, end })
        cursor = next
    }

    return buckets
}

// Expenses (negative amounts) carrying any of the tags, counted once, on days [start, end].
export const spentBetween = (transactionsStore, start, end, tags) => {
    const tagIds = new Set(tags.map((tag) => tag.id))

    return transactionsStore
        .filteredByDateRange(start, end)
        .filter((t) => t.amount < 0 && t.tags.some((tag) => tagIds.has(tag.id)))
        .reduce((sum, t) => sum - t.amount, 0)
}

export const limitStatus = (spent, amount) => {
    if (amount > 0 && spent > amount) return 'over'
    if (amount > 0 && spent >= amount * NEAR_LIMIT_RATIO) return 'near'

    return 'on_track'
}

// Current-bucket snapshot of an active limit period.
// anchor: budget.anchor_date (null = calendar weeks/months/years).
export const limitProgress = (period, transactionsStore, anchor = null, today = nowDateStr()) => {
    const start = currentBucketStart(period, anchor, today)
    const end = currentBucketEnd(period, anchor, today)
    const amount = Number(period.amount)
    const spent = spentBetween(transactionsStore, start, today, period.tags)
    const totalDays = Math.max(1, daysBetween(start, end) + 1)
    const elapsedDays = Math.max(1, Math.min(totalDays, daysBetween(start, today) + 1))
    const daysLeft = Math.max(1, totalDays - elapsedDays + 1) // today still counts as a day to spend
    const left = amount - spent

    return {
        start,
        end,
        amount,
        spent,
        left,
        percent: amount > 0 ? Math.round((spent / amount) * 100) : 0,
        status: limitStatus(spent, amount),
        totalDays,
        elapsedDays,
        daysLeft,
        perDay: left > 0 ? left / daysLeft : 0,
        projected: (spent / elapsedDays) * totalDays,
    }
}
