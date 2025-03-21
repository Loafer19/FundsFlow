import { createApp, reactive } from 'vue'
import './assets/css/main.css'
import App from './App.vue'

const toasts = reactive([])

const formatMoney = (amount) => new Intl.NumberFormat('uk-UA', {
    style: 'currency',
    currency: 'UAH'
}).format(amount)

const formatDate = (date) => new Date(date).toLocaleDateString()

createApp(App).provide('toasts', toasts)
    .provide('formatMoney', formatMoney)
    .provide('formatDate', formatDate)
    .mount('body')
