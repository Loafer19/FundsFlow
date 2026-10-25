import { createPinia } from 'pinia'
import { createApp } from 'vue'
import './assets/css/main.css'
import App from './App.vue'
import { formatDate, formatMoney, formatPercentage } from './services/formatters'
import toasts from './services/toasts'

// After a deploy the old hashed chunks are gone: a lazy tab/picker import then fails.
// Reload once to pick up the new index.html (guarded so a real outage cannot loop).
window.addEventListener('vite:preloadError', (event) => {
    const key = 'chunk-reload-at'
    const last = Number(sessionStorage.getItem(key) || 0)
    if (Date.now() - last < 10000) return
    sessionStorage.setItem(key, String(Date.now()))
    event.preventDefault()
    window.location.reload()
})

const mount = (root) =>
    createApp(root)
        .use(createPinia())
        .provide('toasts', toasts)
        .provide('formatDate', formatDate)
        .provide('formatMoney', formatMoney)
        .provide('formatPercentage', formatPercentage)
        .mount('body')

// /?print=report#t=… is the report page Gotenberg prints to PDF (see PrintReport.vue).
if (new URLSearchParams(window.location.search).get('print') === 'report') {
    import('./PrintReport.vue').then((module) => mount(module.default))
} else {
    mount(App)
}
