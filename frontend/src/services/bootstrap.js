import api from './api.js'
import { useBudgetsStore } from './budgets.js'
import { currentUserId, noticeCachedData, persistCache, readCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import { useRecurringTransactionsStore } from './recurringTransactions.js'
import { useTagsStore } from './tags.js'
import toasts from './toasts.js'
import { useTransactionsStore } from './transactions.js'

/**
 * One round-trip for initial app data. Hydrates from local cache first,
 * then replaces with /bootstrap and refreshes per-resource snapshots.
 */
export const bootstrapStores = async () => {
    const userId = currentUserId()
    const tagsStore = useTagsStore()
    const transactionsStore = useTransactionsStore()
    const budgetsStore = useBudgetsStore()
    const recurringStore = useRecurringTransactionsStore()

    const cached = {
        tags: readCache(userId, 'tags'),
        transactions: readCache(userId, 'transactions'),
        budgets: readCache(userId, 'budgets'),
        recurring: readCache(userId, 'recurring'),
    }

    if (cached.tags) tagsStore.tags = cached.tags
    if (cached.transactions) transactionsStore.transactions = cached.transactions
    if (cached.budgets) budgetsStore.budgets = cached.budgets
    if (cached.recurring) recurringStore.rules = cached.recurring

    const hadCache = Object.values(cached).some((value) => value != null)

    tagsStore.isLoading = true
    transactionsStore.isLoading = true
    budgetsStore.isLoading = true
    recurringStore.isLoading = true

    try {
        const { data } = await api.get('/bootstrap', { timeout: 60000 })

        tagsStore.tags = data.tags ?? []
        transactionsStore.transactions = data.transactions ?? []
        budgetsStore.budgets = data.budgets ?? []
        recurringStore.rules = data.recurring_transactions ?? []

        persistCache('tags', tagsStore.tags)
        persistCache('transactions', transactionsStore.transactions)
        persistCache('budgets', budgetsStore.budgets)
        persistCache('recurring', recurringStore.rules)
    } catch (error) {
        if (hadCache) noticeCachedData()
        else toasts.error(apiErrorMessage(error, 'Failed to load data: '))
    } finally {
        tagsStore.isLoading = false
        transactionsStore.isLoading = false
        budgetsStore.isLoading = false
        recurringStore.isLoading = false
    }
}
