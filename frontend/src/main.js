import { createPinia } from 'pinia'
import { createApp } from 'vue'
import './assets/css/main.css'
import App from './App.vue'
import { formatDate, formatMoney, formatPercentage } from './services/formatters'
import toasts from './services/toasts'

createApp(App)
    .use(createPinia())
    .provide('toasts', toasts)
    .provide('formatDate', formatDate)
    .provide('formatMoney', formatMoney)
    .provide('formatPercentage', formatPercentage)
    .mount('body')
