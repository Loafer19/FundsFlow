<template>
    <section class="flex flex-col gap-2 min-w-0" aria-labelledby="my-cards-title">
        <div class="flex items-center gap-2 min-w-0">
            <h2 id="my-cards-title" class="card-title text-base sm:text-lg min-w-0">
                <LayoutGrid :size="22" class="text-primary shrink-0" />
                My cards
                <span class="tooltip tooltip-bottom before:max-w-52 sm:before:max-w-64 font-normal text-sm"
                    data-tip="Your own cards. Numbers follow the date range above and compare with the previous period. Drag the handle to reorder.">
                    <Info :size="16" class="opacity-60" />
                </span>
            </h2>
            <button v-if="store.cards.length" type="button" class="btn btn-ghost btn-sm ml-auto"
                :disabled="atLimit" @click="store.openEditor()">
                <Plus :size="16" /> Add card
            </button>
        </div>

        <div v-if="!store.loaded && store.isLoading" class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="skeleton h-28"></div>
            <div class="skeleton h-28 col-span-1 lg:col-span-2"></div>
            <div class="skeleton h-28 hidden lg:block"></div>
        </div>

        <div v-else-if="!store.cards.length"
            class="rounded-box border border-dashed border-base-300 p-4 flex flex-col gap-3 min-w-0">
            <p class="text-sm text-base-content/70">
                Pin the numbers you check often: a stat, a chart over time or a pie, with your own filters.
            </p>
            <div class="flex flex-wrap gap-2">
                <button v-for="idea in suggestions" :key="idea.key" type="button"
                    class="btn btn-sm btn-soft btn-primary tooltip tooltip-bottom" :data-tip="idea.hint"
                    @click="store.openEditor(null, idea)">
                    <component :is="KIND_ICONS[idea.kind]" :size="14" /> {{ idea.label }}
                </button>
                <button type="button" class="btn btn-sm btn-ghost" @click="store.openEditor()">
                    <Plus :size="14" /> Custom card
                </button>
            </div>
        </div>

        <TransitionGroup v-else tag="div" move-class="dash-move"
            class="grid grid-cols-2 lg:grid-cols-4 gap-3 min-w-0"
            :class="{ 'select-none': dragId !== null }">
            <div v-for="card in shown" :key="card.id" :data-card-id="card.id" class="min-w-0" :class="span(card)">
                <DashboardCard :card="card" :dragging="dragId === card.id"
                    @drag-start="startDrag(card, $event)" @resize="store.setSize(card, $event)"
                    @edit="store.openEditor(card)" @delete="store.remove(card)" />
            </div>
        </TransitionGroup>

        <CardEditorModal v-if="store.editorOpen" />
    </section>
</template>

<script setup>
import { ChartLine, ChartPie, Hash, Info, LayoutGrid, Plus } from '@lucide/vue'
import { computed, defineAsyncComponent, onBeforeUnmount, ref, watch } from 'vue'
import { useDashboardCardsStore } from '../../services/dashboardCards.js'
import { useTransactionsStore } from '../../services/transactions.js'
import { useTransactionTypesStore } from '../../services/transactionTypes.js'
import DashboardCard from './DashboardCard.vue'
import { cardSuggestions } from './suggestions.js'

const CardEditorModal = defineAsyncComponent(() => import('./CardEditorModal.vue'))

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
})

const MAX_CARDS = 30
// S = 1 column, M = 2, L = full row (4 on desktop). On phones (2 columns) M and L are full width.
const SPAN = { S: 'col-span-1', M: 'col-span-2', L: 'col-span-2 lg:col-span-4' }
// A list of every number field does not fit half a phone screen: full width there, S from sm up.
const span = (card) =>
    card.size === 'S' && card.kind === 'stat' && card.config?.metric === 'fields'
        ? 'col-span-2 sm:col-span-1'
        : (SPAN[card.size] ?? SPAN.M)
const KIND_ICONS = { stat: Hash, chart: ChartLine, pie: ChartPie }

const store = useDashboardCardsStore()
const typesStore = useTransactionTypesStore()
const transactionsStore = useTransactionsStore()

const atLimit = computed(() => store.cards.length >= MAX_CARDS)
const suggestions = computed(() => cardSuggestions(typesStore.types))

watch(
    () => [props.from, props.to],
    ([from, to]) => store.load({ from, to }),
    { immediate: true },
)

// A transaction added, edited or deleted (here or by the assistant) changes the numbers: reload quietly.
let reloadTimer = null
watch(
    () => transactionsStore.transactions,
    () => {
        clearTimeout(reloadTimer)
        reloadTimer = setTimeout(() => store.load(), 400)
    },
    { deep: 1 },
)

// Reorder: a plain pointer drag on the card's handle. While dragging, the dragged card moves to the
// slot under the pointer (other cards slide in 0.2s); the new order is saved on release.
const dragId = ref(null)
const order = ref(null)
let startIds = []

const shown = computed(() => {
    if (!order.value) return store.cards
    const byId = new Map(store.cards.map((c) => [c.id, c]))
    return order.value.map((id) => byId.get(id)).filter(Boolean)
})

const onMove = (event) => {
    if (dragId.value === null) return
    event.preventDefault()
    const edge = 56
    if (event.clientY < edge) window.scrollBy(0, -14)
    else if (event.clientY > window.innerHeight - edge) window.scrollBy(0, 14)

    const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-card-id]')
    const overId = target ? Number(target.dataset.cardId) : null
    if (overId === null || overId === dragId.value) return

    const ids = order.value.slice()
    const from = ids.indexOf(dragId.value)
    const to = ids.indexOf(overId)
    if (from === -1 || to === -1) return
    ids.splice(from, 1)
    ids.splice(to, 0, dragId.value)
    order.value = ids
}

const stopListening = () => {
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', endDrag)
    window.removeEventListener('pointercancel', endDrag)
}

const endDrag = () => {
    stopListening()
    const ids = order.value
    dragId.value = null
    order.value = null
    if (ids && ids.join() !== startIds.join()) store.reorder(ids)
}

const startDrag = (card, event) => {
    if (event.button !== 0 || store.cards.length < 2) return
    event.preventDefault()
    startIds = store.cards.map((c) => c.id)
    order.value = startIds.slice()
    dragId.value = card.id
    window.addEventListener('pointermove', onMove, { passive: false })
    window.addEventListener('pointerup', endDrag)
    window.addEventListener('pointercancel', endDrag)
}

onBeforeUnmount(() => {
    stopListening()
    clearTimeout(reloadTimer)
})
</script>

<style scoped>
.dash-move {
    transition: transform 0.2s ease;
}
</style>
