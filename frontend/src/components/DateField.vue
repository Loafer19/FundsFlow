<template>
    <div class="relative">
        <div class="input w-full flex items-center gap-2 pointer-events-none" :class="[sm ? 'input-sm' : '', inputClass]">
            <CalendarDays :size="16" class="shrink-0 opacity-60" />
            <span class="flex-1 truncate text-left" :class="{ 'text-base-content/40': !model }">
                {{ model ? formatDate(parseLocalDate(model)) : placeholder }}
            </span>
            <button v-if="clearable && model" type="button"
                class="btn btn-ghost btn-xs btn-square pointer-events-auto relative z-10 shrink-0"
                aria-label="Clear date" @click.stop="clear">
                <X :size="14" />
            </button>
        </div>
        <input ref="input" type="date" class="absolute inset-0 opacity-0 cursor-pointer"
            :class="clearable && model ? 'right-8' : ''" :value="model" :min="min || undefined" :max="max || undefined"
            :id="inputId" :aria-label="resolvedLabel" :required="required" :disabled="disabled"
            @click="openPicker" @change="onChange" />
    </div>
</template>

<script setup>
import { CalendarDays, X } from '@lucide/vue'
import { computed, ref, useAttrs } from 'vue'
import { formatDate, parseLocalDate } from '../services/formatters.js'
import { haptic } from '../services/telegramWebApp.js'

const props = defineProps({
    placeholder: { type: String, default: 'Pick a date' },
    ariaLabel: { type: String, default: 'Date' },
    inputId: { type: String, default: undefined },
    min: { type: String, default: '' },
    max: { type: String, default: '' },
    sm: { type: Boolean, default: false },
    clearable: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    inputClass: { type: String, default: '' },
})

const model = defineModel({ type: String, default: '' })
const input = ref(null)
const attrs = useAttrs()
const resolvedLabel = computed(() => props.ariaLabel || attrs['aria-label'] || 'Date')

const openPicker = (event) => {
    try {
        event.target.showPicker?.()
    } catch {
        // Not allowed here (e.g. cross-origin iframe) — the native input still works.
    }
}

const onChange = (event) => {
    model.value = event.target.value || ''
    if (model.value) haptic.select()
}

const clear = () => {
    model.value = ''
}
</script>
