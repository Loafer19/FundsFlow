<template>
    <section v-if="type && fields.length" class="flex flex-col gap-3"
        :class="bare ? '' : 'rounded-box border border-base-300 p-3 mb-4'" :aria-label="`${type.name} fields`">
        <h3 v-if="!bare" class="text-xs font-semibold uppercase tracking-wide text-base-content/60">{{ type.name }} fields</h3>
        <FieldInput v-for="field in fields" :key="field.key" :field="field" :model-value="data[field.key] ?? null"
            :computed-text="field.type === 'computed' ? computedText(field) : ''"
            @update:model-value="(value) => set(field.key, value)" />
    </section>
</template>

<script setup>
import { computed } from 'vue'
import { displayValue, evaluateFormula, numericValues, visibleFields } from '../services/typeFields.js'
import FieldInput from './FieldInput.vue'

const props = defineProps({
    type: { type: Object, default: null },
    /** No frame or title (the Manage Types preview). */
    bare: { type: Boolean, default: false },
})

const data = defineModel('data', { type: Object, default: () => ({}) })

const fields = computed(() => visibleFields(props.type))
const inputs = computed(() => numericValues(props.type, data.value))

const computedText = (field) => {
    const value = evaluateFormula(field.formula, inputs.value)
    return value === null ? '' : displayValue(field, value)
}

const set = (key, value) => {
    data.value = { ...data.value, [key]: value }
}
</script>
