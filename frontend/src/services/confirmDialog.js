import { reactive } from 'vue'

export const confirmDialog = reactive({
    open: false,
    title: '',
    message: '',
    actionLabel: 'Confirm',
    danger: false,
})

let resolver = null

export const askConfirm = ({ title, message, actionLabel = 'Confirm', danger = false }) => {
    if (resolver) resolver(false)

    Object.assign(confirmDialog, { open: true, title, message, actionLabel, danger: !!danger })

    return new Promise((resolve) => {
        resolver = resolve
    })
}

export const settleConfirm = (ok) => {
    confirmDialog.open = false

    const resolve = resolver
    resolver = null
    resolve?.(!!ok)
}
