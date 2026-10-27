<template>
    <div class="flex flex-col gap-2 mb-4">
        <div v-if="signed" class="join w-full">
            <button type="button" class="btn join-item" :class="isIncome ? 'btn-success' : 'btn-ghost'"
                aria-label="Income" :aria-pressed="isIncome" @click="setType(true)">
                Income
            </button>
            <button type="button" class="btn join-item" :class="!isIncome ? 'btn-error' : 'btn-ghost'"
                aria-label="Expense" :aria-pressed="!isIncome" @click="setType(false)">
                Expense
            </button>
        </div>

        <!-- type="text" + inputmode="decimal": a decimal keypad on phones, and we parse
             "1 500,50" / "120+35" ourselves instead of the browser silently dropping it. -->
        <input ref="input" type="text" inputmode="decimal" autocomplete="off" enterkeyhint="done" :required="required"
            :placeholder="placeholder" :aria-label="label" :value="display" :autofocus="autofocus"
            :aria-invalid="Boolean(shownError)" :aria-describedby="hintId" class="input w-full font-semibold"
            :class="signed ? (isIncome ? 'input-success text-success' : 'input-error text-error') : ''"
            @input="onInput" @blur="onBlur" />

        <p v-if="shownError" :id="hintId" class="text-error text-sm -mt-1">{{ shownError }}</p>
        <p v-else-if="preview" :id="hintId" class="text-base-content/60 text-sm -mt-1">= {{ preview }}</p>
    </div>
</template>

<script setup>
import { computed, ref, useId, watch, watchEffect } from 'vue'
import { parseAmountInput, toEditableAmount } from '../services/amountInput.js'
import { formatMoney } from '../services/formatters.js'
import settings from '../services/settings.js'
import { haptic } from '../services/telegramWebApp.js'

const props = defineProps({
    /** Income/Expense toggle and a signed model; false for always-positive amounts (budgets). */
    signed: { type: Boolean, default: true },
    /** Column limit: 9 999 999 999.99 for transactions, 99 999 999.99 for budgets/recurring. */
    max: { type: Number, default: 9_999_999_999.99 },
    autofocus: { type: Boolean, default: true },
    placeholder: { type: String, default: 'Amount' },
    /** Optional fields (a goal's start amount) may stay empty; the model is then ''. */
    required: { type: Boolean, default: true },
    label: { type: String, default: 'Amount' },
})

const model = defineModel({ type: [Number, String], default: '' })

const input = ref(null)
const isIncome = ref(false)
const display = ref('')
const editing = ref(false)
const hintId = useId()

// What this field last wrote to the model, so the watcher below doesn't
// reformat the text the user is still typing ("12," would become "12").
let lastEmitted

const parsed = computed(() => parseAmountInput(display.value, settings.moneyFormat))

const error = computed(() => {
    const result = parsed.value

    if (result.empty) return null
    if (result.error) return result.error
    if (result.value > props.max) return 'Amount is too large'

    return null
})

// Don't nag about "120+" while it's still being typed.
const shownError = computed(() => (parsed.value.incomplete && editing.value ? null : error.value))

const preview = computed(() => (parsed.value.expression && !error.value ? formatMoney(parsed.value.value) : null))

// Native form validation blocks submit with our message.
watchEffect(() => input.value?.setCustomValidity(error.value ?? ''))

const emitModel = () => {
    const result = parsed.value
    const valid = !result.empty && result.value !== null && !error.value
    const signedValue = props.signed && !isIncome.value ? -result.value : result.value

    lastEmitted = valid ? signedValue : ''
    model.value = lastEmitted
}

const applyModel = (value) => {
    if (value === '' || value === null || value === undefined || Number.isNaN(Number(value))) {
        display.value = ''

        return
    }

    const number = Number(value)

    if (props.signed) isIncome.value = number >= 0
    display.value = number === 0 ? '' : toEditableAmount(number, settings.moneyFormat)
}

watch(
    model,
    (value) => {
        if (value !== lastEmitted) applyModel(value)
    },
    { immediate: true },
)

const setType = (income) => {
    if (isIncome.value !== income) haptic.select()

    isIncome.value = income
    emitModel()
}

const onInput = (event) => {
    let raw = event.target.value
    const lead = raw.match(/^\s*([+\-−–])/)

    // A leading sign picks the type and leaves the field; "+" or "-" later in
    // the text is arithmetic ("120+35").
    if (lead) {
        const income = lead[1] === '+'

        if (props.signed && isIncome.value !== income) {
            isIncome.value = income
            haptic.select()
        }

        raw = raw.slice(lead[0].length)
        event.target.value = raw
    }

    editing.value = true
    display.value = raw
    emitModel()
}

const onBlur = () => {
    editing.value = false

    // Collapse "120+35" into "155" once the user is done with the field.
    if (parsed.value.expression && !error.value) {
        display.value = toEditableAmount(parsed.value.value, settings.moneyFormat)
    }
}
</script>
