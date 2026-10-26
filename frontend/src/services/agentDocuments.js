// Documents for the assistant: PDF, Word, CSV, Excel / OpenDocument, JSON, OFX / QFX, QIF, HTML.
// Sent as-is (up to MAX_DOCUMENTS per message, one import); the server checks the real type and
// reads it (see backend DocumentFile / DocumentImport).

export const MAX_PDF_BYTES = 8 * 1024 * 1024
export const MAX_FILE_BYTES = 5 * 1024 * 1024
export const MAX_DOCUMENTS = 5
export const MAX_DOCUMENTS_TOTAL_BYTES = 20 * 1024 * 1024

const EXTENSIONS = /\.(pdf|docx|csv|tsv|txt|xlsx|ods|json|ofx|qfx|qif|html?)$/i
// Known formats the server refuses with a "save as" hint; caught here to save the upload.
const LEGACY = {
    xls: 'Old Excel (.xls) files are not supported yet. Save it as .xlsx or .csv.',
    xlsm: 'Macro-enabled Excel files are not accepted. Save it as .xlsx or .csv.',
    xlsb: 'Binary Excel files are not supported. Save it as .xlsx or .csv.',
    doc: 'Old Word (.doc) files are not supported. Save it as .docx or PDF.',
    docm: 'Macro-enabled Word files are not accepted. Save it as .docx or PDF.',
    rtf: 'RTF files are not supported. Save it as .docx or PDF.',
    numbers: 'Numbers files are not supported. Export it as .csv or .xlsx.',
}

export const DOCUMENT_ACCEPT =
    '.pdf,.docx,.csv,.tsv,.txt,.xlsx,.ods,.json,.ofx,.qfx,.qif,.html,.htm,application/pdf,text/csv,application/json,' +
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document,' +
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,' +
    'application/vnd.oasis.opendocument.spreadsheet'

const extension = (name) =>
    String(name ?? '')
        .match(/\.([^.]+)$/)?.[1]
        ?.toLowerCase() ?? ''

export const isPdf = (file) => file?.type === 'application/pdf' || extension(file?.name) === 'pdf'

export const isSupportedDocument = (file) => file instanceof Blob && (isPdf(file) || EXTENSIONS.test(file.name ?? ''))

// A "save it as…" hint for a known unsupported format, else null.
export const legacyDocumentHint = (file) => LEGACY[extension(file?.name)] ?? null

export const documentLimit = (file) => (isPdf(file) ? MAX_PDF_BYTES : MAX_FILE_BYTES)

export const formatSize = (bytes) => {
    const size = Number(bytes) || 0
    if (size >= 1024 * 1024) return `${(size / (1024 * 1024)).toFixed(1)} MB`
    return `${Math.max(1, Math.round(size / 1024))} KB`
}
