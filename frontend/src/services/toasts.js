import { reactive } from 'vue'
import { haptic } from './telegramWebApp.js'

const toasts = reactive([])

const add = (toast) => {
    toasts.push({
        id: crypto.randomUUID(),
        type: 'info',
        ...toast,
    })
}

toasts.add = add
// Every save and failure already toasts, so this is where the Mini App's haptics live.
toasts.success = (message) => {
    haptic.success()
    add({ type: 'success', message })
}
toasts.info = (message) => add({ type: 'info', message })
toasts.error = (message) => {
    haptic.error()
    add({ type: 'error', message })
}

export default toasts
