<template>
    <div class="join" :class="{ 'w-full': block }" role="radiogroup" :aria-label="label">
        <button v-for="option in visible" :key="String(option.value)" type="button" role="radio"
            class="btn join-item"
            :class="[sizeClass, block ? 'flex-1' : '', model === option.value ? 'btn-primary' : 'btn-ghost border-base-300']"
            :aria-checked="model === option.value" :aria-label="option.ariaLabel || option.label"
            :disabled="option.disabled" @click="pick(option.value)">
            <component :is="option.icon" v-if="option.icon" :size="size === 'sm' ? 16 : 18" />
            {{ option.label }}
        </button>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { haptic } from '../services/telegramWebApp.js'

const props = defineProps({
    // [{ value, label, icon?, hidden?, disabled?, ariaLabel? }]
    options: { type: Array, required: true },
    label: { type: String, required: true },
    size: { type: String, default: 'md' },
    block: { type: Boolean, default: false },
})

const model = defineModel({ required: true })

const visible = computed(() => props.options.filter((option) => !option.hidden))
const sizeClass = computed(() => (props.size === 'sm' ? 'btn-sm' : ''))

const pick = (value) => {
    if (model.value === value) return
    model.value = value
    haptic.select()
}
</script>
