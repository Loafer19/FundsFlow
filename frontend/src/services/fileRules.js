// Accepted file formats and limits, one place for the whole web app. The defaults mirror the
// backend (TransactionAttachmentRules, DocumentFile); /bootstrap sends the live values as
// `file_rules` and applyFileRules() updates these exports (ES module live bindings).

// Transaction attachments (HEIC/HEIF are converted to JPEG on the server).
export let ATTACHMENT_MIMES = new Set([
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/heic',
    'image/heif',
    'application/pdf',
])
export let ATTACHMENT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf']
export let ATTACHMENT_ACCEPT = [...ATTACHMENT_MIMES, ...ATTACHMENT_EXTENSIONS.map((e) => `.${e}`)].join(',')
export let ATTACHMENT_LABEL = 'JPEG, PNG, WebP, HEIC and PDF'
export let ATTACHMENT_MAX_BYTES = 8 * 1024 * 1024
export let ATTACHMENT_MAX_FILES = 5

// Receipt photos for the assistant.
export let RECEIPT_MAX_IMAGES = 5

// Documents and statements (import page and assistant).
export let DOCUMENT_EXTENSIONS = [
    'pdf',
    'docx',
    'csv',
    'tsv',
    'txt',
    'xlsx',
    'ods',
    'json',
    'ofx',
    'qfx',
    'qif',
    'html',
    'htm',
]
export let DOCUMENT_ACCEPT_EXTENSIONS = DOCUMENT_EXTENSIONS.map((e) => `.${e}`).join(',')
const DOCUMENT_MIMES =
    'application/pdf,text/csv,application/json,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.oasis.opendocument.spreadsheet'
export let DOCUMENT_ACCEPT = `${DOCUMENT_ACCEPT_EXTENSIONS},${DOCUMENT_MIMES}`
export let MAX_PDF_BYTES = 8 * 1024 * 1024
export let MAX_FILE_BYTES = 5 * 1024 * 1024
export let MAX_DOCUMENTS = 5
export let MAX_DOCUMENTS_TOTAL_BYTES = 20 * 1024 * 1024

const num = (value, fallback) => (Number.isFinite(Number(value)) && Number(value) > 0 ? Number(value) : fallback)
const list = (value, fallback) => (Array.isArray(value) && value.length ? value.map(String) : fallback)

export const applyFileRules = (rules) => {
    if (!rules || typeof rules !== 'object') return
    const a = rules.attachments ?? {}
    ATTACHMENT_MIMES = new Set(list(a.mimes, [...ATTACHMENT_MIMES]))
    ATTACHMENT_EXTENSIONS = list(a.extensions, ATTACHMENT_EXTENSIONS)
    ATTACHMENT_ACCEPT = typeof a.accept === 'string' && a.accept ? a.accept : ATTACHMENT_ACCEPT
    ATTACHMENT_LABEL = typeof a.label === 'string' && a.label ? a.label : ATTACHMENT_LABEL
    ATTACHMENT_MAX_BYTES = num(a.max_bytes, ATTACHMENT_MAX_BYTES)
    ATTACHMENT_MAX_FILES = num(a.max_files, ATTACHMENT_MAX_FILES)
    RECEIPT_MAX_IMAGES = num(rules.receipt_images?.max_files, RECEIPT_MAX_IMAGES)
    const d = rules.documents ?? {}
    DOCUMENT_EXTENSIONS = list(d.extensions, DOCUMENT_EXTENSIONS)
    DOCUMENT_ACCEPT_EXTENSIONS = DOCUMENT_EXTENSIONS.map((e) => `.${e}`).join(',')
    DOCUMENT_ACCEPT = `${DOCUMENT_ACCEPT_EXTENSIONS},${DOCUMENT_MIMES}`
    MAX_PDF_BYTES = num(d.max_pdf_bytes, MAX_PDF_BYTES)
    MAX_FILE_BYTES = num(d.max_file_bytes, MAX_FILE_BYTES)
    MAX_DOCUMENTS = num(d.max_files, MAX_DOCUMENTS)
    MAX_DOCUMENTS_TOTAL_BYTES = num(d.max_total_bytes, MAX_DOCUMENTS_TOTAL_BYTES)
}

/** File extension (lowercase, no dot) of a name. */
export const extensionOf = (name) =>
    String(name ?? '')
        .match(/\.([^.]+)$/)?.[1]
        ?.toLowerCase() ?? ''

/** Attachable by type, or by extension when the browser reports no type (HEIC on some systems). */
export const isAttachable = (file) =>
    ATTACHMENT_MIMES.has(file?.type) || (!file?.type && ATTACHMENT_EXTENSIONS.includes(extensionOf(file?.name)))
