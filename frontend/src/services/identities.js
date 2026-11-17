import api from './api.js'

export const getTelegramLinkCode = () => api.post('/identities/telegram/link-code')

export const openTelegramLinkBot = async () => {
    const telegram = window.Telegram?.WebApp
    const inMiniApp = Boolean(telegram?.initData) && typeof telegram.openTelegramLink === 'function'
    // Open before the await, or Safari drops the popup as not a user gesture.
    const pending = inMiniApp ? null : window.open('', '_blank')

    try {
        const response = await getTelegramLinkCode()
        const bot = import.meta.env.VITE_TELEGRAM_BOT_USERNAME
        const url = `https://t.me/${bot}?start=${response.data.code}`

        if (inMiniApp) {
            telegram.openTelegramLink(url)
            return
        }

        if (pending) pending.location.href = url
    } catch (error) {
        pending?.close()
        throw error
    }
}
