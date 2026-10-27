// Spending-limit maths for the Budgets tab (mirrors BudgetLength::bucketBounds,
// LimitBuckets and CalculateLimitStatusAction). All dates
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

// Versions sorted by start (ties by id), mirroring LimitBuckets::sorted.
const sortedPeriods = (periods) =>
    [...periods].sort((a, b) => (a.starts_at === b.starts_at ? a.id - b.id : a.starts_at < b.starts_at ? -1 : 1))

// Edits (amount, length, tags) close the open version and open a new one the same day; a
// pause leaves a gap. previous() is the closed version this one directly continues, if any.
const previousPeriod = (period, periods) => {
    const prev = sortedPeriods(periods)
        .filter(
            (p) =>
                p.id !== period.id &&
                p.ends_at &&
                (p.starts_at < period.starts_at || (p.starts_at === period.starts_at && p.id < period.id)),
        )
        .at(-1)

    return prev && prev.ends_at >= dayBefore(period.starts_at) ? prev : null
}

// First day of the run of back-to-back versions the period belongs to.
export const chainStart = (period, periods) => {
    let current = period
    let prev = previousPeriod(current, periods)

    for (let guard = 0; prev && guard < 1000; guard++) {
        current = prev
        prev = previousPeriod(current, periods)
    }

    return current.starts_at
}

// A version counts from the start of the bucket containing its starts_at (so a mid-period
// edit applies to the whole active period), never before the start of its run.
export const effectiveStart = (period, periods, anchor = null) =>
    [periodBucket(period, anchor, period.starts_at).start, chainStart(period, periods)].sort()[1]

// Last day a closed version owns: ends_at, or the day before the next back-to-back
// version's effective start when that is earlier (no day is counted twice).
export const closedEnd = (period, periods, anchor = null) => {
    if (!period.ends_at) return null

    const next = sortedPeriods(periods).find(
        (p) => p.id !== period.id && p.starts_at >= period.starts_at && previousPeriod(p, periods)?.id === period.id,
    )

    return next ? [period.ends_at, dayBefore(effectiveStart(next, periods, anchor))].sort()[0] : period.ends_at
}

// periods: all versions of the budget (budget.periods); anchor: budget.anchor_date.
export const currentBucketStart = (period, periods, anchor = null, today = nowDateStr()) =>
    // The later of the bucket boundary and the version's effective start (ISO strings sort as dates).
    [periodBucket(period, anchor, today).start, effectiveStart(period, periods, anchor)].sort()[1]

export const currentBucketEnd = (period, anchor = null, today = nowDateStr()) => periodBucket(period, anchor, today).end

// Slices a version's finished range into week/month/year buckets on the budget's grid,
// clamped at both ends, so a limit that ran unedited for months shows one comparable row per
// bucket instead of one misleading total. A closed version runs from its effective start to
// its closedEnd (empty when a later edit took over its only bucket); the open one contributes
// the buckets before the current one.
export const historyBuckets = (period, periods, anchor = null, today = nowDateStr()) => {
    const buckets = []
    const last = period.ends_at
        ? closedEnd(period, periods, anchor)
        : dayBefore(currentBucketStart(period, periods, anchor, today))
    let cursor = effectiveStart(period, periods, anchor)

    while (cursor <= last) {
        const { next } = periodBucket(period, anchor, cursor)
        const end = next <= last ? dayBefore(next) : last

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

// Rounded down (99.6% spent is not 100%), like CalculateLimitStatusAction::percent.
export const limitPercent = (spent, amount) =>
    amount > 0 ? Math.floor(Math.round((spent / amount) * 100 * 1e6) / 1e6) : 0

export const limitStatus = (spent, amount) => {
    if (amount > 0 && spent > amount) return 'over'
    if (amount > 0 && spent >= amount * NEAR_LIMIT_RATIO) return 'near'

    return 'on_track'
}

// Current-bucket snapshot of an active limit period.
// anchor: budget.anchor_date (null = calendar weeks/months/years).
// periods: all versions of the budget, so spending since the start of the active period
// counts even when the limit was edited mid-period (matches CalculateLimitStatusAction).
export const limitProgress = (period, periods, transactionsStore, anchor = null, today = nowDateStr()) => {
    const start = currentBucketStart(period, periods, anchor, today)
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
        percent: limitPercent(spent, amount),
        status: limitStatus(spent, amount),
        totalDays,
        elapsedDays,
        daysLeft,
        perDay: left > 0 ? left / daysLeft : 0,
        projected: (spent / elapsedDays) * totalDays,
    }
}
