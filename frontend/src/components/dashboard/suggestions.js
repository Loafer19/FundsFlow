// Starter ideas for Analytics → My cards, built from the user's own types (no preset dashboards).
// Each one only prefills the editor; nothing is saved until the user presses Add card.
const isNumeric = (field) => ['number', 'rating', 'duration', 'computed'].includes(field.type)
const isSplittable = (field) => ['select', 'text', 'boolean'].includes(field.type)

export const cardSuggestions = (types) => {
    const out = []
    const active = types.filter((type) => !type.archived_at)
    const withPrice = active.find((type) => (type.fields ?? []).some((f) => isNumeric(f) && /price|rate/i.test(f.key)))
    const typed = withPrice ?? active.find((type) => (type.fields ?? []).some(isSplittable))

    if (typed) {
        const split = (typed.fields ?? []).find((f) => f.type === 'text') ?? (typed.fields ?? []).find(isSplittable)
        out.push({
            key: 'type-spend',
            label: `${typed.emoji ?? ''} ${typed.name} spending`.trim(),
            hint: split ? `Add a ${split.label} filter in the editor` : 'Compared with the previous period',
            kind: 'stat',
            config: { type_id: typed.id },
        })
    }

    if (withPrice) {
        const price = withPrice.fields.find((f) => isNumeric(f) && /price|rate/i.test(f.key))
        const split = withPrice.fields.find((f) => f.type === 'text') ?? withPrice.fields.find(isSplittable)
        out.push({
            key: 'price-split',
            label: `${price.label}${split ? ` by ${split.label}` : ''}`,
            hint: `${withPrice.emoji ?? ''} ${withPrice.name} · average over time`.trim(),
            kind: 'chart',
            config: { type_id: withPrice.id, metric: price.key, agg: 'avg', split: split?.key ?? null, chart: 'line' },
        })
    }

    out.push({
        key: 'tag-pie',
        label: 'Spending by tag',
        hint: 'Share of each top-level tag',
        kind: 'pie',
        config: { split: 'tag' },
    })

    return out
}
