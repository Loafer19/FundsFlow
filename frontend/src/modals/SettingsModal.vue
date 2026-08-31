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
                    <div class="p-3 rounded-box bg-base-200 mb-3">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <label for="formatDate" class="font-medium text-sm">Date</label>
                            <span class="text-xs text-base-content/60 font-mono shrink-0">{{ formatDatePreview }}</span>
                        </div>
                        <select v-model="formatDate" class="select select-sm w-full" id="formatDate">
                            <optgroup v-for="(options, key) in formatDateOptions" :label="key">
                                <option v-for="option in options" :value="option">
                                    {{ option }}
                                </option>
                            </optgroup>
                        </select>
                    </div>

                    <div class="p-3 rounded-box bg-base-200 mb-3">
                        <div class="font-medium text-sm mb-2">Money</div>
                        <div class="flex flex-wrap gap-1.5 mb-3" role="group" aria-label="Money format">
                            <button v-for="(value, title) in formatMoneyOptions" :key="value" type="button"
                                class="btn btn-sm"
                                :class="formatMoney === value ? 'btn-primary' : 'btn-outline'"
                                :aria-pressed="formatMoney === value" @click="formatMoney = value">
                                {{ title }}
                            </button>
                        </div>
                        <label class="label cursor-pointer justify-start gap-2 py-0">
                            <input v-model="decimals" type="checkbox" class="checkbox checkbox-info checkbox-sm" />
                            <span class="label-text text-sm">Show decimals</span>
                        </label>
                    </div>
                </template>

                <template v-else-if="tab === 'theme'">
                    <template v-if="favoriteThemes.length">
                        <div class="text-xs text-base-content/60 mb-2">Favorites</div>
                        <div class="grid grid-cols-2 gap-2 mb-3">
                            <button v-for="theme in favoriteThemes" :key="'fav-' + theme" type="button"
                                class="theme-swatch btn btn-ghost h-auto min-h-0 p-3 gap-2 justify-start border w-full"
                                :class="themeSelected === theme ? 'border-primary' : 'border-base-300'"
                                :aria-label="themeLabel(theme)" :aria-pressed="themeSelected === theme"
                                @click="themeSelected = theme">
                                <span class="theme-chips flex gap-0.5 shrink-0" :data-theme="theme">
                                    <span class="chip bg-primary"></span>
                                    <span class="chip bg-secondary"></span>
                                    <span class="chip bg-accent"></span>
                                    <span class="chip bg-base-100 border border-base-300"></span>
                                </span>
                                <span class="text-sm truncate flex-1 text-left">{{ themeLabel(theme) }}</span>
                                <span class="text-sm shrink-0" role="button" tabindex="0"
                                    :aria-label="'Unfavorite ' + theme"
                                    @click.stop="toggleFavorite(theme)"
                                    @keydown.enter.stop.prevent="toggleFavorite(theme)">★</span>
                            </button>
                        </div>
                        <hr class="border-base-300 mb-3" />
                    </template>

                    <div class="grid grid-cols-2 gap-2">
                        <button v-for="theme in otherThemes" :key="theme" type="button"
                            class="theme-swatch btn btn-ghost h-auto min-h-0 p-3 gap-2 justify-start border w-full"
                            :class="themeSelected === theme ? 'border-primary' : 'border-base-300'"
                            :aria-label="themeLabel(theme)" :aria-pressed="themeSelected === theme"
                            @click="themeSelected = theme">
                            <span class="theme-chips flex gap-0.5 shrink-0" :data-theme="theme">
                                <span class="chip bg-primary"></span>
                                <span class="chip bg-secondary"></span>
                                <span class="chip bg-accent"></span>
                                <span class="chip bg-base-100 border border-base-300"></span>
                            </span>
                            <span class="text-sm truncate flex-1 text-left">{{ themeLabel(theme) }}</span>
                            <span class="text-sm shrink-0 opacity-50" role="button" tabindex="0"
                                :aria-label="'Favorite ' + theme"
                                @click.stop="toggleFavorite(theme)"
                                @keydown.enter.stop.prevent="toggleFavorite(theme)">☆</span>
                        </button>
                    </div>
                </template>

                <template v-else-if="tab === 'accounts'">
                    <div class="flex flex-col gap-2 mb-4">
                        <div class="flex items-center gap-2 p-3 rounded-box bg-base-200">
                            <span class="font-medium w-24 shrink-0">Google</span>
                            <span v-if="googleIdentity" class="badge badge-success badge-sm">Linked</span>
                            <span v-else class="badge badge-ghost badge-sm">Not linked</span>
                            <span v-if="googleLabel" class="ml-auto text-xs text-base-content/50 truncate max-w-[40%]"
                                :title="googleLabel">{{ googleLabel }}</span>
                        </div>

                        <div class="flex items-center gap-2 p-3 rounded-box bg-base-200">
                            <span class="font-medium w-24 shrink-0">GitHub</span>
                            <span v-if="githubIdentity" class="badge badge-success badge-sm">Linked</span>
                            <span v-else class="badge badge-ghost badge-sm">Not linked</span>
                            <span v-if="githubLabel" class="ml-auto text-xs text-base-content/50 truncate max-w-[40%]"
                                :title="githubLabel">{{ githubLabel }}</span>
                        </div>

                        <div class="flex items-center gap-2 p-3 rounded-box bg-base-200">
                            <span class="font-medium w-24 shrink-0">Telegram</span>
                            <template v-if="telegramIdentity">
                                <span class="badge badge-success badge-sm">Linked</span>
                                <span v-if="telegramLinkLabel"
                                    class="ml-auto text-xs text-base-content/50 truncate max-w-[40%]"
                                    :title="telegramLinkLabel">{{ telegramLinkLabel }}</span>
                            </template>
                            <template v-else>
                                <span class="badge badge-ghost badge-sm">Not linked</span>
                                <button type="button" class="btn btn-primary btn-xs ml-auto"
                                    @click="openTelegramLink" :disabled="generatingTelegramLink"
                                    title="Link Telegram to add transactions from chat">
                                    Open Bot
                                    <span v-if="generatingTelegramLink"
                                        class="loading loading-spinner loading-xs"></span>
                                    <Send v-else :size="14" />
                                </button>
                            </template>
                        </div>
                    </div>

                    <div class="divider text-sm text-base-content/60">Login credentials</div>

                    <p class="text-sm text-base-content/60 mb-3">
                        Set an email and password to log in without Telegram or Google/GitHub
                    </p>

                    <form @submit.prevent="saveCredentials">
                        <input v-model="credentialsForm.email" type="email" placeholder="Email" aria-label="Email"
                            class="input w-full mb-4" maxlength="255" autofocus required />

                        <input v-model="credentialsForm.password" type="password" placeholder="New password"
                            aria-label="New password" class="input w-full mb-4" minlength="8" maxlength="255"
                            required />

                        <div class="flex justify-end">
                            <button type="submit" class="btn btn-primary btn-sm" :disabled="savingCredentials">
                                Save
                                <span v-if="savingCredentials" class="loading loading-spinner loading-xs"></span>
                                <KeyRound v-else :size="20" />
                            </button>
                        </div>
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

                    <div class="flex justify-end">
                        <button type="button" class="btn btn-primary btn-sm" :disabled="exporting" @click="exportData">
                            <span v-if="exporting" class="loading loading-spinner loading-xs"></span>
                            <Download v-else :size="20" />
                            Download JSON
                        </button>
                    </div>
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
import { Download, KeyRound, Save, Send } from 'lucide-vue-next'
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

const themeOptions = [
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
]

const themeSelected = ref(settings.theme)
const favoriteThemes = ref(JSON.parse(localStorage.getItem('theme:favorites') || '[]'))

const themeLabel = (theme) => theme.charAt(0).toUpperCase() + theme.slice(1)

const otherThemes = computed(() => themeOptions.filter((theme) => !favoriteThemes.value.includes(theme)))

const toggleFavorite = (theme) => {
    const next = favoriteThemes.value.includes(theme)
        ? favoriteThemes.value.filter((t) => t !== theme)
        : [...favoriteThemes.value, theme]
    favoriteThemes.value = next
    localStorage.setItem('theme:favorites', JSON.stringify(next))
}

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

.theme-chips {
    border-radius: 0.35rem;
    overflow: hidden;
    padding: 0.2rem;
    background: var(--color-base-200);
}

.chip {
    width: 0.7rem;
    height: 0.7rem;
    border-radius: 999px;
    display: inline-block;
}
</style>
