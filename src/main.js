import { createApp, reactive } from 'vue'
import './assets/css/main.css'
import App from './App.vue'

const toasts = reactive([])

createApp(App).provide('toasts', toasts).mount('body')
