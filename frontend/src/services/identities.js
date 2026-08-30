import api from './api.js'

export const getTelegramLinkCode = () => api.post('/identities/telegram/link-code')

export const openTelegramLinkBot = async () => {
    const response = await getTelegramLinkCode()
    const bot = import.meta.env.VITE_TELEGRAM_BOT_USERNAME

    window.open(`https://t.me/${bot}?start=${response.data.code}`, '_blank')
}
