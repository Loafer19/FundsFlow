<template>
    <div v-if="choices.length" class="mb-4">
        <div class="text-sm text-base-content/80 mb-1" :id="labelId">Type</div>
        <div class="flex flex-wrap gap-1.5" role="radiogroup" :aria-labelledby="labelId">
            <button type="button" role="radio" class="btn btn-sm" :aria-checked="model == null"
                :class="model == null ? 'btn-primary' : 'btn-soft'" @click="pick(null)">None</button>
            <button v-for="type in choices" :key="type.id" type="button" role="radio" class="btn btn-sm"
                :aria-checked="model === type.id" :class="model === type.id ? 'btn-primary' : 'btn-soft'"
                :style="model === type.id && type.color ? { backgroundColor: type.color, borderColor: type.color, color: '#fff' } : null"
                :title="type.archived_at ? 'Archived type' : null" @click="pick(type.id)">
                <span v-if="type.emoji">{{ type.emoji }}</span>
                {{ type.name }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, useId } from 'vue'
import { haptic } from '../services/telegramWebApp.js'
import { useTransactionTypesStore } from '../services/transactionTypes.js'

const typesStore = useTransactionTypesStore()

const model = defineModel({ type: [Number, null], default: null })
const emit = defineEmits(['pick'])
const labelId = useId()

// Active types, plus an archived one the transaction already has (it can keep it).
const choices = computed(() => {
    const list = [...typesStore.active]
    const current = typesStore.byId(model.value)
    if (current?.archived_at) list.push(current)
    return list
})

const pick = (id) => {
    if (id === model.value) return
    haptic.select()
    emit('pick', id)
}
</script>
