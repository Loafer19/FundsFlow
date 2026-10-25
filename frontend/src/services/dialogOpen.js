import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Tracks a native <dialog> by id: `isOpen` mirrors its open attribute, `hasOpened` latches true
 * on first open. Lets a modal mount heavy content (emoji picker) only once it is actually shown.
 */
export const useDialogOpen = (id) => {
    const isOpen = ref(false)
    const hasOpened = ref(false)
    let observer = null

    const sync = (el) => {
        isOpen.value = el.open
        if (el.open) hasOpened.value = true
    }

    onMounted(() => {
        const el = document.getElementById(id)
        if (!el) return
        sync(el)
        observer = new MutationObserver(() => sync(el))
        observer.observe(el, { attributes: true, attributeFilter: ['open'] })
    })

    onBeforeUnmount(() => observer?.disconnect())

    return { isOpen, hasOpened }
}
