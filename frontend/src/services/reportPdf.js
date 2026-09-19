import html2canvas from 'html2canvas'
import { jsPDF } from 'jspdf'

const waitForCharts = async () => {
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))
    await new Promise((resolve) => setTimeout(resolve, 300))
}

/**
 * Build a landscape A4 PDF from #fundsflow-report (one page per .report-section; header on first page).
 * @returns {Promise<Blob>}
 */
export const buildReportPdfBlob = async () => {
    const root = document.documentElement
    const el = document.getElementById('fundsflow-report')

    if (!el) {
        throw new Error('Report document is not ready')
    }

    root.classList.add('printing-report')

    try {
        await waitForCharts()

        const header = el.querySelector('.report-header')
        const sections = [...el.querySelectorAll('.report-section')]

        if (!sections.length) {
            throw new Error('No report sections selected')
        }

        const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' })
        const pageWidth = pdf.internal.pageSize.getWidth()
        const pageHeight = pdf.internal.pageSize.getHeight()
        const margin = 8
        const usableWidth = pageWidth - margin * 2

        const capture = async (node) =>
            html2canvas(node, {
                scale: 2,
                useCORS: true,
                logging: false,
                backgroundColor: '#ffffff',
                windowWidth: Math.max(el.scrollWidth, 1100),
            })

        for (let i = 0; i < sections.length; i++) {
            if (i > 0) pdf.addPage()

            let y = margin

            if (i === 0 && header) {
                const headerCanvas = await capture(header)
                const headerHeight = (headerCanvas.height * usableWidth) / headerCanvas.width
                pdf.addImage(headerCanvas.toDataURL('image/jpeg', 0.92), 'JPEG', margin, y, usableWidth, headerHeight)
                y += headerHeight + 4
            }

            const sectionCanvas = await capture(sections[i])
            const naturalHeight = (sectionCanvas.height * usableWidth) / sectionCanvas.width
            const maxHeight = pageHeight - y - margin
            const scale = naturalHeight > maxHeight ? maxHeight / naturalHeight : 1
            const drawWidth = usableWidth * scale
            const drawHeight = naturalHeight * scale
            const x = margin + (usableWidth - drawWidth) / 2

            pdf.addImage(
                sectionCanvas.toDataURL('image/jpeg', 0.88),
                'JPEG',
                x,
                y,
                drawWidth,
                drawHeight,
            )
        }

        return pdf.output('blob')
    } finally {
        root.classList.remove('printing-report')
    }
}
