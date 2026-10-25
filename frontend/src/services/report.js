import api from './api.js'
import { apiErrorMessage } from './formatters.js'
import settings from './settings.js'

// Report PDFs are rendered by the backend (Gotenberg opens this SPA in print mode, see PrintReport.vue),
// so Settings → Report and the assistant report card get the same document.
const REPORT_TIMEOUT_MS = 180000

/**
 * @param {object} request from, to, period?, period_type?, sections?, tag_ids?, untagged?
 * @param {'download'|'telegram'} delivery
 * @param {string} endpoint '/report' (Settings) or '/agent/report' (assistant card)
 * @returns {Promise<Blob|string>} the PDF for download, or the server message for telegram
 */
export const requestReport = async (request, delivery = 'download', endpoint = '/report') => {
    const body = { theme: settings.theme, ...request, delivery }

    if (delivery === 'telegram') {
        const { data } = await api.post(endpoint, body, { timeout: REPORT_TIMEOUT_MS })
        return data?.message || 'Report sent to Telegram.'
    }

    const { data } = await api.post(endpoint, body, { timeout: REPORT_TIMEOUT_MS, responseType: 'blob' })
    return data instanceof Blob ? data : new Blob([data], { type: 'application/pdf' })
}

/** Error text for a failed report request (blob responses carry their JSON error as a Blob). */
export const reportErrorMessage = async (error, prefix = 'Failed to build the report: ') => {
    const body = error?.response?.data
    if (body instanceof Blob) {
        try {
            const parsed = JSON.parse(await body.text())
            if (parsed?.message) return `${prefix}${parsed.message}`
        } catch {
            // keep the generic message
        }
    }
    return apiErrorMessage(error, prefix)
}

export const saveBlob = (blob, filename) => {
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(url), 10000)
}

const settle = (target) =>
    new Promise((resolve) => {
        target.addEventListener('load', resolve, { once: true })
        target.addEventListener('error', resolve, { once: true })
    })

// The print page is ready once it has painted: two frames for Vue/Chart.js layout,
// fonts and images (logo) loaded, then a short settle for late canvas draws.
export const waitForCharts = async () => {
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))
    await document.fonts?.ready
    await Promise.all([...document.images].filter((img) => !img.complete).map(settle))
    await new Promise((resolve) => setTimeout(resolve, 300))
}
