import { getActivePinia } from 'pinia'
import { apiErrorMessage } from './formatters.js'
import toasts from './toasts.js'

const PREFIX = 'ff:cache:v1:'

let cachedNoticeShown = false

export const resetCachedNotice = () => {
    cachedNoticeShown = false
}

export const noticeCachedData = () => {
    if (cachedNoticeShown) return

    cachedNoticeShown = true
    toasts.info('Showing cached data')
}

// Avoid importing auth.js here (auth clears cache on logout → circular).
export const currentUserId = () => getActivePinia()?._s.get('auth')?.user?.id

export const readCache = (userId, name) => {
    if (userId == null) return null

    try {
        const raw = localStorage.getItem(`${PREFIX}${userId}:${name}`)
        if (!raw) return null

        const parsed = JSON.parse(raw)

        return Array.isArray(parsed?.data) ? parsed.data : null
    } catch {
        return null
    }
}

export const writeCache = (userId, name, data) => {
    if (userId == null) return

    try {
        localStorage.setItem(`${PREFIX}${userId}:${name}`, JSON.stringify({ savedAt: Date.now(), data }))
    } catch {
        // QuotaExceeded or private mode — ignore; in-memory state still works.
    }
}

/** Persist a store list field for the current user. */
export const persistCache = (name, data) => writeCache(currentUserId(), name, data)

/**
 * Stale-while-revalidate load for Pinia list stores.
 * @param {object} store pinia store (`this` in an action)
 * @param {{ name: string, key: string, fetch: () => Promise<any[]>, errorPrefix: string }} options
 */
export const loadWithCache = async (store, { name, key, fetch, errorPrefix }) => {
    const userId = currentUserId()
    const cached = readCache(userId, name)

    if (cached) store[key] = cached

    store.isLoading = true

    try {
        store[key] = await fetch()
        writeCache(userId, name, store[key])
    } catch (error) {
        if (cached) noticeCachedData()
        else toasts.error(apiErrorMessage(error, errorPrefix))
    } finally {
        store.isLoading = false
    }
}

export const clearUserCache = (userId) => {
    const keys = []

    for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i)
        if (!key?.startsWith(PREFIX)) continue
        if (userId == null || key.startsWith(`${PREFIX}${userId}:`)) keys.push(key)
    }

    for (const key of keys) localStorage.removeItem(key)

    resetCachedNotice()
}
