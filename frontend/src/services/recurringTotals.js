/** Convert a rule amount into a monthly equivalent (expenses stay negative until the caller takes abs). */
export const monthlyFactor = (frequency) => {
    switch (frequency) {
        case 'daily':
            return 365 / 12
        case 'weekly':
            return 52 / 12
        case 'monthly':
            return 1
        case 'yearly':
            return 1 / 12
        default:
            return 0
    }
}

export const periodFromFrequency = (frequency) => {
    if (frequency === 'monthly') return 'Monthly'
    if (frequency === 'yearly') return 'Yearly'
    return null
}

export const typeHasField = (type, key) =>
    (type?.fields ?? []).some((field) => field.key === key && !field.hidden)

export const applyScheduleData = (type, data, frequency, nextRunAt) => {
    const next = { ...(data ?? {}) }
    if (typeHasField(type, 'period')) {
        const auto = periodFromFrequency(frequency)
        if (auto) next.period = auto
    }
    if (typeHasField(type, 'next_charge') && nextRunAt) {
        next.next_charge = nextRunAt
    }
    return next
}

/** Auto Period / Next charge for the form: any type that has those field keys. */
export const scheduleAutoFields = (type, frequency, nextRunAt) => {
    const auto = {}
    if (typeHasField(type, 'period') && periodFromFrequency(frequency)) {
        auto.period = { hint: 'From frequency', display: periodFromFrequency(frequency) }
    }
    if (typeHasField(type, 'next_charge') && nextRunAt) {
        auto.next_charge = { hint: 'From the next charge date' }
    }
    return auto
}

export const serviceName = (rule) => {
    const service = String(rule?.data?.service ?? '').trim()
    const note = String(rule?.note ?? '').trim()
    return service || note || 'Untitled'
}

/**
 * Active expense recurring rules (any type, or none), normalized to a month.
 * daily × 365/12, weekly × 52/12, monthly × 1, yearly ÷ 12.
 * Bars are grouped by type; typeless rules form a "No type" group last.
 */
export const recurringSummary = (rules, types) => {
    const byId = new Map((types ?? []).map((type) => [Number(type.id), type]))
    const items = (rules ?? [])
        .filter((rule) => rule.active && Number(rule.amount) < 0)
        .map((rule) => ({
            id: rule.id,
            name: serviceName(rule),
            monthly: Math.abs(Number(rule.amount)) * monthlyFactor(rule.frequency),
            next_run_at: rule.next_run_at,
            typeId: rule.transaction_type_id == null ? null : Number(rule.transaction_type_id),
        }))
        .filter((item) => item.monthly > 0)

    if (!items.length) return null

    const monthly = items.reduce((sum, item) => sum + item.monthly, 0)
    const next = [...items].sort((a, b) => String(a.next_run_at).localeCompare(String(b.next_run_at)) || a.name.localeCompare(b.name))[0]

    const groupsMap = new Map()
    for (const item of items) {
        const key = item.typeId ?? 'none'
        if (!groupsMap.has(key)) {
            const type = item.typeId != null ? byId.get(item.typeId) : null
            groupsMap.set(key, {
                id: key,
                typeId: item.typeId,
                name: type?.name || 'No type',
                emoji: type?.emoji || '',
                color: type?.color || null,
                untyped: item.typeId == null,
                monthly: 0,
                items: [],
            })
        }
        const group = groupsMap.get(key)
        group.monthly += item.monthly
        group.items.push(item)
    }

    const groups = [...groupsMap.values()]
        .map((group) => {
            group.items.sort((a, b) => b.monthly - a.monthly || a.name.localeCompare(b.name))
            const names = group.items.map((item) => item.name)
            const shown = names.slice(0, 2)
            group.preview = shown.join(', ') + (names.length > 2 ? ` +${names.length - 2}` : '')
            group.count = group.items.length
            group.share = monthly > 0 ? (group.monthly / monthly) * 100 : 0
            return group
        })
        .sort((a, b) => {
            if (a.untyped !== b.untyped) return a.untyped ? 1 : -1
            return b.monthly - a.monthly || a.name.localeCompare(b.name)
        })

    return {
        monthly,
        yearly: monthly * 12,
        count: items.length,
        nextDate: next?.next_run_at ?? null,
        nextName: next?.name ?? null,
        groups,
    }
}
