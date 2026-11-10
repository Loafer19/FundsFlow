import api from './api.js'

const long = { timeout: 120000 }

export const fetchImport = (id, params = {}) =>
    api.get(`/imports/${id}`, { params, timeout: 20000 }).then((res) => res.data)

export const uploadImport = (file, caption = '') => {
    const body = new FormData()
    body.append('file', file)
    if (caption) body.append('caption', caption)
    return api.post('/imports', body, long).then((res) => res.data)
}

export const applyImportRules = (id, rules) =>
    api.patch(`/imports/${id}`, rules, long).then((res) => res.data)

export const updateImportRow = (id, rowId, data) =>
    api.patch(`/imports/${id}/rows/${rowId}`, data, { timeout: 20000 }).then((res) => res.data)

export const commitImport = (id) =>
    api.post(`/imports/${id}/commit`, {}, long).then((res) => res.data)

export const undoImport = (id) =>
    api.post(`/imports/${id}/undo`, {}, long).then((res) => res.data)
