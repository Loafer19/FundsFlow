import api from './api.js'

export const getMonobank = () => api.get('/monobank')

export const connectMonobank = (token) => api.post('/monobank/connect', { token }, { timeout: 30000 })

export const updateMonobank = (data) => api.patch('/monobank', data)

export const syncMonobank = () => api.post('/monobank/sync', {}, { timeout: 15000 })

export const disconnectMonobank = () => api.delete('/monobank', { timeout: 20000 })
