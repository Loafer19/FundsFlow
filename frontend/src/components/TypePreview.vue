<template>
    <div class="flex flex-col gap-3">
        <span class="badge font-medium w-fit"
            :style="{ backgroundColor: color, borderColor: color, color: readableTextOn(color) }">
            {{ emoji }} {{ name || 'New type' }}
        </span>
        <TypeFieldsCard :type="type" v-model:data="data" bare />
        <div class="rounded-box bg-base-200 p-3 text-sm">
            <span class="flex items-center gap-1 text-xs font-semibold text-base-content/70 mb-1">
                <Send :size="12" /> Log it in Telegram
            </span>
            <span class="italic">“{{ telegramExample }}”</span>
        </div>
    </div>
</template>

<script setup>
import { Send } from '@lucide/vue'
import { readableTextOn } from '../services/typeFields.js'
import TypeFieldsCard from './TypeFieldsCard.vue'

defineProps({
    color: { type: String, default: '#f59e0b' },
    emoji: { type: String, default: '' },
    name: { type: String, default: '' },
    type: { type: Object, default: null },
    telegramExample: { type: String, default: '' },
})

const data = defineModel('data', { type: Object, default: () => ({}) })
</script>
