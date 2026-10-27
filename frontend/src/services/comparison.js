// Current vs previous period comparison shared by the summary cards (Flow, Trend).
// Higher is always treated as better: income/net up is good, and expenses / lows are negative,
// so a higher (less negative) value is good too.
export const compareValues = (current, previous) => {
    const delta = current - previous
    const pct = previous === 0 ? null : (delta / Math.abs(previous)) * 100
    let badge = 'badge-ghost'
    if (Math.abs(delta) > 0.004) badge = delta > 0 ? 'badge-success' : 'badge-error'

    return { delta, pct, badge }
}
