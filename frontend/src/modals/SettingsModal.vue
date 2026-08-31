<template>
    <dialog id="settings_modal" class="modal" aria-labelledby="settings_modal_title">
        <div class="modal-box max-w-sm">
            <h2 id="settings_modal_title" class="card-title mb-4">Settings</h2>

            <div class="tabs tabs-box justify-center mb-4">
                <template v-for="(title, key) in tabs">
                    <a class="tab" @click="tab = key" :class="{ 'tab-active': tab === key }">
                        {{ title }}
                    </a>
                </template>
            </div>

            <div class="modal-content">
                <template v-if="tab === 'formatting'">
                    <label for="formatDate">Date</label>
                    <select v-model="formatDate" class="select w-full mb-4" id="formatDate">
                        <optgroup v-for="(options, key) in formatDateOptions" :label="key">
                            <option v-for="option in options" :value="option">
                                {{ option }}
                            </option>
                        </optgroup>
                    </select>

                    <label for="formatMoney">Money</label>
                    <select v-model="formatMoney" class="select w-full mb-4" id="formatMoney">
                        <option v-for="(value, title) in formatMoneyOptions" :value="value">
                            {{ title }}
                        </option>
                    </select>

                    <label class="label">
                        <input v-model="decimals" type="checkbox" class="checkbox checkbox-info checkbox-sm" />
                        Show decimals
                    </label>

                    <div class="mt-4 p-3 rounded-box bg-base-200 text-sm">
                        <div class="text-base-content/60 mb-1">Preview</div>
                        <div>{{ formatDatePreview }} · {{ formatMoneyPreview }}</div>
                    </div>
                </template>

                <template v-else-if="tab === 'theme'">
                    <fieldset class="fieldset">
                        <label class="flex gap-2 cursor-pointer items-center" v-for="theme in themeOptions"
                            :key="theme">
                            <input type="radio" name="theme-radios" class="radio radio-sm theme-controller"
                                :value="theme" v-model="themeSelected" />
                            {{ theme.charAt(0).toUpperCase() + theme.slice(1) }}
                        </label>
                    </fieldset>
                </template>

                <template v-else-if="tab === 'accounts'">
                    <div class="flex flex-col gap-2 mb-4">
                        <div class="flex items-center justify-between p-3 rounded-box bg-base-200">
                            <span>Google</span>
                            <span class="tooltip tooltip-left" :data-tip="statusTooltip(googleIdentity, googleLabel)">
                                <CircleCheck v-if="googleIdentity" :size="20" class="text-success" />
                                <Circle v-else :size="20" class="text-base-content/40" />
                            </span>
                        </div>
                        <div class="flex items-center justify-between p-3 rounded-box bg-base-200">
                            <span>GitHub</span>
                            <span class="tooltip tooltip-left" :data-tip="statusTooltip(githubIdentity, githubLabel)">
                                <CircleCheck v-if="githubIdentity" :size="20" class="text-success" />
                                <Circle v-else :size="20" class="text-base-content/40" />
                            </span>
                        </div>
                        <div class="flex items-center justify-between p-3 rounded-box bg-base-200">
                            <span>Telegram</span>
                            <span class="tooltip tooltip-left"
                                :data-tip="statusTooltip(telegramIdentity, telegramLinkLabel)">
                                <CircleCheck v-if="telegramIdentity" :size="20" class="text-success" />
                                <Circle v-else :size="20" class="text-base-content/40" />
                            </span>
                        </div>
                    </div>

                    <template v-if="!telegramIdentity">
                        <div class="divider text-sm text-base-content/60">Link Telegram</div>

                        <p class="text-sm text-base-content/60 mb-3">
                            Link your Telegram account to add transactions from chat
                        </p>

                        <button type="button" class="btn btn-primary btn-sm" @click="openTelegramLink"
                            :disabled="generatingTelegramLink">
                            Open Telegram to link
                            <span v-if="generatingTelegramLink" class="loading loading-spinner loading-xs"></span>
                            <Send v-else :size="20" />
                        </button>
                    </template>

                    <div class="divider text-sm text-base-content/60">Email &amp; Password</div>

                    <p class="text-sm text-base-content/60 mb-3">
                        Set an email and password to log in without Telegram or Google/GitHub
                    </p>

                    <form @submit.prevent="saveCredentials">
                        <input v-model="credentialsForm.email" type="email" placeholder="Email" aria-label="Email"
                            class="input w-full mb-4" maxlength="255" autofocus required />

                        <input v-model="credentialsForm.password" type="password" placeholder="New password"
                            aria-label="New password" class="input w-full mb-4" minlength="8" maxlength="255"
                            required />

                        <button type="submit" class="btn btn-primary btn-sm" :disabled="savingCredentials">
                            Save
                            <span v-if="savingCredentials" class="loading loading-spinner loading-xs"></span>
                            <KeyRound v-else :size="20" />
                        </button>
                    </form>
                </template>

                <template v-else-if="tab === 'data'">
                    <p class="text-sm text-base-content/60 mb-4">
                        Download all your FundsFlow data as one JSON file
                    </p>

                    <pre
                        class="p-3 rounded-box bg-base-200 text-xs text-base-content/60 mb-4 font-mono leading-relaxed whitespace-pre-wrap">version,
exported_at,
account,
tags,
transactions,
budgets,
recurring_transactions</pre>

                    <button type="button" class="btn btn-primary btn-sm" :disabled="exporting" @click="exportData">
                        <span v-if="exporting" class="loading loading-spinner loading-xs"></span>
                        <Download v-else :size="20" />
                        Download JSON
                    </button>
                </template>
            </div>

            <div class="modal-action" v-if="tab === 'formatting' || tab === 'theme'">
                <button type="submit" class="btn btn-success" @click="saveSettings">
                    <span class="loading loading-spinner" v-if="saving"></span>
                    Save
                    <Save :size="20" />
                </button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { Circle, CircleCheck, Download, KeyRound, Save, Send } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { downloadAccountExport, updateCredentials, updatePreferences } from '../services/account'
import { useAuthStore } from '../services/auth'
import { apiErrorMessage, formatDateOptions, formatMoneyOptions } from '../services/formatters'
import { openTelegramLinkBot } from '../services/identities'
import settings, { updateDateFormat, updateDecimals, updateMoneyFormat, updateTheme } from '../services/settings'
import toasts from '../services/toasts'

const authStore = useAuthStore()

const tabs = ref({
    formatting: 'Formatting',
    theme: 'Theme',
    accounts: 'Accounts',
    data: 'Data',
})
const tab = ref('formatting')
const exporting = ref(false)

const formatDate = ref(settings.dateFormat)
const formatMoney = ref(settings.moneyFormat)
const decimals = ref(settings.decimals)

const themeOptions = ref([
    'acid',
    'autumn',
    'bumblebee',
    'caramellatte',
    'corporate',
    'cupcake',
    'lofi',
    'light',
    'cyberpunk',
    'cmyk',
    'winter',
    'emerald',
    'garden',
    'lemonade',
    'fantasy',
    'nord',
    'pastel',
    'retro',
    'silk',
    'valentine',
    'wireframe',
    'abyss',
    'aqua',
    'black',
    'business',
    'coffee',
    'dark',
    'dim',
    'dracula',
    'forest',
    'halloween',
    'luxury',
    'night',
    'sunset',
    'synthwave',
])

const themeSelected = ref(settings.theme)

watch(themeSelected, (newTheme) => {
    document.documentElement.setAttribute('data-theme', newTheme)
})

const syncFromSettings = () => {
    formatDate.value = settings.dateFormat
    formatMoney.value = settings.moneyFormat
    decimals.value = settings.decimals
    themeSelected.value = settings.theme
    credentialsForm.value.email = authStore.user?.email ?? ''
}

const onModalClose = () => {
    document.documentElement.setAttribute('data-theme', settings.theme)
    syncFromSettings()
}

const bindModalEvents = () => {
    const modal = document.getElementById('settings_modal')
    if (!modal || modal.dataset.bound === '1') return

    modal.dataset.bound = '1'
    modal.addEventListener('close', onModalClose)

    const originalShow = modal.showModal.bind(modal)
    modal.showModal = () => {
        syncFromSettings()
        originalShow()
    }
}

onMounted(bindModalEvents)

const datePreviewMap = {
    'YYYY/MM/DD': { month: '2-digit', day: '2-digit', year: 'numeric', locale: 'zh-CN' },
    'MM/DD/YYYY': { month: '2-digit', day: '2-digit', year: 'numeric', locale: 'en-US' },
    'MM/DD': { month: '2-digit', day: '2-digit', locale: 'en-US' },
    'long Month with Day & Year': { day: '2-digit', month: 'long', year: 'numeric' },
    'short Month with Day & Year': { day: '2-digit', month: 'short', year: 'numeric' },
    'long Month with Day': { day: '2-digit', month: 'long' },
    'short Month with Day': { day: '2-digit', month: 'short' },
    'DD-MM-YYYY': { day: '2-digit', month: '2-digit', year: 'numeric', locale: 'nl-NL' },
    'DD/MM/YYYY': { day: '2-digit', month: '2-digit', year: 'numeric', locale: 'en-GB' },
    'DD.MM.YYYY': { day: '2-digit', month: '2-digit', year: 'numeric', locale: 'uk-UA' },
    'DD-MM': { day: '2-digit', month: '2-digit', locale: 'nl-NL' },
    'DD/MM': { day: '2-digit', month: '2-digit', locale: 'en-GB' },
    'DD.MM': { day: '2-digit', month: '2-digit', locale: 'uk-UA' },
}

const formatDatePreview = computed(() => {
    const config = datePreviewMap[formatDate.value]
    return new Intl.DateTimeFormat(config.locale, config).format(new Date())
})

const formatMoneyPreview = computed(() => {
    return new Intl.NumberFormat(formatMoney.value, {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: decimals.value ? 2 : 0,
    }).format(1234.56)
})

const generatingTelegramLink = ref(false)

const identityFor = (provider) => authStore.user?.identities?.find((identity) => identity.provider === provider) ?? null

const telegramIdentity = computed(() => identityFor('telegram'))
const googleIdentity = computed(() => identityFor('google'))
const githubIdentity = computed(() => identityFor('github'))

const telegramLinkLabel = computed(() => {
    const meta = telegramIdentity.value?.meta

    if (!meta) return ''
    if (meta.username) return `@${meta.username}`
    if (meta.first_name) return meta.first_name

    return ''
})

const googleLabel = computed(() => googleIdentity.value?.meta?.name || googleIdentity.value?.meta?.nickname || '')
const githubLabel = computed(() => githubIdentity.value?.meta?.name || githubIdentity.value?.meta?.nickname || '')

const statusTooltip = (identity, label) => {
    if (!identity) return 'Not connected'

    return label ? `Linked to ${label}` : 'Linked'
}

const openTelegramLink = async () => {
    generatingTelegramLink.value = true

    try {
        await openTelegramLinkBot()
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Failed to generate link: '))
    } finally {
        generatingTelegramLink.value = false
    }
}

const credentialsForm = ref({ email: '', password: '' })
const savingCredentials = ref(false)

const saveCredentials = async () => {
    savingCredentials.value = true

    try {
        const response = await updateCredentials(credentialsForm.value)

        authStore.user.email = response.data.user.email

        toasts.success('Saved!')
        credentialsForm.value = { email: response.data.user.email, password: '' }
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Failed to save: '))
    } finally {
        savingCredentials.value = false
    }
}

const exportData = async () => {
    exporting.value = true

    try {
        await downloadAccountExport()
        toasts.success('Export downloaded')
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Export failed: '))
    } finally {
        exporting.value = false
    }
}

const saving = ref(false)

const saveSettings = async () => {
    saving.value = true

    updateDateFormat(formatDate.value)
    updateMoneyFormat(formatMoney.value)
    updateDecimals(decimals.value)
    updateTheme(themeSelected.value)

    if (authStore.isAuthenticated) {
        try {
            const response = await updatePreferences({
                moneyFormat: formatMoney.value,
                dateFormat: formatDate.value,
                decimals: decimals.value,
            })

            if (response.data?.user) {
                authStore.user = response.data.user
            }
        } catch {
            toasts.info('Saved locally; could not sync formatting preferences')
        }
    }

    saving.value = false
    toasts.success('Settings saved successfully!')
    settings_modal.close()
}
</script>

<style scoped>
.modal-content {
    max-height: 58vh;
    overflow-y: auto;
}
</style>
