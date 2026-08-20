import api from './api.js'

export const linkTelegram = (code) => api.post('/identities/telegram/link', { code })
