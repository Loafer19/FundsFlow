// Minimal, escape-first Markdown for assistant chat bubbles.
// Everything is HTML-escaped before any formatting is applied, so model output can never inject markup;
// only http(s) links are turned into anchors. Supports: paragraphs, line breaks, #-headings,
// - / * / 1. lists, **bold**, *italic* / _italic_, `code`, ```code blocks```, [text](https://url).

const escapeHtml = (text) =>
    text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')

const inline = (escaped) => {
    const codes = []
    let out = escaped.replace(/`([^`\n]+)`/g, (_, code) => {
        codes.push(code)
        return `\u0000${codes.length - 1}\u0000`
    })

    out = out
        .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, (_, label, url) => {
            return `<a href="${url}" target="_blank" rel="noopener noreferrer nofollow" class="link">${label}</a>`
        })
        .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*\w])\*([^*\n]+)\*(?!\w)/g, '$1<em>$2</em>')
        .replace(/(^|[^_\w])_([^_\n]+)_(?!\w)/g, '$1<em>$2</em>')

    // biome-ignore lint/suspicious/noControlCharactersInRegex: placeholder delimiter
    return out.replace(/\u0000(\d+)\u0000/g, (_, i) => `<code class="agent-code">${codes[Number(i)]}</code>`)
}

export const renderMarkdown = (source) => {
    const text = escapeHtml(String(source ?? '').replace(/\r\n?/g, '\n'))
    const lines = text.split('\n')
    const html = []
    let list = null
    let paragraph = []
    let code = null

    const flushParagraph = () => {
        if (paragraph.length) html.push(`<p>${paragraph.map(inline).join('<br>')}</p>`)
        paragraph = []
    }
    const flushList = () => {
        if (list)
            html.push(`<${list.tag}>${list.items.map((item) => `<li>${inline(item)}</li>`).join('')}</${list.tag}>`)
        list = null
    }

    for (const line of lines) {
        if (code !== null) {
            if (/^\s*```/.test(line)) {
                html.push(`<pre class="agent-pre"><code>${code.join('\n')}</code></pre>`)
                code = null
            } else {
                code.push(line)
            }
            continue
        }

        if (/^\s*```/.test(line)) {
            flushParagraph()
            flushList()
            code = []
            continue
        }

        const heading = line.match(/^\s*(#{1,6})\s+(.+)$/)
        const bullet = line.match(/^\s*[-*•]\s+(.+)$/)
        const ordered = line.match(/^\s*\d+[.)]\s+(.+)$/)

        if (heading) {
            flushParagraph()
            flushList()
            html.push(`<p class="font-semibold">${inline(heading[2])}</p>`)
        } else if (bullet || ordered) {
            flushParagraph()
            const tag = bullet ? 'ul' : 'ol'
            if (list && list.tag !== tag) flushList()
            list ??= { tag, items: [] }
            list.items.push((bullet ?? ordered)[1])
        } else if (line.trim() === '') {
            flushParagraph()
            flushList()
        } else {
            flushList()
            paragraph.push(line)
        }
    }

    if (code !== null) html.push(`<pre class="agent-pre"><code>${code.join('\n')}</code></pre>`)
    flushParagraph()
    flushList()

    return html.join('')
}
