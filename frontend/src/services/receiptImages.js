// Receipt photos for the assistant: pick/drop/paste -> validate -> downscale to JPEG before upload.

export const MAX_IMAGES = 5
// Original file size we accept from the picker (the upload itself is the downscaled JPEG, far below the 8 MB server cap).
export const MAX_INPUT_BYTES = 10 * 1024 * 1024
export const MAX_SIDE = 1600
const JPEG_QUALITY = 0.85

const TYPES = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])
const EXTENSIONS = /\.(jpe?g|png|webp|heic|heif)$/i

// File input `accept` (extensions too: some pickers report HEIC with an empty type).
export const ACCEPT = 'image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif'

export const isSupportedImage = (file) =>
    file instanceof Blob && (TYPES.has(file.type) || (!file.type && EXTENSIONS.test(file.name ?? '')))

const loadWithImg = (file) =>
    new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file)
        const img = new Image()
        img.onload = () => resolve({ source: img, width: img.naturalWidth, height: img.naturalHeight, url })
        img.onerror = () => {
            URL.revokeObjectURL(url)
            reject(new Error('decode'))
        }
        img.src = url
    })

const decode = async (file) => {
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
        return { source: bitmap, width: bitmap.width, height: bitmap.height, url: null }
    } catch {
        // Safari decodes HEIC only through <img>; other browsers simply cannot read HEIC.
        return loadWithImg(file)
    }
}

/**
 * Downscale to at most MAX_SIDE px on the long side and re-encode as JPEG (white behind transparency).
 * Resolves to { file: File (image/jpeg), name, url (object URL for the preview) }.
 */
export const prepareReceiptImage = async (file) => {
    const { source, width, height, url } = await decode(file)

    try {
        const scale = Math.min(1, MAX_SIDE / Math.max(width, height))
        const w = Math.max(1, Math.round(width * scale))
        const h = Math.max(1, Math.round(height * scale))
        const canvas = document.createElement('canvas')
        canvas.width = w
        canvas.height = h
        const ctx = canvas.getContext('2d')
        ctx.fillStyle = '#fff'
        ctx.fillRect(0, 0, w, h)
        ctx.drawImage(source, 0, 0, w, h)

        const blob = await new Promise((resolve, reject) =>
            canvas.toBlob((out) => (out ? resolve(out) : reject(new Error('encode'))), 'image/jpeg', JPEG_QUALITY),
        )
        const name = `${String(file.name || 'receipt').replace(/\.[^.]+$/, '') || 'receipt'}.jpg`

        return { file: new File([blob], name, { type: 'image/jpeg' }), name, url: URL.createObjectURL(blob) }
    } finally {
        source.close?.()
        if (url) URL.revokeObjectURL(url)
    }
}
