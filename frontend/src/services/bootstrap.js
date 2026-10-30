import { ref } from 'vue'
import api from './api.js'
import { useBudgetsStore } from './budgets.js'
import { currentUserId, noticeCachedData, persistCache, readCache } from './cache.js'
import { apiErrorMessage } from './formatters.js'
import { useRecurringTransactionsStore } from './recurringTransactions.js'
import { useTagsStore } from './tags.js'
import toasts from './toasts.js'
import { useTransactionsStore } from './transactions.js'

// True from page load with a stored session (or a login) until the first data load ends, unless cached
// data could be shown meanwhile. Tabs are swapped for a loader then, so their empty states don't flash.
export const isBootstrapping = ref(Boolean(localStorage.getItem('token')))

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

    isBootstrapping.value = !hadCache

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
        isBootstrapping.value = false
    }
}

// Background refresh after a change made outside the stores (assistant Confirm): no cache read-back
// and no isLoading flag, so the open tab and any open modal keep working while it loads. Calls within
// REFRESH_DEBOUNCE_MS share one fetch; a call while a fetch is running queues exactly one more.
const REFRESH_DEBOUNCE_MS = 300
let refreshTimer = null
let refreshRunning = null
let refreshQueued = false
let refreshWaiters = []

const runRefresh = async () => {
    const waiters = refreshWaiters
    refreshWaiters = []

    try {
        const { data } = await api.get('/bootstrap', { timeout: 60000 })
        const tagsStore = useTagsStore()
        const transactionsStore = useTransactionsStore()
        const budgetsStore = useBudgetsStore()
        const recurringStore = useRecurringTransactionsStore()

        tagsStore.tags = data.tags ?? []
        transactionsStore.transactions = data.transactions ?? []
        budgetsStore.budgets = data.budgets ?? []
        recurringStore.rules = data.recurring_transactions ?? []

        persistCache('tags', tagsStore.tags)
        persistCache('transactions', transactionsStore.transactions)
        persistCache('budgets', budgetsStore.budgets)
        persistCache('recurring', recurringStore.rules)
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Saved, but failed to refresh the data: '))
    } finally {
        for (const resolve of waiters) resolve()
    }
}

export const refreshStores = () =>
    new Promise((resolve) => {
        refreshWaiters.push(resolve)
        clearTimeout(refreshTimer)
        refreshTimer = setTimeout(async () => {
            refreshTimer = null
            if (refreshRunning) {
                refreshQueued = true
                return
            }
            do {
                refreshQueued = false
                refreshRunning = runRefresh()
                await refreshRunning
            } while (refreshQueued)
            refreshRunning = null
        }, REFRESH_DEBOUNCE_MS)
    })
