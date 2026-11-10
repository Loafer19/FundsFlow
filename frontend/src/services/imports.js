import api from './api.js'

const long = { timeout: 120000 }

export const fetchImports = () =>
    api.get('/imports', { timeout: 20000 }).then((res) => res.data?.data ?? res.data ?? [])

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

// Matches DocumentFile::EXTENSIONS / ImportController file validation.
export const IMPORT_FILE_ACCEPT = '.csv,.tsv,.txt,.xlsx,.ods,.ofx,.qfx,.qif,.json,.html,.htm,.pdf,.docx'

export const IMPORT_FORMATS = [
    { label: 'CSV, TSV or text', extensions: '.csv, .tsv, .txt' },
    { label: 'Excel', extensions: '.xlsx' },
    { label: 'OpenDocument', extensions: '.ods' },
    { label: 'PDF', extensions: '.pdf' },
    { label: 'Word', extensions: '.docx' },
    { label: 'OFX / Quicken', extensions: '.ofx, .qfx' },
    { label: 'QIF', extensions: '.qif' },
    { label: 'JSON', extensions: '.json' },
    { label: 'HTML statement', extensions: '.html, .htm' },
]

export const goToImport = (id = '') => {
    const path = id ? `/import/${id}` : '/import'
    window.history.pushState({}, '', path)
    window.dispatchEvent(new PopStateEvent('popstate'))
}

export const openImportPreview = (id) => goToImport(id)

// Shared start used by ImportView (the original web entry) and the List-tab Import button.
export const startWebImport = async (file) => {
    const created = await uploadImport(file)
    goToImport(created.id)
    return created
}
