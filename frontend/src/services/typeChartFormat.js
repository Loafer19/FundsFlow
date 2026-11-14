// Short month labels and period presets for the Type analytics panel.
export const MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

export const monthShort = (value) => {
    const match = String(value).match(/^(\d{4})-(\d{2})$/)
    if (!match) return String(value)
    return MONTH_SHORT[Number(match[2]) - 1] || match[2]
}

export const monthRangeLabel = (months) => {
    if (!months?.length) return ''
    const first = String(months[0].month ?? months[0])
    const last = String(months[months.length - 1].month ?? months[months.length - 1])
    const a = first.match(/^(\d{4})-(\d{2})$/)
    const b = last.match(/^(\d{4})-(\d{2})$/)
    if (!a || !b) return ''
    const left = MONTH_SHORT[Number(a[2]) - 1]
    const right = MONTH_SHORT[Number(b[2]) - 1]
    if (a[1] === b[1]) return `${left} – ${right} ${b[1]}`
    return `${left} ${a[1]} – ${right} ${b[1]}`
}

export const PERIOD_PRESETS = [
    { id: 'view', label: 'This view' },
    { id: 'this_month', label: 'This month' },
    { id: 'last_month', label: 'Last month' },
    { id: 'last_30_days', label: 'Last 30 days' },
    { id: 'last_7_days', label: 'Last 7 days' },
    { id: 'this_quarter', label: 'This quarter' },
    { id: 'this_year', label: 'This year' },
    { id: 'last_year', label: 'Last year' },
]
