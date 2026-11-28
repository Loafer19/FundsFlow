<template>
    <ul class="flex flex-col min-w-0">
        <li v-for="row in rows" :key="row.key" class="border-b border-base-300/60 last:border-b-0">
            <button v-if="row.more" type="button"
                class="w-full flex items-center gap-2 py-2 px-1 text-left min-w-0 rounded-field cursor-pointer hover:bg-base-200/60"
                @click="emit('more')">
                <span class="w-6 shrink-0"></span>
                <span class="badge badge-soft text-xl py-4 px-2 shrink-0">⋯</span>
                <span class="truncate flex-1 min-w-0">Other · {{ row.more }} tags</span>
                <span class="font-medium tabular-nums shrink-0">{{ formatMoney(row.amount) }}</span>
            </button>

            <div v-else class="flex items-stretch min-w-0 [--indent:0.875rem] sm:[--indent:1.25rem]"
                :style="{ paddingLeft: `calc(${row.depth} * var(--indent))` }">
                <template v-if="!inReport">
                    <button v-if="row.hasChildren" type="button"
                        class="btn btn-ghost btn-xs btn-square self-center shrink-0"
                        :aria-expanded="row.open" :aria-label="`${row.open ? 'Collapse' : 'Expand'} ${row.title}`"
                        @click="emit('toggle', row)">
                        <ChevronRight :size="16" class="transition-transform duration-200"
                            :class="{ 'rotate-90': row.open }" />
                    </button>
                    <span v-else class="w-6 shrink-0"></span>
                </template>

                <component :is="row.clickable ? 'button' : 'div'" :type="row.clickable ? 'button' : undefined"
                    class="flex-1 min-w-0 py-2 px-1 flex flex-col gap-1 text-left rounded-field"
                    :class="{ 'cursor-pointer hover:bg-base-200/60': row.clickable }"
                    :aria-label="row.clickable ? `Open ${row.title} details` : undefined"
                    @click="row.clickable && emit('select', row)">
                    <span class="flex items-center gap-2 min-w-0">
                        <span class="badge badge-soft text-xl py-4 px-2 shrink-0" :class="badge">{{ row.emoji }}</span>
                        <span class="truncate flex-1 min-w-0">{{ row.title }}</span>
                        <span v-if="row.change.label"
                            class="badge badge-sm tabular-nums shrink-0 tooltip tooltip-left before:max-w-52"
                            :class="row.change.badge" :data-tip="`Previous period: ${formatMoney(row.previousAmount)}`">
                            {{ row.change.label }}
                        </span>
                        <span class="font-medium tabular-nums shrink-0">{{ formatMoney(row.amount) }}</span>
                    </span>
                    <span class="flex items-center gap-2 min-w-0">
                        <progress class="progress h-1 flex-1 min-w-0" :style="{ color: row.color }" :value="row.share"
                            max="100"></progress>
                        <span class="text-xs text-base-content/60 tabular-nums shrink-0">
                            {{ formatPercentage(row.share) }} · {{ row.count }} txn{{ row.count === 1 ? '' : 's' }}
                        </span>
                    </span>
                </component>
            </div>
        </li>
    </ul>
</template>

<script setup>
import { ChevronRight } from '@lucide/vue'
import { inject } from 'vue'

defineProps({
    // Visible rows, already ordered: { key, title, emoji, depth, amount, count, share, color, change, previousAmount,
    // hasChildren, open, clickable } plus the synthetic { key, more, amount } "Other · N tags" row.
    rows: { type: Array, required: true },
    // Badge colour class of the section (badge-success / badge-error).
    badge: { type: String, default: '' },
    inReport: { type: Boolean, default: false },
})

const emit = defineEmits(['select', 'toggle', 'more'])

const formatMoney = inject('formatMoney')
const formatPercentage = inject('formatPercentage')
</script>
