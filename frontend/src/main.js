import { createApp, reactive } from 'vue'
import './assets/css/main.css'
import App from './App.vue'

const toasts = reactive([])

const formatMoney = (amount) =>
    new Intl.NumberFormat('uk-UA', {
        style: 'currency',
        currency: 'UAH',
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(amount)

const formatDate = (date) => new Date(date).toLocaleDateString('uk-UA')

createApp(App)
    .provide('toasts', toasts)
    .provide('formatMoney', formatMoney)
    .provide('formatDate', formatDate)
    .mount('body')
