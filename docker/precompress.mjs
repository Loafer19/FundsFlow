// Prepares a static build for Caddy's file_server (docker/Caddyfile.frontend):
// - writes .br and .gz next to every compressible file, for `precompressed br gzip`;
// - writes a .etag sidecar with a content hash next to every served file (originals and .br/.gz),
//   for `etag_file_extensions .etag`. Caddy's default ETag is mtime+size, which changes on every
//   image build even when the bytes don't; a content hash keeps 304s working across deploys.
// Usage: bun docker/precompress.mjs <dir>
import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { brotliCompressSync, constants, gzipSync } from 'node:zlib'

const COMPRESSIBLE = /\.(html|js|mjs|css|json|svg|xml|txt|map|webmanifest|wasm|ico)$/i
const SIDECAR = /\.(br|gz|etag)$/i
const MIN_SIZE = 1024

const walk = (dir) =>
    readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name)
        return entry.isDirectory() ? walk(path) : [path]
    })

// Strong ETag from the exact bytes served, so each encoding gets its own value.
const writeEtag = (file, data) => {
    const hash = createHash('sha256').update(data).digest('base64url').slice(0, 22)
    writeFileSync(`${file}.etag`, `"${hash}"`)
}

const root = process.argv[2]
if (!root) {
    console.error('Usage: bun precompress.mjs <dir>')
    process.exit(1)
}

let compressed = 0
let tagged = 0
for (const file of walk(root)) {
    if (SIDECAR.test(file)) continue
    const data = readFileSync(file)
    writeEtag(file, data)
    tagged++
    if (!COMPRESSIBLE.test(file) || data.length < MIN_SIZE) continue

    const br = brotliCompressSync(data, {
        params: {
            [constants.BROTLI_PARAM_QUALITY]: constants.BROTLI_MAX_QUALITY,
            [constants.BROTLI_PARAM_SIZE_HINT]: data.length,
        },
    })
    const gz = gzipSync(data, { level: 9 })
    for (const [ext, out] of [
        ['.br', br],
        ['.gz', gz],
    ]) {
        // Not worth a sidecar if it barely shrinks; Caddy then serves the original as is.
        if (out.length > data.length * 0.9) continue
        writeFileSync(`${file}${ext}`, out)
        writeEtag(`${file}${ext}`, out)
    }
    compressed++
}
console.log(`${root}: ${compressed} files precompressed, ${tagged} ETags`)
