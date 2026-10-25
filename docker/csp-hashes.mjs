// Collects SHA-256 hashes of the inline <script> blocks in a static build (the app's theme snippet,
// Starlight's theme/sidebar/search scripts) and appends them as <VAR>="'sha256-…' …" to a Caddy env file,
// so the CSP in docker/Caddyfile.frontend can list exact hashes instead of script-src 'unsafe-inline'.
// Usage: bun docker/csp-hashes.mjs <dist dir> <env file> <VAR>
import { createHash } from 'node:crypto'
import { appendFileSync, readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

const walk = (dir) =>
    readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name)
        return entry.isDirectory() ? walk(path) : [path]
    })

const [root, envFile, name] = process.argv.slice(2)
if (!root || !envFile || !name) {
    console.error('Usage: bun csp-hashes.mjs <dist dir> <env file> <VAR>')
    process.exit(1)
}

// Data blocks (JSON, ld+json) are never executed, so CSP does not apply to them.
const EXECUTABLE_TYPES = new Set(['', 'module', 'text/javascript', 'application/javascript'])
const SCRIPT = /<script(?![^>]*\bsrc=)([^>]*)>([\s\S]*?)<\/script>/gi

const hashes = new Set()
for (const file of walk(root).filter((path) => path.endsWith('.html'))) {
    for (const [, attrs, body] of readFileSync(file, 'utf8').matchAll(SCRIPT)) {
        const type = (attrs.match(/\btype=["']?([^"'\s>]+)/i)?.[1] ?? '').toLowerCase()
        if (!EXECUTABLE_TYPES.has(type) || body.length === 0) continue
        hashes.add(`'sha256-${createHash('sha256').update(body, 'utf8').digest('base64')}'`)
    }
}

const list = [...hashes].sort().join(' ')
appendFileSync(envFile, `${name}="${list}"\n`)
console.log(`${envFile}: ${name} = ${hashes.size} inline script hashes`)
