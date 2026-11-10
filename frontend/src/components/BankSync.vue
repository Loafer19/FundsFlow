<template>
    <div v-if="loading && !loaded" class="flex justify-center py-8">
        <span class="loading loading-dots loading-md text-base-content/40"></span>
    </div>

    <template v-else-if="!connection.connected">
        <p class="text-sm text-base-content/70 mb-3">
            Import your monobank cards automatically. New purchases show up in FundsFlow,
            and you can change the tag from the web app or Telegram.
        </p>

        <ol class="text-sm text-base-content/70 list-decimal pl-5 mb-4 space-y-1.5">
            <li>
                Open
                <a href="https://api.monobank.ua" target="_blank" rel="noopener noreferrer"
                    class="link link-primary">api.monobank.ua</a>
            </li>
            <li>Sign in with the monobank app or Diia</li>
            <li>Copy your personal token (it stays on our server, encrypted)</li>
            <li>Paste it below</li>
        </ol>

        <form class="flex flex-col gap-3" @submit.prevent="connect">
            <input v-model="token" type="password" autocomplete="off" spellcheck="false"
                class="input input-sm w-full font-mono" aria-label="Monobank personal token"
                placeholder="Personal token" maxlength="512" required />
            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary btn-sm" :disabled="busy || !token.trim()">
                    <span v-if="busy" class="loading loading-spinner loading-xs"></span>
                    Connect
                </button>
            </div>
        </form>

                <details class="rounded-box bg-base-200 px-3 py-2 mt-4">
            <summary class="text-sm font-medium cursor-pointer py-1">How it works</summary>
            <ul class="text-sm text-base-content/70 list-disc pl-5 pb-2 space-y-1.5">
                <li>Transfers between your own cards and jars are not counted as income or expenses.</li>
                <li>Only UAH cards are synced. Foreign-currency cards are listed but left off.</li>
                <li>Pending (hold) purchases appear immediately; the amount updates when they settle.</li>
                <li>The last 31 days are imported when you connect. New purchases arrive instantly after that.</li>
                <li>Auto-tagging learns from the tags you change on imported purchases.</li>
                <li>Deleted purchases stay deleted: Sync now won't bring them back.</li>
                <li>Telegram notifications are sent only for new purchases, not the first import or Sync now.</li>
                <li>Your token is stored encrypted. You can revoke it any time at api.monobank.ua.</li>
                <li>UAH jars can be tracked as savings goals: progress follows the jar balance in monobank.</li>
            </ul>
        </details>
    </template>

    <template v-else>
        <div class="flex items-center gap-2 mb-3">
            <span class="font-medium text-sm">Monobank</span>
            <span class="badge badge-sm" :class="statusBadge">{{ statusLabel }}</span>
        </div>

        <p v-if="connection.last_synced_at" class="text-xs text-base-content/60 mb-2">
            Last synced {{ formatDateTime(connection.last_synced_at) }}
        </p>
        <p v-if="connection.last_error" class="text-xs text-error mb-3">{{ connection.last_error }}</p>

                <details class="rounded-box bg-base-200 px-3 py-2 mb-3">
            <summary class="text-sm font-medium cursor-pointer py-1">How it works</summary>
            <ul class="text-sm text-base-content/70 list-disc pl-5 pb-2 space-y-1.5">
                <li>Transfers between your own cards and jars are not counted as income or expenses.</li>
                <li>Only UAH cards are synced. Foreign-currency cards are listed but left off.</li>
                <li>Pending (hold) purchases appear immediately; the amount updates when they settle.</li>
                <li>The last 31 days are imported when you connect. New purchases arrive instantly after that.</li>
                <li>Auto-tagging learns from the tags you change on imported purchases.</li>
                <li>Deleted purchases stay deleted: Sync now won't bring them back.</li>
                <li>Telegram notifications are sent only for new purchases, not the first import or Sync now.</li>
                <li>Your token is stored encrypted. You can revoke it any time at api.monobank.ua.</li>
                <li>UAH jars can be tracked as savings goals: progress follows the jar balance in monobank.</li>
            </ul>
        </details>

        <label class="flex items-center justify-between gap-3 p-3 rounded-box bg-base-200 mb-3 cursor-pointer">
            <span class="text-sm">Notify in Telegram</span>
            <input type="checkbox" class="toggle toggle-primary toggle-sm"
                :checked="connection.notify" :disabled="busy" @change="toggleNotify($event.target.checked)" />
        </label>

        <div class="flex flex-col gap-2 mb-4">
            <div v-for="account in connection.accounts" :key="account.id"
                class="p-3 rounded-box bg-base-200">
                <div class="flex items-start justify-between gap-3 mb-2">
                    <label class="flex items-start gap-2 min-w-0 cursor-pointer"
                        :class="{ 'opacity-60': !account.syncable }">
                        <input type="checkbox" class="checkbox checkbox-primary checkbox-sm mt-0.5"
                            :checked="account.enabled" :disabled="busy || !account.syncable"
                            @change="toggleAccount(account, $event.target.checked)" />
                        <span>
                            <span class="text-sm font-medium block">{{ account.label }}</span>
                            <span class="text-xs text-base-content/50">
                                {{ account.currency }}
                                <template v-if="!account.syncable"> · UAH only — this card isn’t imported</template>
                            </span>
                        </span>
                    </label>
                </div>
                <PopoverMenu label="Extra tag" root-class="block w-full">
                    <template #trigger="{ toggle, open }">
                        <button type="button" class="badge badge-soft px-2 gap-1 text-base h-8 font-normal"
                            :class="accountTag(account) ? 'badge-info' : 'badge-neutral'"
                            :disabled="busy" :aria-expanded="open" aria-haspopup="dialog"
                            :aria-label="`Tag for ${account.label}`" @click="toggle">
                            <template v-if="accountTag(account)">
                                <span>{{ accountTag(account).emoji }}</span>
                                <span>{{ accountTag(account).title }}</span>
                            </template>
                            <span v-else>No extra tag</span>
                        </button>
                    </template>
                    <template #default="{ close }">
                        <button type="button" class="badge gap-0 px-1 text-lg cursor-pointer mb-2 w-fit"
                            :class="account.tag_id ? 'badge-soft badge-neutral' : 'badge-neutral'"
                            @click="mapTag(account, ''); close()">
                            No extra tag
                        </button>
                        <TagPicker :model-value="account.tag_id ? [account.tag_id] : []" single
                            @update:model-value="onAccountTags(account, $event, close)" />
                    </template>
                </PopoverMenu>
            </div>
            <p v-if="!connection.accounts?.length" class="text-xs text-base-content/50">
                No cards were returned for this token.
            </p>
        </div>

        <div v-if="connection.jars?.length" class="mb-4">
            <h3 class="text-sm font-medium mb-1">Jars</h3>
            <p class="text-xs text-base-content/60 mb-2">
                Track a UAH jar as a savings goal. Progress follows the jar balance — you can set a target and deadline on the goal.
            </p>
            <div class="flex flex-col gap-2">
                <div v-for="jar in connection.jars" :key="jar.id" class="p-3 rounded-box bg-base-200">
                    <label class="flex items-start justify-between gap-3 cursor-pointer"
                        :class="{ 'opacity-60': !jar.syncable }">
                        <span class="min-w-0">
                            <span class="text-sm font-medium block">{{ jar.label }}</span>
                            <span class="text-xs text-base-content/50">
                                {{ jar.currency }}
                                <template v-if="jar.balance != null">
                                    · {{ formatMoney(jar.balance) }}
                                </template>
                                <template v-if="jar.goal_amount">
                                    of {{ formatMoney(jar.goal_amount) }}
                                </template>
                                <template v-if="!jar.syncable"> · UAH only</template>
                            </span>
                        </span>
                        <span class="flex items-center gap-2 shrink-0">
                            <span class="text-xs text-base-content/60 hidden sm:inline">Track as goal</span>
                            <input type="checkbox" class="toggle toggle-primary toggle-sm"
                                :checked="jar.track_as_goal"
                                :disabled="busy || !jar.syncable"
                                :aria-label="`Track ${jar.label} as goal`"
                                @change="toggleJar(jar, $event.target.checked)" />
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap justify-end gap-2">
            <button type="button" class="btn btn-outline btn-sm" :disabled="busy || cooldown > 0" @click="syncNow">
                <span v-if="syncing" class="loading loading-spinner loading-xs"></span>
                {{ cooldown > 0 ? `Sync in ${cooldown}s` : 'Sync now' }}
            </button>
            <button type="button" class="btn btn-outline btn-error btn-sm" :disabled="busy" @click="disconnect">
                Disconnect
            </button>
        </div>
    </template>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { apiErrorMessage, formatDateTime, formatMoney } from '../services/formatters'
import { useBudgetsStore } from '../services/budgets'
import {
    connectMonobank,
    disconnectMonobank,
    getMonobank,
    syncMonobank,
    updateMonobank,
} from '../services/monobank'
import { useTagsStore } from '../services/tags'
import toasts from '../services/toasts'
import PopoverMenu from './PopoverMenu.vue'
import TagPicker from './TagPicker.vue'

const empty = { connected: false, accounts: [], jars: [], notify: true, status: 'active' }
const connection = ref({ ...empty })
const token = ref('')
const loading = ref(false)
const loaded = ref(false)
const busy = ref(false)
const syncing = ref(false)
const cooldown = ref(0)
let cooldownTimer = null

const startCooldown = (seconds) => {
    cooldown.value = Math.max(0, Math.ceil(Number(seconds) || 0))
    clearInterval(cooldownTimer)
    if (cooldown.value <= 0) return
    cooldownTimer = setInterval(() => {
        cooldown.value -= 1
        if (cooldown.value <= 0) clearInterval(cooldownTimer)
    }, 1000)
}

onUnmounted(() => clearInterval(cooldownTimer))
const tagsStore = useTagsStore()
const budgetsStore = useBudgetsStore()

const statusLabel = computed(() => {
    if (connection.value.status === 'syncing') return 'Syncing'
    if (connection.value.status === 'error') return 'Error'
    return 'Connected'
})

const statusBadge = computed(() => {
    if (connection.value.status === 'syncing') return 'badge-info'
    if (connection.value.status === 'error') return 'badge-error'
    return 'badge-success'
})

const accountTag = (account) => tagsStore.tags.find((tag) => Number(tag.id) === Number(account.tag_id)) ?? null

const onAccountTags = (account, ids, close) => {
    const current = account.tag_id ? Number(account.tag_id) : null
    const next = (ids || []).map(Number).find((id) => id !== current) ?? (ids || []).map(Number).at(-1)
    mapTag(account, next ?? '')
    if (next) close?.()
}

const apply = (data) => {
    connection.value = data?.connected ? { jars: [], ...data } : { ...empty }
}

const refresh = async () => {
    loading.value = true
    try {
        const { data } = await getMonobank()
        apply(data)
        loaded.value = true
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not load bank sync: '))
    } finally {
        loading.value = false
    }
}

const connect = async () => {
    const value = token.value.trim()
    if (!value || busy.value) return
    busy.value = true
    try {
        const { data } = await connectMonobank(value)
        token.value = ''
        apply(data)
        toasts.success('Monobank connected. The last 31 days will import in the background.')
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not connect: '))
    } finally {
        busy.value = false
    }
}

const toggleNotify = async (notify) => {
    busy.value = true
    try {
        const { data } = await updateMonobank({ notify })
        apply(data)
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not save: '))
    } finally {
        busy.value = false
    }
}

const toggleAccount = async (account, enabled) => {
    busy.value = true
    try {
        const { data } = await updateMonobank({ accounts: [{ id: account.id, enabled }] })
        apply(data)
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not save: '))
    } finally {
        busy.value = false
    }
}

const mapTag = async (account, value) => {
    const tagId = value === '' ? null : Number(value)
    busy.value = true
    try {
        const { data } = await updateMonobank({ accounts: [{ id: account.id, tag_id: tagId }] })
        apply(data)
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not save: '))
    } finally {
        busy.value = false
    }
}

const toggleJar = async (jar, track) => {
    busy.value = true
    try {
        const { data } = await updateMonobank({ jars: [{ id: jar.id, track_as_goal: track }] })
        apply(data)
        await budgetsStore.reload()
        toasts.success(track ? 'Jar tracked as a savings goal.' : 'Jar unlinked from its goal. The goal is kept.')
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not save: '))
    } finally {
        busy.value = false
    }
}

const syncNow = async () => {
    if (busy.value) return
    busy.value = true
    syncing.value = true
    try {
        const { data } = await syncMonobank()
        apply(data)
        await budgetsStore.reload()
        toasts.success('Sync started. New items appear in a minute or two.')
        startCooldown(60)
    } catch (error) {
        if (error.response?.status === 429) {
            const wait = error.response?.data?.retry_after ?? error.retryAfter ?? 60
            startCooldown(wait)
            toasts.info(`Monobank allows one refresh per minute. Sync will be available in ${Math.ceil(wait)}s.`)
        } else {
            toasts.error(apiErrorMessage(error, 'Could not sync: '))
        }
    } finally {
        busy.value = false
        syncing.value = false
    }
}

const disconnect = async () => {
    if (!confirm('Disconnect monobank? New transactions will stop syncing. Existing ones stay in your list. Jar goals are kept and marked as unlinked.')) {
        return
    }
    busy.value = true
    try {
        const { data } = await disconnectMonobank()
        apply(data)
        await budgetsStore.reload()
        toasts.success('Monobank disconnected.')
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Could not disconnect: '))
    } finally {
        busy.value = false
    }
}

onMounted(refresh)
defineExpose({ refresh })
</script>
