<template>
    <div class="max-w-5xl mx-auto">
        <div class="flex items-center gap-2 mb-4 flex-wrap">
            <a href="/" class="btn btn-ghost btn-sm gap-1" @click.prevent="goHome">
                <ArrowLeft :size="16" />
                Back
            </a>
            <h1 class="text-xl font-semibold">Import preview</h1>
        </div>

        <div v-if="!authStore.isAuthenticated" class="card bg-base-100 border border-base-300">
            <div class="card-body">
                <p>Log in to upload a statement or review an import.</p>
                <button type="button" class="btn btn-primary w-fit" @click="showModal('auth_modal')">Log in</button>
            </div>
        </div>

        <div v-else-if="!uuid" class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <p class="text-sm text-base-content/70">
                    Upload a bank statement or export (CSV, Excel, OFX, QIF, PDF, and more). You'll get a preview
                    of every row before anything is saved.
                </p>
                <input ref="fileInput" type="file" class="file-input file-input-bordered w-full max-w-md"
                    accept=".csv,.tsv,.txt,.xlsx,.ods,.ofx,.qfx,.qif,.json,.html,.htm,.pdf,.docx"
                    @change="onFile" />
                <p v-if="uploadError" class="text-error text-sm">{{ uploadError }}</p>
                <p v-if="uploading" class="text-sm flex items-center gap-2">
                    <span class="loading loading-spinner loading-sm"></span>
                    Reading the file…
                </p>
            </div>
        </div>

        <div v-else-if="loading" class="flex justify-center py-16">
            <span class="loading loading-spinner loading-lg"></span>
        </div>

        <div v-else-if="loadError" class="alert alert-error">{{ loadError }}</div>

        <template v-else-if="batch">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 mb-4">
                <div class="stat bg-base-100 border border-base-300 rounded-box p-3">
                    <div class="stat-title">Rows</div>
                    <div class="stat-value text-2xl">{{ batch.summary.total_rows }}</div>
                    <div class="stat-desc">{{ periodLabel }}</div>
                </div>
                <div class="stat bg-base-100 border border-base-300 rounded-box p-3">
                    <div class="stat-title">New</div>
                    <div class="stat-value text-2xl text-success">{{ batch.summary.new }}</div>
                    <div v-if="batch.summary.needs_tag" class="stat-desc">{{ batch.summary.needs_tag }} need a tag</div>
                </div>
                <div class="stat bg-base-100 border border-base-300 rounded-box p-3">
                    <div class="stat-title">Expenses</div>
                    <div class="stat-value text-2xl text-error">{{ formatMoney(batch.summary.expense_total) }}</div>
                    <div class="stat-desc">Income {{ formatMoney(batch.summary.income_total) }}</div>
                </div>
                <div class="stat bg-base-100 border border-base-300 rounded-box p-3">
                    <div class="stat-title">Held back</div>
                    <div class="stat-value text-2xl">{{ heldBack }}</div>
                    <div class="stat-desc">{{ batch.summary.duplicate }} dup · {{ batch.summary.transfer }} transfer · {{ batch.summary.skipped }} skipped</div>
                </div>
            </div>

            <div v-if="batch.categories?.length" class="card bg-base-100 border border-base-300 mb-4">
                <div class="card-body p-4 gap-3">
                    <div>
                        <h2 class="font-semibold">Bank categories</h2>
                        <p class="text-sm text-base-content/70">
                            How the file's own categories should be tagged. A shop rule still wins when one matches.
                        </p>
                    </div>
                    <div v-for="cat in batch.categories" :key="cat.name" class="flex flex-wrap items-center gap-2">
                        <span class="font-medium min-w-32">{{ cat.name }}</span>
                        <span class="badge badge-ghost">{{ cat.count }}</span>
                        <select class="select select-sm select-bordered" :value="cat.action" :disabled="busy || !editable"
                            @change="onCategoryAction(cat, $event.target.value)">
                            <option value="map">Map to a tag</option>
                            <option value="create">Create as a new tag</option>
                            <option value="ignore">Ignore</option>
                        </select>
                        <template v-if="cat.action === 'map'">
                            <span v-if="cat.tag" class="badge badge-ghost">{{ cat.tag.emoji }} {{ cat.tag.title }}</span>
                            <span v-else class="text-xs text-base-content/60">Choose a tag</span>
                            <button v-if="editable" type="button" class="btn btn-ghost btn-xs"
                                @click="categoryPicker = categoryPicker === cat.name ? null : cat.name">
                                {{ categoryPicker === cat.name ? 'Close' : 'Choose tag' }}
                            </button>
                        </template>
                        <template v-else-if="cat.action === 'create'">
                            <input class="input input-sm input-bordered" :value="cat.title || cat.name"
                                :disabled="busy || !editable" placeholder="New tag name"
                                @change="onCategoryRename(cat, $event.target.value)" />
                        </template>
                        <div v-if="categoryPicker === cat.name && editable" class="w-full p-2 bg-base-200 rounded-box">
                            <TagPicker :model-value="cat.tag_id ? [cat.tag_id] : []" searchable
                                @update:model-value="ids => onCategoryTag(cat, ids)" />
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="batch.by_tag?.length" class="flex flex-wrap gap-2 mb-4">
                <span v-for="item in batch.by_tag.slice(0, 12)" :key="item.tag_id ?? 'none'" class="badge badge-ghost gap-1">
                    {{ item.emoji }} {{ item.title }} · {{ item.count }}
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
            <div v-else-if="batch.status === 'undone'" class="alert mb-4">This import was undone.</div>
            <div v-else-if="batch.status === 'committing'" class="alert mb-4">
                <span class="loading loading-spinner loading-sm"></span>
                Importing… {{ batch.progress }}%
            </div>
            <div v-else-if="batch.status === 'failed'" class="alert alert-error mb-4">{{ batch.error_message }}</div>

            <div v-if="batch.isMutable !== false && ['previewing', 'failed'].includes(batch.status)"
                class="card bg-base-100 border border-base-300 mb-4">
                <div class="card-body p-4 gap-3">
                    <h2 class="font-semibold">Bulk rules</h2>
                    <div class="flex flex-wrap gap-2 items-end">
                        <label class="form-control">
                            <span class="label-text text-xs">Merchant contains</span>
                            <input v-model="ruleMerchant" class="input input-sm input-bordered" placeholder="Glovo" />
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs">Tag</span>
                            <input v-model="ruleTag" class="input input-sm input-bordered" placeholder="Food" list="import-tag-titles" />
                        </label>
                        <button type="button" class="btn btn-sm btn-outline" :disabled="busy || !ruleMerchant || !ruleTag" @click="applyMerchant">
                            Apply
                        </button>
                    </div>
                    <div class="flex flex-wrap gap-2 items-end">
                        <label class="form-control">
                            <span class="label-text text-xs">Skip before</span>
                            <input v-model="ruleBefore" type="date" class="input input-sm input-bordered" />
                        </label>
                        <button type="button" class="btn btn-sm btn-outline" :disabled="busy || !ruleBefore" @click="applyBefore">Skip</button>
                        <label class="form-control">
                            <span class="label-text text-xs">Skip name</span>
                            <input v-model="ruleSkipName" class="input input-sm input-bordered" placeholder="Olya" />
                        </label>
                        <button type="button" class="btn btn-sm btn-outline" :disabled="busy || !ruleSkipName" @click="applySkipName">Skip</button>
                        <label class="label cursor-pointer gap-2">
                            <input type="checkbox" class="toggle toggle-sm" :checked="!!batch.options?.include_foreign" @change="toggleForeign" />
                            <span class="label-text text-sm">Include non-UAH</span>
                        </label>
                    </div>
                    <datalist id="import-tag-titles">
                        <option v-for="tag in tagsStore.tags" :key="tag.id" :value="tag.title" />
                    </datalist>
                    <p class="text-xs text-base-content/60">Or tell the assistant “Glovo is Food, skip transfers to Olya”.</p>
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
                <input v-model="search" type="search" class="input input-sm input-bordered ml-auto" placeholder="Filter notes"
                    @change="reload()" />
            </div>

            <div class="overflow-x-auto border border-base-300 rounded-box bg-base-100">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Line</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Note</th>
                            <th>Tags</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in batch.rows" :key="row.id" :class="rowClass(row)">
                            <td>{{ row.line }}</td>
                            <td class="whitespace-nowrap">{{ row.at || '—' }}</td>
                            <td class="whitespace-nowrap" :class="row.amount > 0 ? 'text-success' : 'text-error'">
                                {{ row.amount == null ? '—' : formatMoney(row.amount) }}
                            </td>
                            <td class="max-w-xs truncate" :title="row.note">{{ row.note || '—' }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1 items-center">
                                    <span v-for="tag in row.tags" :key="tag.id" class="badge badge-ghost badge-sm">{{ tag.emoji }} {{ tag.title }}</span>
                                    <span v-for="title in row.new_tag_titles" :key="title" class="badge badge-warning badge-sm">New: {{ title }}</span>
                                    <span v-if="row.manual" class="text-xs text-base-content/50">edited</span>
                                    <button v-if="editable" type="button" class="btn btn-ghost btn-xs" @click="editing = editing === row.id ? null : row.id">
                                        Edit
                                    </button>
                                </div>
                                <div v-if="editing === row.id" class="mt-2 p-2 bg-base-200 rounded-box max-w-sm">
                                    <TagPicker :model-value="row.tag_ids" searchable @update:model-value="ids => saveTags(row, ids)" />
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-sm" :class="statusBadge(row.status)">{{ row.status.replace('_', ' ') }}</span>
                                <div v-if="row.skip_reason || row.error" class="text-xs text-base-content/60">{{ row.skip_reason || row.error }}</div>
                                <button v-if="editable && row.status !== 'new'" type="button" class="btn btn-link btn-xs px-0" @click="setRowStatus(row, 'new')">
                                    Import anyway
                                </button>
                                <button v-else-if="editable && row.status === 'new'" type="button" class="btn btn-link btn-xs px-0" @click="setRowStatus(row, 'skipped')">
                                    Skip
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!batch.rows.length">
                            <td colspan="6" class="text-center text-base-content/60">No rows in this filter</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between mt-3">
                <div class="join" v-if="batch.meta.last_page > 1">
                    <button type="button" class="join-item btn btn-sm" :disabled="page <= 1" @click="page--; reload()">Prev</button>
                    <button type="button" class="join-item btn btn-sm btn-ghost">{{ page }} / {{ batch.meta.last_page }}</button>
                    <button type="button" class="join-item btn btn-sm" :disabled="page >= batch.meta.last_page" @click="page++; reload()">Next</button>
                </div>
                <button v-if="['previewing', 'failed'].includes(batch.status)" type="button" class="btn btn-primary ml-auto"
                    :disabled="busy || !importableCount" @click="onCommit">
                    <span v-if="busy" class="loading loading-spinner loading-sm"></span>
                    Import {{ importableCount }} transaction{{ importableCount === 1 ? '' : 's' }}
                </button>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ArrowLeft } from 'lucide-vue-next'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useAuthStore } from '../services/auth.js'
import { formatMoney } from '../services/formatters.js'
import { applyImportRules, commitImport, fetchImport, undoImport, updateImportRow, uploadImport } from '../services/imports.js'
import { showModal } from '../services/modal.js'
import { apiErrorMessage } from '../services/formatters.js'
import { useTagsStore } from '../services/tags.js'
import toasts from '../services/toasts.js'
import TagPicker from './TagPicker.vue'

const props = defineProps({
    uuid: { type: String, default: '' },
})

const authStore = useAuthStore()
const tagsStore = useTagsStore()
const batch = ref(null)
const loading = ref(false)
const loadError = ref('')
const uploadError = ref('')
const uploading = ref(false)
const busy = ref(false)
const statusFilter = ref('all')
const search = ref('')
const page = ref(1)
const editing = ref(null)
const ruleMerchant = ref('')
const ruleTag = ref('')
const ruleBefore = ref('')
const ruleSkipName = ref('')
const fileInput = ref(null)
const categoryPicker = ref(null)
let pollTimer = null

const heldBack = computed(() => {
    const s = batch.value?.summary
    return s ? s.duplicate + s.transfer + s.skipped + s.error : 0
})
const periodLabel = computed(() => {
    const s = batch.value?.summary
    if (!s?.period_from) return batch.value?.filename || ''
    return s.period_from === s.period_to ? s.period_from : `${s.period_from} – ${s.period_to}`
})
const importableCount = computed(() => {
    const s = batch.value?.summary
    return s ? s.new + s.needs_tag : 0
})
const editable = computed(() => ['previewing', 'failed'].includes(batch.value?.status))
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

const goHome = () => {
    window.history.pushState({}, '', '/')
    window.location.assign('/')
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
        loadError.value = apiErrorMessage(error, 'Could not load this import')
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

const onFile = async (event) => {
    const file = event.target.files?.[0]
    if (!file) return
    uploading.value = true
    uploadError.value = ''
    try {
        const created = await uploadImport(file)
        window.history.pushState({}, '', `/import/${created.id}`)
        window.location.assign(`/import/${created.id}`)
    } catch (error) {
        uploadError.value = apiErrorMessage(error, 'Could not read this file')
    } finally {
        uploading.value = false
    }
}

const applyMerchant = async () => {
    await runRules({ merchant_map: { [ruleMerchant.value]: ruleTag.value } })
    ruleMerchant.value = ''
    ruleTag.value = ''
}
const onCategoryAction = async (cat, action) => {
    if (action === 'map') {
        if (cat.tag_id) {
            await runRules({ category: cat.name, category_action: 'map', tag_id: cat.tag_id })
        } else {
            categoryPicker.value = cat.name
        }
        return
    }
    categoryPicker.value = null
    const payload = { category: cat.name, category_action: action }
    if (action === 'create') payload.title = cat.title || cat.name
    await runRules(payload)
}
const onCategoryRename = async (cat, title) => {
    const name = (title || '').trim()
    if (!name) return
    await runRules({ category: cat.name, category_action: 'create', title: name })
}
const onCategoryTag = async (cat, ids) => {
    const next = ids.find((id) => id !== cat.tag_id) ?? ids[ids.length - 1]
    if (!next) return
    await runRules({ category: cat.name, category_action: 'map', tag_id: next })
    categoryPicker.value = null
}
const applyBefore = async () => {
    await runRules({ skip_before: ruleBefore.value })
}
const applySkipName = async () => {
    await runRules({ skip_names: [ruleSkipName.value] })
    ruleSkipName.value = ''
}
const toggleForeign = async (event) => {
    await runRules({ include_foreign: event.target.checked })
}
const runRules = async (rules) => {
    busy.value = true
    try {
        batch.value = await applyImportRules(props.uuid, rules)
        toasts.success('Preview updated')
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not apply the rule'))
    } finally {
        busy.value = false
    }
}

const saveTags = async (row, ids) => {
    busy.value = true
    try {
        batch.value = await updateImportRow(props.uuid, row.id, { tag_ids: ids })
        editing.value = null
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not update tags'))
    } finally {
        busy.value = false
    }
}

const setRowStatus = async (row, status) => {
    busy.value = true
    try {
        batch.value = await updateImportRow(props.uuid, row.id, { status })
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not update the row'))
    } finally {
        busy.value = false
    }
}

const onCommit = async () => {
    busy.value = true
    try {
        batch.value = await commitImport(props.uuid)
        toasts.success('Imported')
        if (batch.value.status === 'committing') startPoll()
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not import'))
    } finally {
        busy.value = false
    }
}

const onUndo = async () => {
    if (!confirm('Delete every transaction created by this import?')) return
    busy.value = true
    try {
        batch.value = await undoImport(props.uuid)
        toasts.success('Import undone')
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not undo'))
    } finally {
        busy.value = false
    }
}

const rowClass = (row) => ({
    'opacity-60': ['duplicate', 'skipped', 'transfer'].includes(row.status),
    'bg-warning/10': row.status === 'needs_tag',
    'bg-error/10': row.status === 'error',
})
const statusBadge = (status) => ({
    new: 'badge-success',
    duplicate: 'badge-ghost',
    transfer: 'badge-info',
    skipped: 'badge-ghost',
    needs_tag: 'badge-warning',
    error: 'badge-error',
}[status] || 'badge-ghost')

watch(() => props.uuid, () => {
    batch.value = null
    page.value = 1
    reload()
})

onMounted(reload)
onUnmounted(stopPoll)
</script>
