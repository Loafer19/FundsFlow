import { parseLocalDate, toLocalDateStr } from './formatters.js'

const addMonthsNoOverflow = (date, months) => {
    const day = date.getDate()
    const d = new Date(date)
    d.setDate(1)
    d.setMonth(d.getMonth() + months)
    d.setDate(Math.min(day, new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()))

    return d
}

const addYearsNoOverflow = (date, years) => {
    const day = date.getDate()
    const d = new Date(date)
    d.setDate(1)
    d.setFullYear(d.getFullYear() + years)
    d.setDate(Math.min(day, new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()))

    return d
}

export const advanceDateStr = (dateStr, frequency, steps = 1) => {
    const d = parseLocalDate(dateStr)

    if (frequency === 'daily') d.setDate(d.getDate() + steps)
    else if (frequency === 'weekly') d.setDate(d.getDate() + steps * 7)
    else if (frequency === 'monthly') return toLocalDateStr(addMonthsNoOverflow(d, steps))
    else return toLocalDateStr(addYearsNoOverflow(d, steps))

    return toLocalDateStr(d)
}

const MAX_OCCURRENCES = 500

export const projectOccurrences = (rule, rangeStart, rangeEnd) => {
    if (!rule.active) return []

    const startStr = toLocalDateStr(rangeStart)
    const endStr = toLocalDateStr(rangeEnd)

    const dates = []
    let cursor = rule.next_run_at
    let steps = 0

    while (cursor <= endStr && steps < MAX_OCCURRENCES) {
        if (rule.ends_at && cursor > rule.ends_at) break
        if (cursor >= startStr) dates.push(cursor)

        cursor = advanceDateStr(cursor, rule.frequency, 1)
        steps++
    }

    return dates
}
