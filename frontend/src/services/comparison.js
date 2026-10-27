// Current vs previous period comparison shared by the summary cards (Flow, Trend, Analytics).
// By default higher is treated as better: income/net up is good, and expenses / lows are negative,
// so a higher (less negative) value is good too. Pass `lowerIsBetter` when the value is a positive
// cost (Analytics shows expenses as positive numbers) and `neutral` when neither direction is good.
// `relative: false` skips the % change (e.g. a savings rate already in %, compared in points).
export const compareValues = (current, previous, { lowerIsBetter = false, neutral = false, relative = true } = {}) => {
    if (current == null || previous == null || !Number.isFinite(current) || !Number.isFinite(previous)) {
        return { delta: null, pct: null, badge: 'badge-ghost' }
    }

    const delta = current - previous
    const pct = !relative || previous === 0 ? null : (delta / Math.abs(previous)) * 100
    let badge = 'badge-ghost'
    if (!neutral && Math.abs(delta) > 0.004) {
        const better = lowerIsBetter ? delta < 0 : delta > 0
        badge = better ? 'badge-success' : 'badge-error'
    }

    return { delta, pct, badge }
}
