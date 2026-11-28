import { defineStore } from 'pinia'
import api from './api.js'
import { persistCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import { showModal } from './modal.js'
import toasts from './toasts.js'

const CACHE = 'tags'

export const useTagsStore = defineStore('tags', {
    state: () => ({
        tags: [],
        // What the tag form should show next: { tag, parentId } (tag = null creates a new one).
        tagForm: null,
        isLoading: false,
    }),

    getters: {
        // Archived tags (archived_at set) still label old transactions but are not offered for new ones.
        active: (state) => state.tags.filter((tag) => !tag.archived_at),
        archived: (state) => state.tags.filter((tag) => tag.archived_at).sort((a, b) => a.title.localeCompare(b.title)),
        list: (state) => () => state.buildTagsList(state.tags.filter((tag) => !tag.archived_at)),
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

        /** Opens the shared tag form: edit `tag`, or create a new one (optionally under `parentId`). */
        openForm(tag = null, parentId = null) {
            this.tagForm = { tag, parentId, at: Date.now() }
            showModal('tag_form_modal')
        },

        /** Re-reads the list for fresh transaction counts (cheap; the manager calls it when it opens). */
        async refreshCounts() {
            try {
                const { data } = await api.get('/tags')
                const counts = new Map(data.map((tag) => [tag.id, tag.transactions_count ?? 0]))

                this.tags = this.tags.map((tag) =>
                    counts.has(tag.id) ? { ...tag, transactions_count: counts.get(tag.id) } : tag,
                )
                this.persist()
            } catch {
                // Counts are decoration; keep the old ones.
            }
        },

        /** A starter set: one parent with its children, created in a row with a single toast. */
        async createSet(set) {
            this.isLoading = true

            try {
                const { data: parent } = await api.post('/tags', {
                    title: set.title,
                    emoji: set.emoji,
                    parent_id: null,
                    calc_balance: false,
                })
                this.tags.push({ ...parent, transactions_count: 0 })

                for (const child of set.children) {
                    const { data } = await api.post('/tags', {
                        title: child.title,
                        emoji: child.emoji,
                        parent_id: parent.id,
                        calc_balance: false,
                    })
                    this.tags.push({ ...data, transactions_count: 0 })
                }

                this.persist()
                toasts.success('Tags created!')

                return true
            } catch (error) {
                this.persist()
                toasts.error(apiErrorMessage(error, 'Could not create tags: '))

                return false
            } finally {
                this.isLoading = false
            }
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

                this.tags.push({ ...response.data, transactions_count: 0 })
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
                this.tags[index] = {
                    ...response.data,
                    transactions_count: response.data.transactions_count ?? this.tags[index].transactions_count,
                }
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

        async archive(id) {
            this.isLoading = id

            try {
                await api.delete('/tags/' + id)

                const toArchive = new Set([id])

                for (let added = true; added; ) {
                    added = false

                    this.tags.forEach((t) => {
                        if (toArchive.has(t.parent_id) && !toArchive.has(t.id)) {
                            toArchive.add(t.id)
                            added = true
                        }
                    })
                }

                const at = new Date().toISOString()
                this.tags = this.tags.map((t) => (toArchive.has(t.id) ? { ...t, archived_at: t.archived_at || at } : t))
                this.persist()

                toasts.success('Tag archived!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not archive tag: '))

                return false
            } finally {
                this.isLoading = false
            }
        },

        async restore(id) {
            this.isLoading = id

            try {
                const { data } = await api.post(`/tags/${id}/restore`)
                const index = this.tags.findIndex((t) => t.id === id)
                const fresh = data?.id ? data : { ...this.tags[index], archived_at: null }

                if (index === -1) this.tags.push(fresh)
                else this.tags[index] = { ...this.tags[index], ...fresh, archived_at: fresh.archived_at ?? null }

                this.persist()
                await patchTagCopies({ ...fresh, archived_at: fresh.archived_at ?? null })

                toasts.success('Tag restored!')

                return true
            } catch (error) {
                toasts.error(apiErrorMessage(error, 'Could not restore tag: '))

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
              archived_at: fresh.archived_at ?? null,
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
