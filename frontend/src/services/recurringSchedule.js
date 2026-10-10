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

const daysBetween = (fromStr, toStr) => Math.round((parseLocalDate(toStr) - parseLocalDate(fromStr)) / 86_400_000)

// Index of the first occurrence on or after dateStr, counted from the anchor.
// Mirrors RecurringFrequency::firstOnOrAfter() on the backend.
const firstIndexOnOrAfter = (anchorStr, frequency, dateStr) => {
    if (dateStr <= anchorStr) return 0

    const [anchorYear, anchorMonth] = anchorStr.split('-').map(Number)
    const [year, month] = dateStr.split('-').map(Number)

    let n = 0
    if (frequency === 'daily') n = daysBetween(anchorStr, dateStr)
    else if (frequency === 'weekly') n = Math.floor(daysBetween(anchorStr, dateStr) / 7)
    else if (frequency === 'monthly') n = (year - anchorYear) * 12 + (month - anchorMonth) - 1
    else n = year - anchorYear - 1

    n = Math.max(0, n)
    while (advanceDateStr(anchorStr, frequency, n) < dateStr) n++

    return n
}

const MAX_OCCURRENCES = 500

// Occurrences are always counted from starts_at, so month-end rules stay on
// the 31st/30th instead of drifting to the 28th after February.
export const projectOccurrences = (rule, rangeStart, rangeEnd) => {
    if (!rule.active) return []

    const startStr = toLocalDateStr(rangeStart)
    const endStr = toLocalDateStr(rangeEnd)
    const anchor = rule.starts_at || rule.next_run_at
    const from = rule.next_run_at > startStr ? rule.next_run_at : startStr

    const dates = []
    let n = firstIndexOnOrAfter(anchor, rule.frequency, from)
    let cursor = advanceDateStr(anchor, rule.frequency, n)

    while (cursor <= endStr && dates.length < MAX_OCCURRENCES) {
        if (rule.ends_at && cursor > rule.ends_at) break
        dates.push(cursor)

        n++
        cursor = advanceDateStr(anchor, rule.frequency, n)
    }

    return dates
}
