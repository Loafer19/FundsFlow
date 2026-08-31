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
