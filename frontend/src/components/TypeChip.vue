<template>
    <component :is="tag" v-if="type" class="badge badge-soft gap-1 font-medium border max-w-full" :class="sizeClass"
        :style="style" :title="title">
        <span v-if="type.emoji" aria-hidden="true">{{ type.emoji }}</span>
        <span class="truncate">{{ type.name }}<template v-if="values"><span class="opacity-50"> · </span>{{ values
        }}</template></span>
    </component>
</template>

<script setup>
// "⛽ Fuel · 40 l": the transaction type as the first chip of a list row, tinted with the type color.
import { computed } from 'vue'
import { useTransactionTypesStore } from '../services/transactionTypes.js'
import { typeChipValues, typeRows } from '../services/typeFields.js'

const props = defineProps({
    transaction: { type: Object, required: true },
    size: { type: String, default: 'md' },
    tag: { type: String, default: 'span' },
    // Already formatted values (assistant cards get fields as text, not raw data).
    presetValues: { type: String, default: null },
    // Name only, for narrow cards (the title still lists every field).
    compact: { type: Boolean, default: false },
})

const typesStore = useTransactionTypesStore()
const type = computed(() => typesStore.byId(props.transaction?.transaction_type_id))
const values = computed(() => {
    if (props.compact) return ''
    if (props.presetValues !== null) return props.presetValues
    return type.value ? typeChipValues(type.value, props.transaction.data) : ''
})
const title = computed(() =>
    type.value
        ? [
              type.value.name,
              ...typeRows(type.value, props.transaction.data ?? {}).map((row) => `${row.label}: ${row.value}`),
          ].join('\n')
        : undefined,
)
const sizeClass = computed(() =>
    props.size === 'xs' ? 'badge-xs px-1' : props.size === 'sm' ? 'badge-sm px-1' : 'px-1',
)
const style = computed(() => {
    const color = type.value?.color
    return color
        ? { backgroundColor: `${color}26`, borderColor: `${color}73`, color: 'var(--color-base-content)' }
        : { borderColor: 'color-mix(in oklab, var(--color-base-content) 25%, transparent)' }
})
</script>
