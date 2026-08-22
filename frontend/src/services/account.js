import api from './api.js'

export const updateCredentials = (data) => api.put('/account/credentials', data)
