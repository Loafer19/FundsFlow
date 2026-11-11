import { createPinia } from 'pinia'
import { createApp } from 'vue'
import './assets/css/main.css'
import './styles/agent-motion.css'
import App from './App.vue'
import { formatDate, formatMoney, formatPercentage } from './services/formatters'
import { installStaleChunkReload } from './services/staleChunk'
import toasts from './services/toasts'

installStaleChunkReload()

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
