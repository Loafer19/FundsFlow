import api from './api.js'

export const getIdentities = () => api.get('/identities')
export const linkTelegram = (code) => api.post('/identities/telegram/link', { code })
