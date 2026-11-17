import { defineStore } from 'pinia'
import api from './api.js'
import { persistCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

const CACHE = 'tags'

export const useTagsStore = defineStore('tags', {
    state: () => ({
        tags: [],
        tagForEdit: null,
        isLoading: false,
    }),

    getters: {
        list: (state) => () => state.buildTagsList(state.tags),
        forBalances: (state) => () => state.list().filter((tag) => tag.calc_balance),
        descendantIds: (state) => (rootId) => {
            const ids = new Set([rootId])
            let grew = true

            while (grew) {
                grew = false

                for (const tag of state.tags) {
                    if (tag.parent_id != null && ids.has(tag.parent_id) && !ids.has(tag.id)) {
                        ids.add(tag.id)
                        grew = true
                    }
                }
            }

            return ids
        },
        // A tag includes all of its descendants (limits, goals, the tag filter): the given ids plus their child tags.
        expandIds() {
            return (ids) => {
                const out = new Set()
                for (const id of ids ?? []) for (const x of this.descendantIds(id)) out.add(x)
                return out
            }
        },
    },

    actions: {
        persist() {
            persistCache(CACHE, this.tags)
        },

        normalizePayload(raw) {
            return {
                ...raw,
                parent_id: raw.parent_id === '' || raw.parent_id == null ? null : raw.parent_id,
            }
        },

        async create(raw) {
            this.isLoading = true

            try {
                const response = await api.post('/tags', this.normalizePayload(raw))

                this.tags.push(response.data)
                this.persist()

                toasts.success('Tag created!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not create tag: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async update(raw) {
            this.isLoading = true

            try {
                const response = await api.patch('/tags/' + raw.id, this.normalizePayload(raw))

                const index = this.tags.findIndex((t) => t.id === raw.id)
                this.tags[index] = response.data
                this.persist()
                await patchTagCopies(response.data)

                toasts.success('Tag updated!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not update tag: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async delete(id) {
            this.isLoading = id

            try {
                await api.delete('/tags/' + id)

                const toRemove = new Set([id])

                for (let added = true; added; ) {
                    added = false

                    this.tags.forEach((t) => {
                        if (toRemove.has(t.parent_id) && !toRemove.has(t.id)) {
                            toRemove.add(t.id)
                            added = true
                        }
                    })
                }

                this.tags = this.tags.filter((t) => !toRemove.has(t.id))
                this.persist()

                toasts.info('Tag deleted!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not delete tag: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        buildTagsList(tags, parent_id = null, depth = 0) {
            const children = tags
                .filter((tag) => tag.parent_id === parent_id)
                .sort((a, b) => a.title.localeCompare(b.title))

            const result = []

            children.forEach((child) => {
                result.push({ ...child, depth })
                result.push(...this.buildTagsList(tags, child.id, depth + 1))
            })

            return result
        },
    },
})

const copiedTag = (tag, fresh) =>
    tag?.id === fresh.id
        ? {
              ...tag,
              title: fresh.title,
              emoji: fresh.emoji,
              parent_id: fresh.parent_id,
              calc_balance: fresh.calc_balance,
          }
        : tag

const copiedTagList = (tags, fresh) => {
    if (!Array.isArray(tags) || !tags.some((tag) => tag?.id === fresh.id)) return tags
    return tags.map((tag) => copiedTag(tag, fresh))
}

// List rows, budgets and recurring keep their own tag objects. A rename has to
// reach those copies or the old title stays on screen until the next full load.
const patchTagCopies = async (fresh) => {
    const [{ useTransactionsStore }, { useBudgetsStore }, { useRecurringTransactionsStore }] = await Promise.all([
        import('./transactions.js'),
        import('./budgets.js'),
        import('./recurringTransactions.js'),
    ])

    const transactions = useTransactionsStore()
    let transactionsChanged = false
    transactions.transactions = transactions.transactions.map((item) => {
        const tags = copiedTagList(item.tags, fresh)
        if (tags === item.tags) return item
        transactionsChanged = true
        return { ...item, tags }
    })
    if (transactionsChanged) transactions.persist()

    const budgets = useBudgetsStore()
    let budgetsChanged = false
    budgets.budgets = budgets.budgets.map((budget) => {
        if (!Array.isArray(budget.periods)) return budget
        let changed = false
        const periods = budget.periods.map((period) => {
            const tags = copiedTagList(period.tags, fresh)
            if (tags === period.tags) return period
            changed = true
            budgetsChanged = true
            return { ...period, tags }
        })
        return changed ? { ...budget, periods } : budget
    })
    if (budgetsChanged) budgets.persist()

    const recurring = useRecurringTransactionsStore()
    let rulesChanged = false
    recurring.rules = recurring.rules.map((rule) => {
        const tags = copiedTagList(rule.tags, fresh)
        if (tags === rule.tags) return rule
        rulesChanged = true
        return { ...rule, tags }
    })
    if (rulesChanged) recurring.persist()
}
