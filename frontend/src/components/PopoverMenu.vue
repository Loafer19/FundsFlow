<template>
    <div ref="root" :class="rootClass">
        <slot name="trigger" :open="open" :toggle="toggle" :close="close" />
        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-[1100]" @mousedown.self="close">
                <div ref="panel" class="fixed bg-base-100 rounded-box border border-base-300 shadow-lg p-3"
                    :class="panelClass" role="dialog" :aria-label="label" :style="style" @keydown.escape.stop="close">
                    <slot :close="close" />
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({
    label: { type: String, default: 'Menu' },
    panelClass: { type: String, default: 'w-64' },
    rootClass: { type: String, default: 'inline-flex max-w-full' },
})

const open = defineModel({ type: Boolean, default: false })

const root = ref(null)
const panel = ref(null)
const style = ref({})

const place = () => {
    const rect = root.value?.getBoundingClientRect()
    if (!rect) return

    const width = panel.value?.offsetWidth || 256
    const height = panel.value?.offsetHeight || 320
    let left = Math.min(rect.left, window.innerWidth - width - 8)
    left = Math.max(8, left)
    let top = rect.bottom + 8
    if (top + height > window.innerHeight - 8) {
        top = Math.max(8, rect.top - height - 8)
    }
    style.value = { top: `${top}px`, left: `${left}px` }
}

const close = () => {
    open.value = false
}

const toggle = () => {
    open.value = !open.value
}

const onKey = (event) => {
    if (event.key === 'Escape' && open.value) {
        event.stopPropagation()
        close()
    }
}

watch(open, async (isOpen) => {
    if (!isOpen) return
    await nextTick()
    place()
    await nextTick()
    place()
})

if (typeof window !== 'undefined') {
    watch(open, (isOpen) => {
        if (isOpen) {
            window.addEventListener('keydown', onKey, true)
            window.addEventListener('resize', place)
        } else {
            window.removeEventListener('keydown', onKey, true)
            window.removeEventListener('resize', place)
        }
    })
}

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey, true)
    window.removeEventListener('resize', place)
})

defineExpose({ open, toggle, close })
</script>
