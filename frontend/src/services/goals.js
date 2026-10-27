import { nowDateStr, parseLocalDate, toLocalDateStr } from './formatters.js'

// Savings goal progress — a client-side mirror of
// backend/app/Actions/Budgets/CalculateGoalProgressAction.php so the card
// reacts to new transactions without a refetch (limits work the same way).
//
// Sign rule: contributions are transactions carrying ANY of the goal's tags
// (exact tags, counted once) from the goal start through today. Money moved
// into savings is logged as an expense, so saved = -(net of those
// transactions): a -500 transfer adds 500, a +200 withdrawal takes 200 off.
// Progress = start_amount + saved, never below zero.

const PACE_WINDOW_DAYS = 90
const MIN_PACE_DAYS = 30
const DAYS_PER_MONTH = 30.4375
const WEEKLY_BELOW_DAYS = 61

const round2 = (value) => Math.round(value * 100) / 100

const addDays = (dateStr, days) => {
    const d = parseLocalDate(dateStr)
    d.setDate(d.getDate() + days)

    return toLocalDateStr(d)
}

// Y-m-d strings compare lexicographically (Math.min/max would coerce them to NaN).
const earliest = (dates) => [...dates].sort()[0]
const latestOf = (a, b) => [a, b].sort()[1]

const daysBetween = (start, end) => Math.round((parseLocalDate(end) - parseLocalDate(start)) / 86400000)

export const isGoal = (budget) => budget?.type === 'goal'

const contributedBetween = (transactions, tagIds, from, to) => {
    if (!tagIds.size || from > to) return 0

    let sum = 0

    for (const t of transactions) {
        const date = toLocalDateStr(t.at)

        if (date < from || date > to) continue
        if (!t.tags?.some((tag) => tagIds.has(tag.id))) continue

        sum += Number(t.amount)
    }

    return round2(-sum)
}

/**
 * @param {object} budget  BudgetResource with type "goal"
 * @param {Array} transactions  all loaded transactions
 * @param {string} [today]  Y-m-d in the user's timezone
 */
export const goalProgress = (budget, transactions, today = nowDateStr()) => {
    const periods = budget.periods ?? []
    const current = periods.find((period) => period.active) ?? null
    const latest = current ?? periods[0] ?? null
    const startedAt = earliest([today, ...periods.map((p) => p.starts_at).filter(Boolean)])

    const tagIds = new Set((latest?.tags ?? []).map((tag) => tag.id))
    const target = Number(latest?.amount ?? 0)
    const startAmount = Number(budget.start_amount ?? 0)

    const windowStart = latestOf(addDays(today, -(PACE_WINDOW_DAYS - 1)), startedAt)

    const contributed = contributedBetween(transactions, tagIds, startedAt, today)
    const windowContributed =
        windowStart === startedAt ? contributed : contributedBetween(transactions, tagIds, windowStart, today)

    const saved = Math.max(0, round2(startAmount + contributed))
    const remaining = Math.max(0, round2(target - saved))
    const completed = target > 0 && saved >= target

    const windowDays = Math.max(MIN_PACE_DAYS, daysBetween(windowStart, today) + 1)
    const perDay = windowContributed > 0 ? windowContributed / windowDays : 0
    const projectedDate = !completed && perDay > 0 ? addDays(today, Math.ceil(remaining / perDay)) : null

    const deadline = budget.target_date ?? null
    const daysLeftRaw = deadline ? daysBetween(today, deadline) : null
    const overdue = daysLeftRaw !== null && daysLeftRaw < 0 && !completed

    let requiredAmount = null
    let requiredPer = null

    if (deadline && !completed && !overdue && remaining > 0) {
        const left = Math.max(1, daysLeftRaw)
        requiredPer = left < WEEKLY_BELOW_DAYS ? 'week' : 'month'
        const units = requiredPer === 'week' ? Math.max(1, left / 7) : left / DAYS_PER_MONTH
        requiredAmount = round2(remaining / units)
    }

    let status = 'behind'
    if (completed) status = 'completed'
    else if (!current) status = 'paused'
    else if (!deadline) status = 'in_progress'
    else if (!overdue && projectedDate && projectedDate <= deadline) status = 'on_track'

    return {
        target,
        startAmount,
        contributed,
        saved,
        remaining,
        percent: target > 0 ? Math.floor(Math.min(saved / target, 9.99) * 100) : 0,
        completed,
        status,
        startedAt,
        targetDate: deadline,
        daysLeft: daysLeftRaw === null ? null : Math.max(0, daysLeftRaw),
        overdue,
        requiredAmount,
        requiredPer,
        monthlyPace: round2(perDay * DAYS_PER_MONTH),
        projectedDate,
        tags: latest?.tags ?? [],
    }
}

export const goalStatusLabel = (status) =>
    ({
        completed: 'Completed',
        paused: 'Paused',
        on_track: 'On track',
        behind: 'Behind',
        in_progress: 'In progress',
    })[status] ?? status

export const formatMonthYear = (dateStr) =>
    parseLocalDate(dateStr).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
