import { reactive } from 'vue'

const toasts = reactive([])

const add = (toast) => {
    toasts.push({
        id: crypto.randomUUID(),
        type: 'info',
        ...toast,
    })
}

toasts.add = add
toasts.success = (message) => add({ type: 'success', message })
toasts.info = (message) => add({ type: 'info', message })
toasts.error = (message) => add({ type: 'error', message })

export default toasts
