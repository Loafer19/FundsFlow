// Spending-limit maths for the Budgets tab (mirrors CalculateBudgetPeriodSpentAction
// and AgentPlanningTools::budgetPeriodStatus). All dates are 'YYYY-MM-DD' strings
// in the user's timezone; a bucket is the current calendar week/month/year of a period.
import { nowDateStr, parseLocalDate, toLocalDateStr } from './formatters.js'

export const NEAR_LIMIT_RATIO = 0.8

const bucketStartFor = (date, length) => {
    const d = new Date(date)

    if (length === 'week') d.setDate(d.getDate() - ((d.getDay() + 6) % 7))
    else if (length === 'month') d.setDate(1)
    else d.setMonth(0, 1)

    return d
}

const nextBucketStart = (date, length) => {
    const d = new Date(date)

    if (length === 'week') d.setDate(d.getDate() + 7)
    else if (length === 'month') d.setMonth(d.getMonth() + 1)
    else d.setFullYear(d.getFullYear() + 1)

    return d
}

const dayBefore = (dateStr) => {
    const d = parseLocalDate(dateStr)
    d.setDate(d.getDate() - 1)

    return toLocalDateStr(d)
}

export const daysBetween = (start, end) => Math.round((parseLocalDate(end) - parseLocalDate(start)) / 86400000)

export const currentBucketStart = (period, today = nowDateStr()) => {
    const boundary = toLocalDateStr(bucketStartFor(parseLocalDate(today), period.length))

    // The later of the calendar boundary and the period start (ISO date strings sort as dates).
    return [boundary, period.starts_at].sort()[1]
}

export const currentBucketEnd = (period, today = nowDateStr()) =>
    dayBefore(toLocalDateStr(nextBucketStart(bucketStartFor(parseLocalDate(today), period.length), period.length)))

// Slices a closed period's whole [starts_at, ends_at] range into calendar-aligned
// week/month/year buckets, clamped at both ends, so a budget that ran unedited for
// months shows one comparable row per bucket instead of one misleading total.
export const historyBuckets = (period) => {
    const buckets = []
    let cursor = period.starts_at

    while (cursor <= period.ends_at) {
        const nextStr = toLocalDateStr(
            nextBucketStart(bucketStartFor(parseLocalDate(cursor), period.length), period.length),
        )
        const end = nextStr <= period.ends_at ? dayBefore(nextStr) : period.ends_at

        buckets.push({ start: cursor, end })
        cursor = nextStr
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
export const limitProgress = (period, transactionsStore, today = nowDateStr()) => {
    const start = currentBucketStart(period, today)
    const end = currentBucketEnd(period, today)
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
