import api from './api.js'

export const getTelegramLinkCode = () => api.post('/identities/telegram/link-code')
