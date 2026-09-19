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

export const ready = () => {
    try {
        const webApp = window.Telegram?.WebApp

        if (!webApp) return

        webApp.ready?.()
        webApp.expand?.()
    } catch {
        // Normal browser — ignore
    }
}

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
