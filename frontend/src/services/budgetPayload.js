// Request body for POST/PATCH /budgets (BudgetStoreRequest validates per type).
export const budgetPayload = (form) => {
    const base = { title: form.title, amount: form.amount }

    if (form.type === 'goal') {
        return {
            ...base,
            tag_ids: form.tag_ids,
            type: 'goal',
            // Empty = today on create, keep the current start on update.
            start_date: form.start_date || null,
            target_date: form.target_date || null,
            start_amount: form.start_amount === '' || form.start_amount == null ? null : form.start_amount,
        }
    }

    // Buckets repeat from start_date (empty = today on create). On edit an untouched start is
    // omitted so the API keeps the current schedule.
    const start = form.start_date && form.start_date !== form.start_original ? form.start_date : null

    if (form.limit_by === 'type') {
        return {
            ...base,
            type: 'limit',
            length: form.length,
            start_date: start,
            tag_ids: [],
            scope: { kind: 'type', type_id: Number(form.scope_type_id) },
        }
    }

    if (form.limit_by === 'field_sum') {
        return {
            ...base,
            type: 'limit',
            length: form.length,
            start_date: start,
            tag_ids: [],
            scope: { kind: 'field_sum', type_id: Number(form.scope_type_id), field: form.scope_field },
        }
    }

    return {
        ...base,
        type: 'limit',
        length: form.length,
        start_date: start,
        tag_ids: form.tag_ids,
        scope: null,
    }
}

export const isBudgetFormValid = (form) => {
    if (form.monobank_jar) return true

    if (form.type !== 'goal' && form.limit_by === 'type') {
        return Boolean(form.scope_type_id)
    }

    if (form.type !== 'goal' && form.limit_by === 'field_sum') {
        return Boolean(form.scope_type_id && form.scope_field)
    }

    return Boolean(form.tag_ids?.length)
}
