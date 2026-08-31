import api from './api.js'

export const updateCredentials = (data) => api.put('/account/credentials', data)

export const updatePreferences = (data) => api.patch('/account/preferences', data)

export const downloadAccountExport = async () => {
    // blob: save server bytes as-is — no JSON.parse / stringify on the client
    const response = await api.get('/account/export', {
        responseType: 'blob',
        timeout: 60000,
    })
    const filename = `fundsflow-export-${new Date().toISOString().slice(0, 10)}.json`
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')

    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
}
