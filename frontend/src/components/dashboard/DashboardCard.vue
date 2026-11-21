<template>
    <div class="card card-border border-base-300 bg-base-100 min-w-0 h-full dash-card"
        :class="{ 'opacity-60 ring-2 ring-primary/50 shadow-lg': dragging }">
        <div class="card-body p-4 gap-2 min-w-0">
            <div class="flex items-start gap-1.5 min-w-0">
                <button v-if="!preview" type="button"
                    class="btn btn-ghost btn-xs btn-square -ml-1.5 shrink-0 cursor-grab active:cursor-grabbing touch-none report-hide text-base-content/40 tooltip tooltip-right"
                    data-tip="Drag to reorder" aria-label="Drag to reorder" @pointerdown="$emit('drag-start', $event)">
                    <GripVertical :size="16" />
                </button>
                <div class="min-w-0 flex-1">
                    <div class="block min-w-0" :class="{ 'tooltip tooltip-bottom': titleLong }" :data-tip="titleLong ? title : null">
                        <h3 class="font-semibold text-sm leading-5 truncate text-left">
                            <span v-if="type" class="mr-1">{{ type.emoji }}</span>{{ title }}
                        </h3>
                    </div>
                    <div v-if="chips.length" class="flex flex-wrap gap-1 mt-1 min-w-0">
                        <component :is="chip.click ? 'button' : 'span'" v-for="chip in chips" :key="chip.key"
                            :type="chip.click ? 'button' : undefined"
                            class="badge badge-sm badge-soft max-w-full whitespace-nowrap"
                            :class="[chip.class, chip.click ? 'cursor-pointer hover:badge-primary tooltip tooltip-bottom' : '']"
                            :data-tip="chip.click ? `View ${type?.name} analytics` : null" @click="chip.click?.()">
                            {{ chip.text }}
                        </component>
                    </div>
                </div>
                <PopoverMenu v-if="!preview" v-model="menuOpen" label="Card menu" panel-class="w-48 p-2" root-class="inline-flex shrink-0 report-hide">
                    <template #trigger="{ toggle }">
                        <button type="button" class="btn btn-ghost btn-xs btn-square -mr-1.5" aria-label="Card menu"
                            :aria-expanded="menuOpen" @click="toggle">
                            <EllipsisVertical :size="16" />
                        </button>
                    </template>
                    <div class="px-1 pb-1 text-xs uppercase tracking-wide text-base-content/60">Size</div>
                    <div class="join w-full mb-2" role="radiogroup" aria-label="Card size">
                        <button v-for="option in SIZES" :key="option.value" type="button" role="radio"
                            class="join-item btn btn-sm flex-1 tooltip tooltip-bottom"
                            :class="card.size === option.value ? 'btn-primary' : 'btn-ghost border-base-300'"
                            :data-tip="option.tip" :aria-checked="card.size === option.value"
                            @click="$emit('resize', option.value)">
                            {{ option.value }}
                        </button>
                    </div>
                    <ul class="menu menu-sm p-0 w-full">
                        <li><button type="button" @click="act('edit')"><Pencil :size="14" /> Edit</button></li>
                        <li><button type="button" class="text-error" @click="act('delete')"><Trash2 :size="14" /> Delete</button></li>
                    </ul>
                </PopoverMenu>
            </div>

            <CardBody v-if="card.data" :kind="card.kind" :data="card.data" :size="card.size" class="flex-1" />
            <div v-else class="skeleton h-16 w-full"></div>
        </div>
    </div>
</template>

<script setup>
import { EllipsisVertical, GripVertical, Pencil, Trash2 } from '@lucide/vue'
import { computed, defineAsyncComponent, ref } from 'vue'
import { useTagsStore } from '../../services/tags.js'
import { useTransactionTypesStore } from '../../services/transactionTypes.js'
import PopoverMenu from '../PopoverMenu.vue'

// Chart.js stays out of the main bundle: the body (charts) loads with the first card.
const CardBody = defineAsyncComponent(() => import('./CardBody.vue'))

const props = defineProps({
    card: { type: Object, required: true },
    // Editor preview: no handle and no menu.
    preview: { type: Boolean, default: false },
    dragging: { type: Boolean, default: false },
})

const emit = defineEmits(['drag-start', 'resize', 'edit', 'delete'])

const SIZES = [
    { value: 'S', tip: 'Small · 1 column' },
    { value: 'M', tip: 'Medium · 2 columns' },
    { value: 'L', tip: 'Large · full width' },
]

const typesStore = useTransactionTypesStore()
const tagsStore = useTagsStore()
const menuOpen = ref(false)

const type = computed(() => props.card.data?.type ?? typesStore.byId(props.card.config?.type_id))
const title = computed(() => props.card.label || props.card.data?.label || 'Card')
const titleLong = computed(() => title.value.length > 34)

const act = (action) => {
    menuOpen.value = false
    emit(action)
}

// Filters as chips under the title: a field value (Station: WOG) opens the type's analytics.
const chips = computed(() => {
    const config = props.card.config ?? {}
    const out = []
    const fields = typesStore.byId(config.type_id)?.fields ?? []

    for (const [key, value] of Object.entries(config.filters ?? {})) {
        const field = fields.find((f) => f.key === key)
        out.push({
            key: `f-${key}`,
            text: `${field?.label ?? key}: ${value === true ? 'Yes' : value === false ? 'No' : value}`,
            class: 'badge-warning',
            click: config.type_id ? () => typesStore.openAnalytics(config.type_id) : null,
        })
    }

    for (const id of config.tag_ids ?? []) {
        const tag = tagsStore.tags.find((t) => t.id === id)
        if (tag) out.push({ key: `t-${id}`, text: `${tag.emoji ?? ''} ${tag.title}`.trim(), class: 'badge-info' })
    }

    if (config.flow === 'income' && config.metric === 'amount')
        out.push({ key: 'flow', text: 'Income', class: 'badge-success' })

    return out
})
</script>

<style scoped>
.dash-card {
    transition:
        opacity 0.2s,
        box-shadow 0.2s,
        transform 0.2s;
}
</style>
