const STASH_KEY = 'telegram_webapp_init_data'

export const isTelegramWebApp = () => {
    try {
        return Boolean(window.Telegram?.WebApp?.initData)
    } catch {
        return false
    }
}

export const getInitData = () => {
    try {
        return window.Telegram?.WebApp?.initData || ''
    } catch {
        return ''
    }
}

const webApp = () => (isTelegramWebApp() ? window.Telegram.WebApp : null)

/** Feature gate by Bot API version: BackButton/haptics 6.1, closing confirmation 6.2, vertical swipes 7.7. */
export const supports = (version) => {
    try {
        return Boolean(webApp()?.isVersionAtLeast?.(version))
    } catch {
        return false
    }
}

export const ready = () => {
    try {
        const app = window.Telegram?.WebApp

        if (!app) return

        app.ready?.()
        app.expand?.()

        if (!isTelegramWebApp()) return

        document.documentElement.classList.add('miniapp')

        // Otherwise a swipe down in a long list collapses the Mini App instead of scrolling.
        if (supports('7.7')) app.disableVerticalSwipes()
    } catch {
        // Normal browser — ignore
    }
}

const withHaptics = (fn) => {
    try {
        if (supports('6.1')) fn(webApp().HapticFeedback)
    } catch {
        // Unsupported device — haptics are best effort.
    }
}

/** Telegram haptic feedback; no-ops outside the Mini App. */
export const haptic = {
    success: () => withHaptics((h) => h.notificationOccurred('success')),
    warning: () => withHaptics((h) => h.notificationOccurred('warning')),
    error: () => withHaptics((h) => h.notificationOccurred('error')),
    tap: () => withHaptics((h) => h.impactOccurred('light')),
    select: () => withHaptics((h) => h.selectionChanged()),
}

/** The Telegram WebApp object inside the Mini App, null in a normal browser. */
export const miniApp = webApp

export const stashInitData = (initData) => {
    if (!initData) return

    try {
        sessionStorage.setItem(STASH_KEY, initData)
    } catch {
        // private mode / blocked storage
    }
}

export const peekStashedInitData = () => {
    try {
        return sessionStorage.getItem(STASH_KEY) || ''
    } catch {
        return ''
    }
}

export const takeStashedInitData = () => {
    const value = peekStashedInitData()

    try {
        sessionStorage.removeItem(STASH_KEY)
    } catch {
        // ignore
    }

    return value
}
