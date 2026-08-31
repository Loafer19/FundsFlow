<template>
    <div class="flex flex-col gap-2 mb-4">
        <div class="join w-full">
            <button type="button" class="btn join-item" :class="isIncome ? 'btn-success' : 'btn-ghost'"
                aria-label="Income" :aria-pressed="isIncome" @click="setType(true)">
                Income
            </button>
            <button type="button" class="btn join-item" :class="!isIncome ? 'btn-error' : 'btn-ghost'"
                aria-label="Expense" :aria-pressed="!isIncome" @click="setType(false)">
                Expense
            </button>
        </div>

        <input type="number" step="0.01" min="0.01" required placeholder="Amount" aria-label="Amount" :value="display"
            autofocus class="input w-full font-semibold"
            :class="isIncome ? 'input-success text-success' : 'input-error text-error'" @input="onInput" />
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const model = defineModel({ type: [Number, String], default: '' })

const isIncome = ref(true)
const display = ref('')

const applyModel = (value) => {
    if (value === '' || value === null || value === undefined) {
        display.value = ''
        return
    }

    const number = Number(value)
    if (Number.isNaN(number)) {
        display.value = ''
        return
    }

    isIncome.value = number >= 0
    display.value = number === 0 ? '0' : String(Math.abs(number))
}

watch(model, applyModel, { immediate: true })

const emitSigned = () => {
    if (display.value === '' || display.value === null) {
        model.value = ''
        return
    }

    const absolute = Math.abs(Number(display.value))
    if (Number.isNaN(absolute)) {
        model.value = ''
        return
    }

    model.value = isIncome.value ? absolute : -absolute
}

const setType = (income) => {
    isIncome.value = income
    emitSigned()
}

const onInput = (event) => {
    const raw = event.target.value

    if (raw === '') {
        display.value = ''
        model.value = ''
        return
    }

    if (raw.includes('-') || Number(raw) < 0) {
        isIncome.value = false
        const absolute = Math.abs(Number(raw))
        display.value = Number.isNaN(absolute) ? '' : String(absolute)
        event.target.value = display.value
        emitSigned()
        return
    }

    display.value = raw
    emitSigned()
}
</script>
