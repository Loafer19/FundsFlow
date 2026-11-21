<template>
    <dialog ref="dialogRef" class="modal" aria-labelledby="card_editor_title" @close="store.closeEditor()">
        <div class="modal-box w-full h-dvh max-h-dvh sm:h-auto sm:max-h-[92dvh] sm:w-[94vw] max-w-4xl p-4 sm:p-5 rounded-none sm:rounded-box">
            <ModalHeader title-id="card_editor_title" :icon="LayoutGrid">
                {{ editing ? 'Edit card' : 'Add card' }}
            </ModalHeader>

            <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-5 min-w-0">
                <form class="flex flex-col gap-3 min-w-0" @submit.prevent="save">
                    <div v-if="!editing && ideas.length" class="flex flex-wrap gap-1.5">
                        <button v-for="idea in ideas" :key="idea.key" type="button"
                            class="btn btn-xs btn-soft tooltip tooltip-bottom" :data-tip="idea.hint" @click="applyIdea(idea)">
                            {{ idea.label }}
                        </button>
                    </div>

                    <div class="join w-full" role="radiogroup" aria-label="Card kind">
                        <button v-for="option in KINDS" :key="option.value" type="button" role="radio"
                            class="join-item btn btn-sm flex-1" :class="form.kind === option.value ? 'btn-primary' : 'btn-ghost border-base-300'"
                            :aria-checked="form.kind === option.value" @click="setKind(option.value)">
                            <component :is="option.icon" :size="14" /> {{ option.label }}
                        </button>
                    </div>

                    <label class="floating-label">
                        <span>Title</span>
                        <input v-model.trim="form.title" type="text" maxlength="60" class="input input-sm w-full"
                            :placeholder="autoTitle || 'Title (optional)'" />
                    </label>

                    <div class="grid grid-cols-2 gap-2">
                        <label class="floating-label">
                            <span>Type</span>
                            <select v-model="form.type_id" class="select select-sm w-full">
                                <option :value="null">Any type</option>
                                <option v-for="type in typesStore.active" :key="type.id" :value="type.id">
                                    {{ type.emoji }} {{ type.name }}
                                </option>
                            </select>
                        </label>
                        <label class="floating-label">
                            <span>Transactions</span>
                            <select v-model="form.flow" class="select select-sm w-full">
                                <option value="">{{ form.metric === 'amount' ? 'Expenses (default)' : 'All (default)' }}</option>
                                <option value="expense">Expenses</option>
                                <option value="income">Income</option>
                                <option value="all">All</option>
                            </select>
                        </label>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                        <PopoverMenu v-model="tagsOpen" label="Tags" panel-class="w-72 max-h-80 overflow-y-auto">
                            <template #trigger="{ toggle }">
                                <button type="button" class="btn btn-sm btn-ghost border-base-300" :aria-expanded="tagsOpen" @click="toggle">
                                    <Tag :size="14" /> Tags
                                </button>
                            </template>
                            <TagPicker v-model="form.tag_ids" />
                        </PopoverMenu>
                        <span v-for="tag in pickedTags" :key="tag.id" class="badge badge-info badge-soft gap-1">
                            {{ tag.emoji }} {{ tag.title }}
                            <button type="button" class="cursor-pointer" :aria-label="`Remove ${tag.title}`" @click="dropTag(tag.id)">
                                <X :size="12" />
                            </button>
                        </span>
                        <span v-if="!pickedTags.length" class="text-xs text-base-content/50">Any tag (children included)</span>
                    </div>

                    <div v-if="type" class="flex flex-col gap-2 min-w-0">
                        <div v-for="(row, index) in form.filters" :key="index" class="flex items-center gap-2 min-w-0">
                            <select v-model="row.key" class="select select-sm w-36 shrink-0" aria-label="Filter field" @change="row.value = ''">
                                <option v-for="field in filterFields" :key="field.key" :value="field.key">{{ field.label }}</option>
                            </select>
                            <span class="text-sm text-base-content/60">=</span>
                            <select v-if="optionsFor(row.key)" v-model="row.value" class="select select-sm flex-1 min-w-0" aria-label="Filter value">
                                <option value="" disabled>Pick…</option>
                                <option v-for="option in optionsFor(row.key)" :key="option" :value="option">{{ option }}</option>
                            </select>
                            <template v-else>
                                <input v-model.trim="row.value" type="text" maxlength="100" class="input input-sm flex-1 min-w-0"
                                    aria-label="Filter value" placeholder="Value" :list="`card-values-${row.key}`" />
                                <datalist :id="`card-values-${row.key}`">
                                    <option v-for="value in knownValues(row.key)" :key="value" :value="value" />
                                </datalist>
                            </template>
                            <button type="button" class="btn btn-ghost btn-sm btn-square" aria-label="Remove filter" @click="form.filters.splice(index, 1)">
                                <X :size="14" />
                            </button>
                        </div>
                        <button v-if="filterFields.length && form.filters.length < 5" type="button" class="btn btn-ghost btn-xs self-start"
                            @click="addFilter">
                            <Filter :size="12" /> Add {{ type.name }} filter
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <label class="floating-label">
                            <span>Value</span>
                            <select v-model="form.metric" class="select select-sm w-full">
                                <option v-for="option in metricOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </label>
                        <label class="floating-label">
                            <span>Calculate</span>
                            <select v-model="form.agg" class="select select-sm w-full">
                                <option value="">Auto</option>
                                <option v-for="option in AGGS" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </label>
                    </div>

                    <div v-if="form.kind !== 'stat'" class="grid grid-cols-2 gap-2">
                        <label class="floating-label">
                            <span>{{ form.kind === 'pie' ? 'Slices by' : 'Split by' }}</span>
                            <select v-model="form.split" class="select select-sm w-full">
                                <option v-if="form.kind === 'chart'" :value="null">No split</option>
                                <option value="tag">Tag</option>
                                <option v-for="field in splitFields" :key="field.key" :value="field.key">{{ field.label }}</option>
                            </select>
                        </label>
                        <div v-if="form.kind === 'chart'" class="join w-full self-end" role="radiogroup" aria-label="Chart style">
                            <button v-for="option in CHARTS" :key="option.value" type="button" role="radio"
                                class="join-item btn btn-sm flex-1" :class="chartStyle === option.value ? 'btn-primary' : 'btn-ghost border-base-300'"
                                :aria-checked="chartStyle === option.value" @click="form.chart = option.value">
                                <component :is="option.icon" :size="14" /> {{ option.label }}
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-sm text-base-content/70">Size</span>
                        <div class="join" role="radiogroup" aria-label="Card size">
                            <button v-for="size in ['S', 'M', 'L']" :key="size" type="button" role="radio"
                                class="join-item btn btn-sm w-11" :class="form.size === size ? 'btn-primary' : 'btn-ghost border-base-300'"
                                :aria-checked="form.size === size" @click="setSize(size)">
                                {{ size }}
                            </button>
                        </div>
                    </div>

                    <div class="modal-action mt-1">
                        <button type="submit" class="btn btn-primary tg-primary" :disabled="store.isSaving || Boolean(error) || !preview">
                            <span v-if="store.isSaving" class="loading loading-spinner"></span>
                            <Save v-else :size="18" />
                            {{ editing ? 'Save' : 'Add card' }}
                        </button>
                    </div>
                </form>

                <div class="flex flex-col gap-2 min-w-0 order-first md:order-none">
                    <div class="text-xs uppercase tracking-wide text-base-content/60 flex items-center gap-2">
                        Preview
                        <span v-if="previewLoading" class="loading loading-spinner loading-xs"></span>
                        <span v-if="preview?.data?.period" class="normal-case tracking-normal">
                            · {{ dmy(preview.data.period.from) }} – {{ dmy(preview.data.period.to) }}
                        </span>
                    </div>
                    <div class="rounded-box bg-base-200/60 p-3 min-w-0 grid grid-cols-2">
                        <div v-if="error" role="alert" class="alert alert-warning alert-soft text-sm col-span-2">{{ error }}</div>
                        <div v-else-if="preview" class="min-w-0 transition-opacity duration-200"
                            :class="[form.size === 'S' ? 'col-span-1' : 'col-span-2', { 'opacity-60': previewLoading }]">
                            <DashboardCard :card="previewCard" preview />
                        </div>
                        <div v-else class="skeleton h-32 col-span-2"></div>
                    </div>
                    <p class="text-xs text-base-content/50">The period follows the Analytics date range.</p>
                </div>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { ChartColumn, ChartLine, ChartPie, Filter, Hash, LayoutGrid, Save, Tag, X } from '@lucide/vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useDashboardCardsStore } from '../../services/dashboardCards.js'
import { apiErrorMessage } from '../../services/formatters.js'
import { useTagsStore } from '../../services/tags.js'
import { useTransactionsStore } from '../../services/transactions.js'
import { useTransactionTypesStore } from '../../services/transactionTypes.js'
import ModalHeader from '../ModalHeader.vue'
import PopoverMenu from '../PopoverMenu.vue'
import TagPicker from '../TagPicker.vue'
import DashboardCard from './DashboardCard.vue'
import { dmy } from './format.js'
import { cardSuggestions } from './suggestions.js'

const KINDS = [
    { value: 'stat', label: 'Stat', icon: Hash },
    { value: 'chart', label: 'Chart', icon: ChartLine },
    { value: 'pie', label: 'Pie', icon: ChartPie },
]
const CHARTS = [
    { value: 'line', label: 'Line', icon: ChartLine },
    { value: 'bar', label: 'Bar', icon: ChartColumn },
]
const AGGS = [
    { value: 'sum', label: 'Total' },
    { value: 'avg', label: 'Average' },
    { value: 'count', label: 'Count' },
    { value: 'min', label: 'Min' },
    { value: 'max', label: 'Max' },
]
const DEFAULT_SIZE = { stat: 'S', chart: 'M', pie: 'M' }
const NUMERIC = ['number', 'rating', 'duration', 'computed']
const SPLITTABLE = ['select', 'text', 'boolean']

const store = useDashboardCardsStore()
const typesStore = useTransactionTypesStore()
const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()

const dialogRef = ref(null)
const tagsOpen = ref(false)
const editing = store.editing

const blank = () => ({
    kind: 'stat',
    title: '',
    type_id: null,
    tag_ids: [],
    filters: [],
    metric: 'amount',
    agg: '',
    flow: '',
    split: null,
    chart: null,
    size: 'S',
})

const fromConfig = (kind, config = {}, size = null) => ({
    ...blank(),
    kind,
    title: config.title ?? '',
    type_id: config.type_id ?? null,
    tag_ids: [...(config.tag_ids ?? [])],
    filters: Object.entries(config.filters ?? {}).map(([key, value]) => ({
        key,
        value: value === true ? 'Yes' : value === false ? 'No' : String(value),
    })),
    metric: config.metric ?? 'amount',
    agg: config.agg && config.agg !== 'auto' ? config.agg : '',
    flow: config.flow ?? '',
    split: config.split ?? (kind === 'pie' ? 'tag' : null),
    chart: config.chart ?? null,
    size: size ?? DEFAULT_SIZE[kind],
})

const form = ref(
    editing
        ? fromConfig(editing.kind, editing.config, editing.size)
        : store.draft
          ? fromConfig(store.draft.kind, store.draft.config)
          : blank(),
)
let sizeTouched = Boolean(editing)
// Field-dependent resets (below) skip the first tick, so an edited card keeps its own agg / filters.
let ready = false

const type = computed(() => typesStore.byId(form.value.type_id))
const fields = computed(() => type.value?.fields ?? [])
const numericFields = computed(() => fields.value.filter((f) => NUMERIC.includes(f.type)))
const splitFields = computed(() => fields.value.filter((f) => SPLITTABLE.includes(f.type)))
const filterFields = computed(() => fields.value.filter((f) => f.type !== 'date' && f.type !== 'computed'))
const ideas = computed(() => cardSuggestions(typesStore.types))
const pickedTags = computed(() =>
    form.value.tag_ids.map((id) => tagsStore.tags.find((t) => t.id === id)).filter(Boolean),
)
const chartStyle = computed(() => form.value.chart ?? preview.value?.config?.chart ?? 'bar')

const metricOptions = computed(() => [
    { value: 'amount', label: 'Amount' },
    ...numericFields.value.map((f) => ({ value: f.key, label: f.label + (f.unit ? `, ${f.unit}` : '') })),
    ...(form.value.kind === 'stat' && numericFields.value.length
        ? [{ value: 'fields', label: 'All number fields' }]
        : []),
])

const optionLabel = (option) => (typeof option === 'string' ? option : (option?.label ?? option?.value ?? ''))
const optionsFor = (key) => {
    const field = fields.value.find((f) => f.key === key)
    if (!field) return null
    if (field.type === 'boolean') return ['Yes', 'No']
    if (field.type === 'select' || field.type === 'multiselect') return (field.options ?? []).map(optionLabel)
    return null
}

// Values already used in this type's transactions (WOG, OKKO…), offered while typing a text filter.
const knownValues = (key) => {
    const seen = new Map()
    for (const t of transactionsStore.transactions) {
        if (t.transaction_type_id !== form.value.type_id) continue
        const value = t.data?.[key]
        if (typeof value !== 'string' || !value.trim()) continue
        const text = value.trim()
        if (!seen.has(text.toLowerCase())) seen.set(text.toLowerCase(), text)
        if (seen.size >= 30) break
    }
    return [...seen.values()]
}

const addFilter = () => {
    const used = new Set(form.value.filters.map((r) => r.key))
    // Text / select fields first (Station = WOG is the usual filter), numbers last.
    const ordered = [...filterFields.value].sort((a, b) => SPLITTABLE.includes(b.type) - SPLITTABLE.includes(a.type))
    const field = ordered.find((f) => !used.has(f.key)) ?? ordered[0]
    if (field) form.value.filters.push({ key: field.key, value: '' })
}

const dropTag = (id) => {
    form.value.tag_ids = form.value.tag_ids.filter((t) => t !== id)
}

const setKind = (kind) => {
    form.value.kind = kind
    if (!sizeTouched) form.value.size = DEFAULT_SIZE[kind]
    if (kind === 'pie' && !form.value.split) form.value.split = 'tag'
    if (kind !== 'stat' && form.value.metric === 'fields') form.value.metric = 'amount'
}

const setSize = (size) => {
    sizeTouched = true
    form.value.size = size
}

const applyIdea = (idea) => {
    const size = sizeTouched ? form.value.size : null
    form.value = fromConfig(idea.kind, idea.config, size)
}

// Picking another type (not a whole new form from a suggestion) drops what does not fit it.
watch(
    () => [form.value, form.value.type_id],
    ([f, typeId], [before, typeBefore]) => {
        if (!ready || f !== before || typeId === typeBefore) return
        const keys = new Set(fields.value.map((f) => f.key))
        form.value.filters = form.value.filters.filter((r) => keys.has(r.key))
        if (!['amount', 'fields'].includes(form.value.metric) && !keys.has(form.value.metric))
            form.value.metric = 'amount'
        if (form.value.metric === 'fields' && !numericFields.value.length) form.value.metric = 'amount'
        if (form.value.split && form.value.split !== 'tag' && !keys.has(form.value.split)) {
            form.value.split = form.value.kind === 'pie' ? 'tag' : null
        }
    },
)
// A new value to measure starts from its own default calculation (prices → average).
watch(
    () => [form.value, form.value.metric],
    ([f, metric], [before, metricBefore]) => {
        if (ready && f === before && metric !== metricBefore) f.agg = ''
    },
)

const payload = computed(() => {
    const f = form.value
    const filters = {}
    for (const row of f.filters) {
        if (row.key && row.value !== '') filters[row.key] = row.value
    }
    const config = {
        title: f.title || null,
        type_id: f.type_id,
        tag_ids: f.tag_ids,
        filters,
        metric: f.metric,
        split: f.kind === 'stat' ? null : f.split,
        chart: f.kind === 'chart' ? f.chart : null,
    }
    if (f.agg) config.agg = f.agg
    if (f.flow) config.flow = f.flow

    return { kind: f.kind, size: f.size, config }
})

// Live preview: the server computes the card for the current Analytics range (nothing is saved).
const preview = ref(null)
const previewLoading = ref(false)
const error = ref('')
let timer = null
let controller = null

const refresh = async () => {
    controller?.abort()
    controller = new AbortController()
    previewLoading.value = true
    try {
        preview.value = await store.preview(payload.value, controller.signal)
        error.value = ''
    } catch (e) {
        if (e?.name === 'CanceledError' || e?.code === 'ERR_CANCELED') return
        error.value = apiErrorMessage(e)
    } finally {
        previewLoading.value = false
    }
}

watch(
    payload,
    () => {
        clearTimeout(timer)
        timer = setTimeout(refresh, 300)
    },
    { deep: true },
)

const autoTitle = computed(() => (form.value.title ? '' : (preview.value?.data?.label ?? '')))

const previewCard = computed(() => ({
    id: 0,
    kind: preview.value.kind,
    size: form.value.size,
    config: preview.value.config,
    label: preview.value.data?.label,
    data: preview.value.data,
}))

const save = async () => {
    const saved = editing ? await store.update(editing.id, payload.value) : await store.create(payload.value)
    if (saved) dialogRef.value?.close()
}

onMounted(() => {
    dialogRef.value?.showModal()
    refresh()
    setTimeout(() => (ready = true))
})

onBeforeUnmount(() => {
    clearTimeout(timer)
    controller?.abort()
})
</script>
