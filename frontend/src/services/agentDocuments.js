// Documents for the assistant: PDF, Word, CSV, Excel / OpenDocument, JSON, OFX / QFX, QIF, HTML.
// Sent as-is (up to MAX_DOCUMENTS per message, one import); the server checks the real type and
// reads it (see backend DocumentFile / DocumentImport).

import { DOCUMENT_EXTENSIONS, MAX_FILE_BYTES, MAX_PDF_BYTES } from './fileRules.js'

export {
    DOCUMENT_ACCEPT,
    MAX_DOCUMENTS,
    MAX_DOCUMENTS_TOTAL_BYTES,
    MAX_FILE_BYTES,
    MAX_PDF_BYTES,
} from './fileRules.js'

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

const extension = (name) =>
    String(name ?? '')
        .match(/\.([^.]+)$/)?.[1]
        ?.toLowerCase() ?? ''

export const isPdf = (file) => file?.type === 'application/pdf' || extension(file?.name) === 'pdf'

export const isSupportedDocument = (file) =>
    file instanceof Blob && (isPdf(file) || DOCUMENT_EXTENSIONS.includes(extension(file.name)))

// A "save it as…" hint for a known unsupported format, else null.
export const legacyDocumentHint = (file) => LEGACY[extension(file?.name)] ?? null

export const documentLimit = (file) => (isPdf(file) ? MAX_PDF_BYTES : MAX_FILE_BYTES)

export const formatSize = (bytes) => {
    const size = Number(bytes) || 0
    if (size >= 1024 * 1024) return `${(size / (1024 * 1024)).toFixed(1)} MB`
    return `${Math.max(1, Math.round(size / 1024))} KB`
}
