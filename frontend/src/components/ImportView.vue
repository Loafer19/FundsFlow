<template>
    <div>
        <div class="mb-4 min-w-0">
            <div class="flex items-center gap-2 min-w-0">
                <h1 class="text-2xl font-semibold truncate min-w-0"
                    :title="pageTitle">{{ pageTitle }}</h1>
                <span v-if="batch" class="badge badge-soft shrink-0" :class="batchStatusBadge">{{ batchStatusLabel }}</span>
            </div>
        </div>

        <EmptyState v-if="!authStore.isAuthenticated" :icon="FileUp" title="Log in to import a statement"
            description="Upload a bank statement and preview every row before anything is saved."
            action-label="Log in" :action-icon="LogIn" @action="showModal('auth_modal')" />

        <div v-else-if="!uuid" class="flex flex-col gap-4">
            <div
                class="rounded-box border-2 border-dashed px-4 py-6 sm:py-12 text-center cursor-pointer transition-colors"
                :class="dragging ? 'border-primary bg-primary/5' : 'border-base-300 bg-base-100'"
                role="button"
                tabindex="0"
                :aria-disabled="uploading"
                aria-label="Drop a statement here or click to choose a file"
                @click="!uploading && fileInput?.click()"
                @keydown.enter.prevent="!uploading && fileInput?.click()"
                @keydown.space.prevent="!uploading && fileInput?.click()"
                @dragenter.prevent="dragging = true"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="onDragLeave"
                @drop.prevent="onDrop">
                <Upload class="mx-auto mb-3 text-base-content/50" :size="28" />
                <div class="font-medium">Drop a statement here</div>
                <p class="text-sm text-base-content/60 mt-1">or click to choose a file</p>
                <p class="text-xs text-base-content/50 mt-2 max-w-md mx-auto">
                    You'll preview every row before anything is saved
                </p>
                <input ref="fileInput" type="file" class="hidden" :accept="IMPORT_FILE_ACCEPT" @change="onFile" @click.stop />
                <p v-if="uploadError" class="text-error text-sm mt-3">{{ uploadError }}</p>
                <p v-if="uploading" class="text-sm flex items-center justify-center gap-2 mt-3">
                    <span class="loading loading-spinner loading-sm"></span>
                    Reading the file…
                </p>
            </div>

            <div class="card card-border border-base-300 bg-base-100">
                <div class="card-body gap-2 py-4">
                    <h2 class="font-medium text-sm">Supported formats</h2>
                    <ul class="flex flex-wrap gap-x-5 gap-y-3">
                        <li v-for="format in IMPORT_FORMATS" :key="format.label" class="min-w-[8.5rem]">
                            <div class="text-sm text-base-content/80">{{ format.label }}</div>
                            <div class="font-mono text-xs text-base-content/50">{{ format.extensions }}</div>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="card card-border border-base-300 bg-base-100">
                <div class="card-body gap-3 py-4">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="font-medium text-sm">Previous imports</h2>
                        <span v-if="historyLoading" class="loading loading-spinner loading-xs"></span>
                    </div>
                    <p v-if="historyError" class="text-error text-sm">{{ historyError }}</p>
                    <p v-else-if="!historyLoading && !historyItems.length" class="text-sm text-base-content/60">
                        No previous imports yet
                    </p>
                    <ul v-else class="flex flex-col gap-2">
                        <li v-for="item in historyItems" :key="item.id"
                            class="rounded-box border border-base-300 p-3 flex flex-col sm:flex-row sm:items-center gap-2 min-w-0">
                            <div class="min-w-0 flex-1">
                                <div class="font-medium truncate" :title="item.filename">{{ item.filename || 'Untitled file' }}</div>
                                <div class="text-sm text-base-content/60">
                                    {{ historyDate(item) }}
                                    <span aria-hidden="true"> · </span>
                                    {{ historyCounts(item) }}
                                </div>
                            </div>
                            <span class="badge badge-soft w-fit shrink-0" :class="historyStatusBadge(item.status)">
                                {{ historyStatusLabel(item.status) }}
                            </span>
                            <div class="flex flex-wrap gap-1 sm:ml-auto">
                                <button type="button" class="btn btn-outline btn-xs" @click="openBatch(item.id)">
                                    Open
                                </button>
                                <button v-if="item.can_undo" type="button" class="btn btn-outline btn-error btn-xs"
                                    :disabled="undoingId === item.id"
                                    @click="undoBatch(item)">
                                    <span v-if="undoingId === item.id" class="loading loading-spinner loading-xs"></span>
                                    Undo
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div v-else-if="loading" class="flex justify-center py-16">
            <span class="loading loading-spinner loading-lg"></span>
        </div>

        <div v-else-if="loadError" class="alert alert-error">{{ loadError }}</div>

        <template v-else-if="batch">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <StatCard v-for="card in summaryCards" :key="card.key" :label="card.label" :icon="card.icon"
                    :icon-component="card.iconComponent" :icon-class="card.iconClass" :current="card.current"
                    :value="card.value" :value-class="card.valueClass" :note="card.note" :format="card.format || 'money'"
                    :compare="false" />
            </div>

            <div v-if="batch.categories?.length" class="card card-border border-base-300 bg-base-100 mb-4">
                <div class="card-body p-4 gap-3">
                    <div class="flex items-start gap-2">
                        <span class="shrink-0 rounded-lg p-1.5 bg-info/15 text-info mt-0.5">
                            <Tags :size="16" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="font-semibold">Bank categories</h2>
                            <p class="text-sm text-base-content/60">
                                How the file’s own categories should be tagged. A shop rule still wins when one matches.
                            </p>
                        </div>
                    </div>
                    <div class="divide-y divide-base-300">
                        <div v-for="cat in batch.categories" :key="cat.name"
                            class="flex flex-wrap items-center gap-2 py-2">
                            <span class="font-medium min-w-28 truncate" :title="cat.name">{{ cat.name }}</span>
                            <span class="text-xs text-base-content/50 tabular-nums">{{ cat.count }}</span>
                            <SegmentedControl size="sm" label="Category action" :model-value="cat.action"
                                :options="categoryActionOptions" :class="{ 'pointer-events-none opacity-50': busy || !editable }"
                                @update:model-value="editable && !busy && onCategoryAction(cat, $event)" />
                            <template v-if="cat.action === 'map'">
                                <button type="button"
                                    class="badge badge-soft badge-info px-1 gap-1 max-w-full"
                                    :class="editable ? 'cursor-pointer hover:bg-info/25' : 'pointer-events-none'"
                                    :disabled="!editable"
                                    @click="editable && openCategoryPicker(cat, $event)">
                                    <template v-if="cat.tag">
                                        <span>{{ cat.tag.emoji }}</span>
                                        <span>{{ cat.tag.title }}</span>
                                    </template>
                                    <span v-else>Choose tag</span>
                                    <Pencil v-if="editable" :size="12" class="opacity-60" />
                                </button>
                            </template>
                            <input v-else-if="cat.action === 'create'" class="input input-sm input-bordered w-40"
                                :value="cat.title || cat.name" :disabled="busy || !editable" placeholder="New tag name"
                                @change="onCategoryRename(cat, $event.target.value)" />
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="batch.by_tag?.length" class="flex flex-wrap gap-2 mb-4">
                <span v-for="item in batch.by_tag.slice(0, 12)" :key="item.tag_id ?? 'none'"
                    class="badge badge-soft px-1 gap-1"
                    :class="item.tag_id ? 'badge-info' : ''">
                    <span v-if="item.emoji">{{ item.emoji }}</span>
                    <span>{{ item.title }}</span>
                    <span class="tabular-nums opacity-70">{{ item.count }}</span>
                </span>
            </div>

            <div v-if="batch.notes?.length" class="alert alert-soft text-sm mb-4">
                <ul class="list-disc pl-4">
                    <li v-for="(note, i) in batch.notes" :key="i">{{ note }}</li>
                </ul>
            </div>

            <div v-if="batch.status === 'committed'" class="alert alert-success mb-4">
                <span>Imported. You can undo the whole batch.</span>
                <button type="button" class="btn btn-sm btn-ghost" :disabled="busy" @click="onUndo">Undo import</button>
            </div>
            <div v-else-if="batch.status === 'undone'" class="alert mb-4">This import was undone</div>
            <div v-else-if="batch.status === 'committing'" class="alert mb-4">
                <span class="loading loading-spinner loading-sm"></span>
                Importing… {{ batch.progress }}%
            </div>
            <div v-else-if="batch.status === 'failed'" class="alert alert-error mb-4">{{ batch.error_message }}</div>

            <div v-if="batch.isMutable !== false && ['previewing', 'failed'].includes(batch.status)"
                class="card card-border border-base-300 bg-base-100 mb-4">
                <div class="card-body p-4 gap-3">
                    <div class="flex items-start gap-2">
                        <span class="shrink-0 rounded-lg p-1.5 bg-primary/15 text-primary mt-0.5">
                            <ListFilter :size="16" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="font-semibold">Bulk rules</h2>
                            <p class="text-sm text-base-content/60">
                                Tag a shop in one go, or skip old rows and transfers before you import
                            </p>
                        </div>
                    </div>
                    <div class="divide-y divide-base-300">
                        <div class="flex flex-wrap items-center gap-2 py-2">
                            <span class="font-medium min-w-28">Tag merchants</span>
                            <input v-model="ruleMerchant" class="input input-sm input-bordered w-40"
                                placeholder="Glovo" aria-label="Merchant contains" />
                            <button type="button"
                                class="badge badge-soft px-1 gap-1 max-w-full"
                                :class="ruleTag ? 'badge-info' : 'badge-neutral'"
                                :style="editable ? 'cursor: pointer' : ''"
                                :disabled="!editable" aria-label="Choose merchant tag"
                                @click="editable && openRulePicker($event)">
                                <template v-if="ruleTag">
                                    <span>{{ ruleTag.emoji }}</span>
                                    <span>{{ ruleTag.title }}</span>
                                </template>
                                <span v-else>Choose tag</span>
                                <Pencil v-if="editable" :size="12" class="opacity-60" />
                            </button>
                            <button type="button" class="btn btn-sm btn-primary"
                                :disabled="busy || !canApplyMerchant" @click="applyMerchant">
                                Apply
                            </button>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 py-2">
                            <span class="font-medium min-w-28">Skip</span>
                            <span class="text-xs text-base-content/50">before</span>
                            <DateField v-model="ruleBefore" sm clearable placeholder="Pick a date"
                                aria-label="Skip rows before" input-class="w-auto min-w-40" />
                            <span class="text-xs text-base-content/50">name contains</span>
                            <input v-model="ruleSkipName" class="input input-sm input-bordered w-40"
                                placeholder="Olya" aria-label="Skip name contains" />
                            <button type="button" class="btn btn-sm btn-soft"
                                :disabled="busy || !canSkip" @click="applySkip">
                                Skip
                            </button>
                        </div>
                        <div class="flex flex-wrap items-center gap-3 py-2">
                            <span class="font-medium min-w-28">Currency</span>
                            <label class="label cursor-pointer gap-3 py-0 justify-start">
                                <input type="checkbox" class="toggle toggle-primary toggle-sm"
                                    :checked="!!batch.options?.include_foreign"
                                    :disabled="busy || !editable" @change="toggleForeign" />
                                <span>
                                    <span class="label-text">Include non-UAH rows</span>
                                    <span class="block text-xs text-base-content/50 font-normal">Also import amounts in other currencies</span>
                                </span>
                            </label>
                        </div>
                    </div>
                    <p class="text-xs text-base-content/60 flex items-start gap-1.5">
                        <Sparkles :size="14" class="shrink-0 mt-px opacity-70" />
                        <span>Or tell the assistant “Glovo is Food, skip transfers to Olya”</span>
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 mb-3 items-center">
                <div class="join flex-wrap">
                    <button v-for="filter in filters" :key="filter.id" type="button" class="join-item btn btn-sm"
                        :class="statusFilter === filter.id ? 'btn-primary' : 'btn-ghost'" @click="setFilter(filter.id)">
                        {{ filter.label }}
                        <span class="badge badge-xs">{{ filter.count }}</span>
                    </button>
                </div>
                <label class="input input-sm min-w-0 flex-1 max-w-xs border-base-300 text-sm ml-auto">
                    <Search :size="16" class="text-base-content/60" />
                    <input v-model="search" type="search" placeholder="Search notes" aria-label="Search notes"
                        @change="reload()" />
                </label>
            </div>

            <div class="card card-border border-base-300 bg-base-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th class="whitespace-nowrap">Date</th>
                                <th>Amount</th>
                                <th>Note</th>
                                <th>Tags</th>
                                <th>Status</th>
                                <th class="text-center w-0">
                                    <label class="flex flex-col items-center gap-1 cursor-pointer">
                                        <input type="checkbox" class="checkbox checkbox-sm"
                                            :checked="allVisibleIncluded"
                                            :indeterminate.prop="someVisibleIncluded && !allVisibleIncluded"
                                            :disabled="!editable || !visibleToggleable.length"
                                            :aria-label="allVisibleIncluded ? 'Exclude all rows on this page' : 'Include all rows on this page'"
                                            @change="toggleAllVisible($event.target.checked)" />
                                        <span class="text-[10px] font-normal normal-case tracking-normal text-base-content/50">Include</span>
                                    </label>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in batch.rows" :key="row.id" :class="rowClass(row)">
                                <td class="whitespace-nowrap">{{ formatRowDate(row.at) }}</td>
                                <td class="whitespace-nowrap">
                                    <div v-if="row.amount == null" class="text-base-content/50">—</div>
                                    <div v-else class="badge badge-outline font-semibold"
                                        :class="row.amount > 0 ? 'badge-success' : 'badge-error'">
                                        {{ row.amount > 0 ? '+' : '' }}{{ formatMoney(row.amount) }}
                                    </div>
                                </td>
                                <td class="max-w-xs" :title="row.note || undefined">
                                    <span class="line-clamp-2 whitespace-normal break-words">{{ row.note || '—' }}</span>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-2 items-center">
                                        <button type="button"
                                            class="flex flex-wrap gap-2 items-center text-left min-w-0"
                                            :class="editable ? 'cursor-pointer' : 'cursor-default'"
                                            :disabled="!editable"
                                            :aria-label="editable ? 'Edit tags' : 'Tags'"
                                            @click="editable && openRowPicker(row, $event)">
                                            <span v-for="tag in row.tags" :key="tag.id"
                                                class="badge badge-soft badge-info px-1 gap-1">
                                                <span>{{ tag.emoji }}</span>
                                                <span>{{ tag.title }}</span>
                                            </span>
                                            <span v-for="title in row.new_tag_titles" :key="title"
                                                class="badge badge-soft badge-warning px-1 gap-1">
                                                New: {{ title }}
                                            </span>
                                            <span v-if="!row.tags?.length && !row.new_tag_titles?.length"
                                                class="text-xs text-base-content/40">No tags</span>
                                            <Pencil v-if="editable" :size="14" class="opacity-40 shrink-0" />
                                        </button>
                                        <span v-if="row.manual" class="tooltip tooltip-top" data-tip="Changed by you">
                                            <span class="block w-1.5 h-1.5 rounded-full bg-info" aria-label="Changed by you"></span>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-sm tooltip tooltip-left whitespace-nowrap"
                                        :class="statusBadge(row.status)" :data-tip="statusTip(row)">
                                        {{ statusLabel(row.status) }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="checkbox checkbox-sm"
                                        :checked="isIncluded(row)"
                                        :disabled="!editable || row.status === 'error'"
                                        :aria-label="`Include line ${row.line}`"
                                        @change="toggleInclude(row, $event.target.checked)" />
                                </td>
                            </tr>
                            <tr v-if="!batch.rows.length">
                                <td colspan="6" class="text-center text-base-content/60">No rows in this filter</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex items-center justify-between mt-3 mb-20">
                <div class="join" v-if="batch.meta.last_page > 1">
                    <button type="button" class="join-item btn btn-sm" :disabled="page <= 1" @click="page--; reload()">Prev</button>
                    <button type="button" class="join-item btn btn-sm btn-ghost">{{ page }} / {{ batch.meta.last_page }}</button>
                    <button type="button" class="join-item btn btn-sm" :disabled="page >= batch.meta.last_page" @click="page++; reload()">Next</button>
                </div>
            </div>

            <div v-if="['previewing', 'failed'].includes(batch.status)"
                class="sticky bottom-0 z-20 -mx-4 px-4 py-3 mt-4 bg-base-100/95 backdrop-blur border-t border-base-300 flex items-center justify-end gap-2">
                <button type="button" class="btn btn-ghost" :disabled="busy" @click="goHome">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="busy || !importableCount" @click="onCommit">
                    <span v-if="busy" class="loading loading-spinner loading-sm"></span>
                    Import {{ importableCount }} transaction{{ importableCount === 1 ? '' : 's' }}
                </button>
            </div>
        </template>

        <Teleport to="body">
            <div v-if="tagMenu" class="fixed inset-0 z-50" @mousedown.self="closeTagMenu">
                <div class="fixed bg-base-100 rounded-box w-64 p-3 border border-base-300 shadow-lg"
                    role="dialog" aria-label="Choose tags"
                    :style="{ top: tagMenu.top + 'px', left: tagMenu.left + 'px' }"
                    @mousedown.stop>
                    <TagPicker v-model="tagMenu.ids" searchable />
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import {
    ArrowLeftRight,
    Ban,
    CalendarDays,
    Copy,
    FileUp,
    List,
    ListFilter,
    LogIn,
    Pencil,
    Search,
    Sparkles,
    Tags,
    TrendingDown,
    TrendingUp,
    Upload,
} from 'lucide-vue-next'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useAuthStore } from '../services/auth.js'
import { apiErrorMessage, formatDate, formatMoney, parseLocalDate } from '../services/formatters.js'
import { applyImportRules, commitImport, fetchImport, fetchImports, goToImport, IMPORT_FILE_ACCEPT, IMPORT_FORMATS, startWebImport, undoImport, updateImportRow } from '../services/imports.js'
import { showModal } from '../services/modal.js'
import { useTagsStore } from '../services/tags.js'
import toasts from '../services/toasts.js'
import EmptyState from './EmptyState.vue'
import TagPicker from './TagPicker.vue'
import StatCard from './StatCard.vue'
import SegmentedControl from './SegmentedControl.vue'
import DateField from './DateField.vue'

const props = defineProps({
    uuid: { type: String, default: '' },
})

const authStore = useAuthStore()
const tagsStore = useTagsStore()
const categoryActionOptions = [
    { value: 'map', label: 'Map' },
    { value: 'create', label: 'Create' },
    { value: 'ignore', label: 'Ignore' },
]

const batch = ref(null)
const loading = ref(false)
const loadError = ref('')
const uploadError = ref('')
const uploading = ref(false)
const dragging = ref(false)
const historyItems = ref([])
const historyLoading = ref(false)
const historyError = ref('')
const undoingId = ref('')
const busy = ref(false)
const statusFilter = ref('all')
const search = ref('')
const page = ref(1)
const ruleMerchant = ref('')
const ruleTagId = ref(null)
const ruleBefore = ref('')
const ruleSkipName = ref('')
const fileInput = ref(null)
const tagMenu = ref(null)
const savedMenuKey = ref('')
let pollTimer = null
let saveSeq = 0

const periodLabel = computed(() => {
    const s = batch.value?.summary
    if (!s?.period_from) return batch.value?.filename || ''
    return s.period_from === s.period_to ? formatRowDate(s.period_from) : `${formatRowDate(s.period_from)} – ${formatRowDate(s.period_to)}`
})
const importableCount = computed(() => {
    const s = batch.value?.summary
    return s ? s.new + s.needs_tag : 0
})
const editable = computed(() => ['previewing', 'failed'].includes(batch.value?.status))
const pageTitle = computed(() => props.uuid ? (batch.value?.filename || 'Import preview') : 'Import')
const batchStatusLabel = computed(() => {
    switch (batch.value?.status) {
        case 'committing':
            return 'Importing…'
        case 'committed':
            return 'Imported'
        case 'undone':
            return 'Undone'
        case 'failed':
            return 'Failed'
        default:
            return 'Draft'
    }
})
const batchStatusBadge = computed(() => ({
    'Importing…': 'badge-info',
    Imported: 'badge-success',
    Undone: '',
    Failed: 'badge-error',
    Draft: 'badge-warning',
}[batchStatusLabel.value] || ''))
const summaryCards = computed(() => {
    const s = batch.value?.summary || {}
    const needs = s.needs_tag ? `${s.needs_tag} need a tag` : ''
    return [
        { key: 'rows', label: 'Rows', current: s.total_rows ?? 0, format: 'count', iconComponent: List, iconClass: 'bg-base-content/10 text-base-content/70' },
        { key: 'new', label: 'New', current: s.new ?? 0, format: 'count', note: needs, iconComponent: Sparkles, iconClass: 'bg-success/15 text-success', valueClass: 'text-success' },
        { key: 'income', label: 'Income', current: s.income_total || 0, icon: 'income', valueClass: 'text-success' },
        { key: 'expenses', label: 'Expenses', current: Math.abs(s.expense_total || 0), icon: 'expenses', valueClass: 'text-error' },
        { key: 'duplicate', label: 'Duplicates', current: s.duplicate ?? 0, format: 'count', iconComponent: Copy, iconClass: 'bg-base-content/10 text-base-content/70' },
        { key: 'transfer', label: 'Transfers', current: s.transfer ?? 0, format: 'count', iconComponent: ArrowLeftRight, iconClass: 'bg-info/15 text-info' },
        { key: 'skipped', label: 'Skipped', current: s.skipped ?? 0, format: 'count', iconComponent: Ban, iconClass: 'bg-base-content/10 text-base-content/70' },
        { key: 'period', label: 'Period', value: periodLabel.value || '—', icon: 'daily' },
    ]
})
const filters = computed(() => {
    const s = batch.value?.summary || {}
    return [
        { id: 'all', label: 'All', count: s.total_rows || 0 },
        { id: 'new', label: 'New', count: s.new || 0 },
        { id: 'duplicate', label: 'Duplicates', count: s.duplicate || 0 },
        { id: 'transfer', label: 'Transfers', count: s.transfer || 0 },
        { id: 'skipped', label: 'Skipped', count: s.skipped || 0 },
        { id: 'needs_tag', label: 'Needs tag', count: s.needs_tag || 0 },
        { id: 'error', label: 'Errors', count: s.error || 0 },
    ]
})
const ruleTag = computed(() => (ruleTagId.value == null ? null : tagsStore.tags.find((tag) => Number(tag.id) === Number(ruleTagId.value)) || null))
const canApplyMerchant = computed(() => Boolean(ruleMerchant.value.trim() && ruleTag.value))
const canSkip = computed(() => Boolean(ruleBefore.value || ruleSkipName.value.trim()))
const isIncluded = (row) => ['new', 'needs_tag'].includes(row.status)
const visibleToggleable = computed(() => (batch.value?.rows || []).filter((row) => row.status !== 'error'))
const allVisibleIncluded = computed(() => visibleToggleable.value.length > 0 && visibleToggleable.value.every(isIncluded))
const someVisibleIncluded = computed(() => visibleToggleable.value.some(isIncluded))

const goHome = () => {
    window.history.pushState({}, '', '/')
    window.location.assign('/')
}

const formatRowDate = (value) => {
    if (!value) return '—'
    try {
        return formatDate(parseLocalDate(value))
    } catch {
        return value
    }
}

const params = () => ({
    status: statusFilter.value,
    q: search.value || undefined,
    page: page.value,
    per_page: 50,
})

const reload = async () => {
    if (!props.uuid) return
    loading.value = !batch.value
    loadError.value = ''
    try {
        batch.value = await fetchImport(props.uuid, params())
        if (batch.value.status === 'committing') startPoll()
        else stopPoll()
    } catch (error) {
        loadError.value = apiErrorMessage(error, 'Could not load this import: ')
    } finally {
        loading.value = false
    }
}

const startPoll = () => {
    stopPoll()
    pollTimer = setInterval(reload, 2000)
}
const stopPoll = () => {
    if (pollTimer) clearInterval(pollTimer)
    pollTimer = null
}

const setFilter = (id) => {
    statusFilter.value = id
    page.value = 1
    reload()
}

const startSelectedFile = async (file) => {
    if (!file || uploading.value) return
    uploading.value = true
    uploadError.value = ''
    dragging.value = false
    try {
        await startWebImport(file)
    } catch (error) {
        uploadError.value = apiErrorMessage(error, 'Could not read this file: ')
    } finally {
        uploading.value = false
        if (fileInput.value) fileInput.value.value = ''
    }
}

const onFile = (event) => {
    const file = event.target.files?.[0]
    event.target.value = ''
    startSelectedFile(file)
}

const onDragLeave = (event) => {
    if (event.currentTarget.contains(event.relatedTarget)) return
    dragging.value = false
}

const onDrop = (event) => {
    dragging.value = false
    startSelectedFile(event.dataTransfer?.files?.[0])
}

const loadHistory = async () => {
    if (props.uuid || !authStore.isAuthenticated) return
    historyLoading.value = !historyItems.value.length
    historyError.value = ''
    try {
        historyItems.value = await fetchImports()
    } catch (error) {
        historyError.value = apiErrorMessage(error, 'Could not load previous imports: ')
    } finally {
        historyLoading.value = false
    }
}

const historyStatusLabel = (status) => ({
    committing: 'Importing…',
    committed: 'Imported',
    undone: 'Undone',
    failed: 'Failed',
    previewing: 'Draft',
}[status] || 'Draft')

const historyStatusBadge = (status) => ({
    committing: 'badge-info',
    committed: 'badge-success',
    undone: 'badge-ghost',
    failed: 'badge-error',
    previewing: 'badge-warning',
}[status] || 'badge-ghost')

const historyDate = (item) => {
    const raw = item.committed_at || item.created_at
    if (!raw) return '—'
    try {
        return formatDate(new Date(raw))
    } catch {
        return raw
    }
}

const historyCounts = (item) => {
    const rows = Number(item.total_rows) || 0
    const imported = Number(item.imported_count) || 0
    if (item.status === 'committed' || item.status === 'undone') {
        return `${rows} row${rows === 1 ? '' : 's'} · ${imported} imported`
    }
    if (imported) {
        return `${rows} row${rows === 1 ? '' : 's'} · ${imported} to import`
    }
    return `${rows} row${rows === 1 ? '' : 's'}`
}

const openBatch = (id) => goToImport(id)

const undoBatch = async (item) => {
    if (!item?.can_undo || undoingId.value) return
    if (!confirm('Delete every transaction created by this import?')) return
    undoingId.value = item.id
    try {
        await undoImport(item.id)
        toasts.success('Import undone!')
        await loadHistory()
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not undo this import: '))
    } finally {
        undoingId.value = ''
    }
}

const applyMerchant = async () => {
    if (!canApplyMerchant.value) return
    await runRules({ merchant_map: { [ruleMerchant.value.trim()]: ruleTag.value.title } })
    ruleMerchant.value = ''
    ruleTagId.value = null
}
const onCategoryAction = async (cat, action) => {
    if (action === 'map') {
        if (cat.tag_id) {
            await runRules({ category: cat.name, category_action: 'map', tag_id: cat.tag_id })
        } else {
            openCategoryPicker(cat, { currentTarget: null })
        }
        return
    }
    const payload = { category: cat.name, category_action: action }
    if (action === 'create') payload.title = cat.title || cat.name
    await runRules(payload)
}
const onCategoryRename = async (cat, title) => {
    const name = (title || '').trim()
    if (!name) return
    await runRules({ category: cat.name, category_action: 'create', title: name })
}
const applySkip = async () => {
    if (!canSkip.value) return
    const rules = {}
    if (ruleBefore.value) rules.skip_before = ruleBefore.value
    if (ruleSkipName.value.trim()) rules.skip_names = [ruleSkipName.value.trim()]
    await runRules(rules)
    ruleBefore.value = ''
    ruleSkipName.value = ''
}
const toggleForeign = async (event) => {
    await runRules({ include_foreign: event.target.checked })
}
const runRules = async (rules) => {
    busy.value = true
    try {
        batch.value = await applyImportRules(props.uuid, rules)
        toasts.success('Preview updated!')
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not apply the rule: '))
    } finally {
        busy.value = false
    }
}

const placeMenu = (event) => {
    const el = event?.currentTarget
    const rect = el?.getBoundingClientRect?.()
    const width = 256
    const estimated = 340
    const left = rect
        ? Math.min(Math.max(8, rect.left), Math.max(8, window.innerWidth - width - 8))
        : Math.max(8, (window.innerWidth - width) / 2)
    let top = rect ? rect.bottom + 8 : 80
    if (top + estimated > window.innerHeight - 8) {
        top = Math.max(8, (rect ? rect.top : estimated + 16) - estimated - 8)
    }
    return { top, left }
}

const menuKey = (menu) => {
    if (!menu) return ''
    const ids = (menu.ids || []).map(Number).join(',')
    if (menu.kind === 'row') return `row:${menu.rowId}:${ids}`
    if (menu.kind === 'category') return `cat:${menu.name}:${ids}`
    return `rule:${ids}`
}

const resolveTags = (ids) =>
    ids.map((id) => tagsStore.tags.find((tag) => Number(tag.id) === Number(id))).filter(Boolean)

const openRowPicker = (row, event) => {
    const ids = [...(row.tag_ids || [])].map(Number)
    const menu = { kind: 'row', rowId: row.id, ids, ...placeMenu(event) }
    savedMenuKey.value = menuKey(menu)
    tagMenu.value = menu
}

const openCategoryPicker = (cat, event) => {
    const ids = cat.tag_id ? [Number(cat.tag_id)] : []
    const menu = { kind: 'category', name: cat.name, tagId: cat.tag_id ? Number(cat.tag_id) : null, ids, ...placeMenu(event) }
    savedMenuKey.value = menuKey(menu)
    tagMenu.value = menu
}

const openRulePicker = (event) => {
    const ids = ruleTagId.value ? [Number(ruleTagId.value)] : []
    const menu = { kind: 'rule', ids, ...placeMenu(event) }
    savedMenuKey.value = menuKey(menu)
    tagMenu.value = menu
}

const closeTagMenu = () => {
    tagMenu.value = null
}

const applyMenuTags = async (menu, ids) => {
    const key = menuKey({ ...menu, ids })
    if (key === savedMenuKey.value) return
    savedMenuKey.value = key

    if (menu.kind === 'category') {
        const next = ids.find((id) => id !== menu.tagId) ?? ids[ids.length - 1]
        if (!next) return
        menu.tagId = Number(next)
        menu.ids = [Number(next)]
        savedMenuKey.value = menuKey(menu)
        await runRules({ category: menu.name, category_action: 'map', tag_id: Number(next) })
        return
    }

    if (menu.kind === 'rule') {
        const current = ruleTagId.value ? Number(ruleTagId.value) : null
        const next = ids.find((id) => id !== current) ?? ids[ids.length - 1]
        ruleTagId.value = next ? Number(next) : null
        menu.ids = next ? [Number(next)] : []
        savedMenuKey.value = menuKey(menu)
        if (next) closeTagMenu()
        return
    }

    const row = batch.value?.rows?.find((item) => item.id === menu.rowId)
    if (row) {
        row.tag_ids = ids
        row.tags = resolveTags(ids)
        row.new_tag_titles = []
        row.manual = true
        if (row.status === 'needs_tag' && ids.length) row.status = 'new'
    }

    const seq = ++saveSeq
    try {
        const updated = await updateImportRow(props.uuid, menu.rowId, { tag_ids: ids })
        if (seq !== saveSeq) return
        if (updated?.summary && batch.value) {
            batch.value.summary = updated.summary
            if (updated.by_tag) batch.value.by_tag = updated.by_tag
            const fresh = updated.rows?.find((item) => item.id === menu.rowId)
            if (fresh && row) Object.assign(row, fresh)
        }
    } catch (error) {
        if (seq !== saveSeq) return
        toasts.error(apiErrorMessage(error, 'Could not update tags: '))
        await reload()
    }
}

watch(
    () => tagMenu.value?.ids,
    (ids) => {
        if (!tagMenu.value || !ids) return
        applyMenuTags(tagMenu.value, [...ids].map(Number))
    },
    { deep: true },
)

const toggleInclude = async (row, include) => {
    busy.value = true
    try {
        batch.value = await updateImportRow(props.uuid, row.id, { status: include ? 'new' : 'skipped' })
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not update the row: '))
    } finally {
        busy.value = false
    }
}

const toggleAllVisible = async (include) => {
    const ids = visibleToggleable.value.map((row) => row.id)
    if (!ids.length) return
    await runRules(include ? { include_ids: ids } : { skip_ids: ids })
}

const onCommit = async () => {
    busy.value = true
    try {
        batch.value = await commitImport(props.uuid)
        toasts.success('Imported!')
        if (batch.value.status === 'committing') startPoll()
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not import: '))
    } finally {
        busy.value = false
    }
}

const onUndo = async () => {
    if (!confirm('Delete every transaction created by this import?')) return
    busy.value = true
    try {
        batch.value = await undoImport(props.uuid)
        toasts.success('Import undone!')
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not undo: '))
    } finally {
        busy.value = false
    }
}

const rowClass = (row) => ({
    'opacity-60': ['duplicate', 'skipped', 'transfer'].includes(row.status),
    'bg-warning/10': row.status === 'needs_tag',
    'bg-error/10': row.status === 'error',
})
const statusLabel = (status) => ({
    new: 'New',
    duplicate: 'Duplicate',
    transfer: 'Transfer',
    skipped: 'Skipped',
    needs_tag: 'Needs tag',
    error: 'Error',
}[status] || status)
const statusBadge = (status) => ({
    new: 'badge-success',
    duplicate: 'badge-ghost',
    transfer: 'badge-info',
    skipped: 'badge-ghost',
    needs_tag: 'badge-warning',
    error: 'badge-error',
}[status] || 'badge-ghost')
const statusTip = (row) => {
    if (row.status === 'duplicate') {
        return row.at ? `Matches a saved transaction on ${formatRowDate(row.at)}` : (row.skip_reason || 'Already saved')
    }
    if (row.status === 'transfer') return row.skip_reason || 'Transfer to own card'
    if (row.status === 'skipped') return row.skip_reason || 'Skipped by you'
    if (row.status === 'needs_tag') return 'Needs a tag before import'
    if (row.status === 'error') return row.error || 'Could not read this row'
    if (row.status === 'new') return row.skip_reason || 'Will be imported'
    return row.skip_reason || row.error || ''
}

watch(() => props.uuid, () => {
    batch.value = null
    page.value = 1
    closeTagMenu()
    if (props.uuid) reload()
    else loadHistory()
})

onMounted(() => {
    if (props.uuid) reload()
    else loadHistory()
})
onUnmounted(() => {
    stopPoll()
    closeTagMenu()
})
</script>
