import api from './api.js'
import { apiErrorMessage } from './formatters.js'
import { requireTelegramLinked } from './telegramSend.js'
import toasts from './toasts.js'

const waitForCharts = async () => {
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))
    await new Promise((resolve) => setTimeout(resolve, 300))
}

/**
 * Collect CSS text from same-origin stylesheets (cross-origin sheets are skipped).
 * @returns {string}
 */
const collectDocumentCss = () => {
    let css = ''
    for (const sheet of document.styleSheets) {
        try {
            const rules = sheet.cssRules
            if (!rules) continue
            for (const rule of rules) {
                css += `${rule.cssText}\n`
            }
        } catch {
            // Ignore cross-origin / unreadable sheets
        }
    }
    return css
}

/**
 * Clone #fundsflow-report for Gotenberg: Chart.js canvases become PNG images so Chromium
 * does not need to re-run Vue/Chart.js.
 * @returns {Promise<string>} full HTML document
 */
export const buildReportHtmlDocument = async () => {
    const root = document.documentElement
    const el = document.getElementById('fundsflow-report')

    if (!el) {
        throw new Error('Report document is not ready')
    }

    const sections = el.querySelectorAll('.report-section')
    if (!sections.length) {
        throw new Error('No report sections selected')
    }

    root.classList.add('printing-report')

    try {
        await waitForCharts()

        const clone = el.cloneNode(true)
        const liveCanvases = el.querySelectorAll('canvas')
        const cloneCanvases = clone.querySelectorAll('canvas')

        liveCanvases.forEach((canvas, index) => {
            const target = cloneCanvases[index]
            if (!target) return

            let dataUrl = ''
            try {
                dataUrl = canvas.toDataURL('image/png')
            } catch {
                dataUrl = ''
            }

            if (!dataUrl) {
                target.remove()
                return
            }

            const img = document.createElement('img')
            img.src = dataUrl
            img.alt = ''
            img.width = canvas.width
            img.height = canvas.height
            const style = canvas.getAttribute('style')
            if (style) img.setAttribute('style', style)
            img.className = canvas.className
            target.replaceWith(img)
        })

        // Absolute-ify same-origin img src (logo) when still relative
        clone.querySelectorAll('img[src]').forEach((img) => {
            const src = img.getAttribute('src')
            if (!src || src.startsWith('data:') || src.startsWith('http://') || src.startsWith('https://')) return
            try {
                img.setAttribute('src', new URL(src, window.location.origin).href)
            } catch {
                // keep as-is
            }
        })

        const css = collectDocumentCss()
        const theme = root.getAttribute('data-theme') || 'bumblebee'
        // Escape theme for attribute context (DaisyUI theme names are alnum/hyphen)
        const safeTheme = String(theme).replace(/[^a-zA-Z0-9_-]/g, '')

        return `<!DOCTYPE html>
<html class="printing-report" lang="en" data-theme="${safeTheme}">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>FundsFlow Report</title>
<style>
${css}
html, body {
  background: var(--color-base-100);
  color: var(--color-base-content);
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
</style>
</head>
<body>
${clone.outerHTML}
</body>
</html>`
    } finally {
        root.classList.remove('printing-report')
    }
}

/**
 * Build print HTML, convert via Gotenberg on the API, send PDF to Telegram.
 * @param {string} caption
 */
export const sendReportPdfToTelegram = async (caption) => {
    if (!requireTelegramLinked()) {
        return { ok: false, needsLink: true }
    }

    try {
        const html = await buildReportHtmlDocument()
        const response = await api.post(
            '/telegram/send-report',
            { html, caption: caption || null },
            { timeout: 180_000 },
        )
        toasts.success(response.data?.message || 'Sent to Telegram successfully!')
        return { ok: true }
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Failed to send report to Telegram: '))
        return { ok: false, error }
    }
}
