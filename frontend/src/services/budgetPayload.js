// Request body for POST/PATCH /budgets (BudgetStoreRequest validates per type).
export const budgetPayload = (form) => {
    const base = { title: form.title, amount: form.amount, tag_ids: form.tag_ids }

    if (form.type === 'goal') {
        return {
            ...base,
            type: 'goal',
            // Empty = today on create, keep the current start on update.
            start_date: form.start_date || null,
            target_date: form.target_date || null,
            start_amount: form.start_amount === '' || form.start_amount == null ? null : form.start_amount,
        }
    }

    return { ...base, type: 'limit', length: form.length, align_to_calendar: form.align_to_calendar }
}
