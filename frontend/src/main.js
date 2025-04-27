import { createPinia } from 'pinia'
import { createApp, reactive } from 'vue'
import './assets/css/main.css'
import App from './App.vue'
import { formatDate, formatMoney, formatPercentage } from './services/formatters'

const toasts = reactive([])

toasts.success = (message) => toasts.push({ type: 'success', message })
toasts.info = (message) => toasts.push({ type: 'info', message })
toasts.error = (message) => toasts.push({ type: 'error', message })

createApp(App)
    .use(createPinia())
    .provide('toasts', toasts)
    .provide('formatDate', formatDate)
    .provide('formatMoney', formatMoney)
    .provide('formatPercentage', formatPercentage)
    .mount('body')
