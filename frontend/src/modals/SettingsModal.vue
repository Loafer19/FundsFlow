<template>
    <dialog id="settings_modal" class="modal">
        <div class="modal-box max-w-sm">
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
                    <select v-model="formatDate" class="select w-full mb-3" id="formatDate">
                        <optgroup v-for="(options, key) in formatDateOptions" :label="key">
                            <option v-for="option in options" :value="option">
                                {{ option }}
                            </option>
                        </optgroup>
                    </select>

                    <label for="formatMoney">Money</label>
                    <select v-model="formatMoney" class="select w-full mb-3" id="formatMoney">
                        <option v-for="(value, title) in formatMoneyOptions" :value="value">
                            {{ title }}
                        </option>
                    </select>

                    <label class="label">
                        <input v-model="decimals" type="checkbox" class="checkbox checkbox-info checkbox-sm" />
                        Show decimals
                    </label>

                    <div class="mt-4 p-3 rounded-box bg-base-200 text-sm">
                        <div class="opacity-60 mb-1">Preview</div>
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

                <template v-else-if="tab === 'telegram'">
                    <p class="text-sm opacity-70 mb-3">
                        Open <a :href="telegramBotUrl" target="_blank" class="link link-primary">@{{
                            telegramBotUsername }}</a>
                        in Telegram, send <code>/start</code>, and enter the code you get below.
                    </p>

                    <input v-model="telegramCode" type="text" inputmode="numeric" maxlength="6"
                        placeholder="Code from Telegram" class="input w-full mb-3" />

                    <button type="button" class="btn btn-primary btn-sm" @click="linkTelegram"
                        :disabled="linkingTelegram || !telegramCode">
                        <span v-if="linkingTelegram" class="loading loading-spinner loading-xs"></span>
                        Link
                    </button>
                </template>
            </div>

            <div class="modal-action">
                <button type="submit" class="btn btn-success" @click="saveSettings">
                    <span class="loading loading-spinner" v-if="saving"></span>
                    Save
                </button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { formatDateOptions, formatMoneyOptions, apiErrorMessage } from '../services/formatters'
import { linkTelegram as linkTelegramRequest } from '../services/identities'
import settings, { updateDateFormat, updateDecimals, updateMoneyFormat, updateTheme } from '../services/settings'
import toasts from '../services/toasts'

const tabs = ref({
    formatting: 'Formatting',
    theme: 'Theme',
    telegram: 'Telegram',
})
const tab = ref('formatting')

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

bindModalEvents()

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

const telegramBotUsername = import.meta.env.VITE_TELEGRAM_BOT_USERNAME
const telegramBotUrl = `https://t.me/${telegramBotUsername}`
const telegramCode = ref('')
const linkingTelegram = ref(false)

const linkTelegram = async () => {
    linkingTelegram.value = true

    try {
        await linkTelegramRequest(telegramCode.value.trim())

        toasts.success('Telegram linked successfully!')
        telegramCode.value = ''
    } catch (error) {
        toasts.error(apiErrorMessage(error, 'Failed to link: '))
    } finally {
        linkingTelegram.value = false
    }
}

const saving = ref(false)

const saveSettings = () => {
    saving.value = true

    updateDateFormat(formatDate.value)
    updateMoneyFormat(formatMoney.value)
    updateDecimals(decimals.value)
    updateTheme(themeSelected.value)

    setTimeout(() => {
        saving.value = false
        toasts.success('Settings saved successfully!')
        settings_modal.close()
    }, 150)
}
</script>

<style scoped>
.modal-content {
    max-height: 58vh;
    overflow-y: auto;
}
</style>
