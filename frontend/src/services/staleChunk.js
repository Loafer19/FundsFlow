/**
 * After a deploy the old hashed chunks are gone, so a lazy import from a page that was open
 * before the deploy fails. Reload once to pick up the new index.html instead of showing an
 * error. Guarded in sessionStorage so a real outage (or a cached old index.html) can't loop.
 */
const KEY = 'chunk-reload-at'
const GUARD_MS = 60000
const PATTERN =
    /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|Unable to preload CSS|ChunkLoadError|Loading (CSS )?chunk .+ failed/i

let reloading = false

export const isStaleChunkError = (error) => {
    if (!error) return false
    if (error.name === 'ChunkLoadError') return true
    return PATTERN.test(String(error.message ?? error))
}

/** Starts a reload unless one ran in the last minute; true when the page is about to reload. */
export const reloadForStaleChunk = () => {
    if (reloading) return true
    let last = 0
    try {
        last = Number(sessionStorage.getItem(KEY) || 0)
    } catch {
        return false
    }
    if (Date.now() - last < GUARD_MS) return false
    try {
        sessionStorage.setItem(KEY, String(Date.now()))
    } catch {
        return false
    }
    reloading = true
    window.location.reload()
    return true
}

export const isReloadingForStaleChunk = () => reloading

// Never settles: the component keeps its loading state until the page reloads.
const pending = () => new Promise(() => {})

/**
 * Wraps a lazy import() for defineAsyncComponent: a stale chunk reloads the page once
 * (the loading state stays up meanwhile); any other error, or a second failure, still
 * reaches the component's error state.
 */
export const lazyImport = (loader) => () =>
    loader().then(
        (module) => (module === undefined && reloading ? pending() : module),
        (error) => {
            if (isStaleChunkError(error) && reloadForStaleChunk()) return pending()
            throw error
        },
    )

/** Global hooks: Vite's preload failures and stray rejected dynamic imports. */
export const installStaleChunkReload = () => {
    window.addEventListener('vite:preloadError', (event) => {
        if (reloadForStaleChunk()) event.preventDefault()
    })
    window.addEventListener('unhandledrejection', (event) => {
        if (isStaleChunkError(event.reason) && reloadForStaleChunk()) event.preventDefault()
    })
}
