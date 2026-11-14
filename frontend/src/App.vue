<template>
    <Toasts />
    <AuthModal />
    <TagsModal />
    <TagsAddModal />
    <TagsEditModal />
    <TypesModal />
    <TransactionsAddModal />
    <TransactionsEditModal />
    <AttachmentPreviewModal />

    <BudgetAddModal />
    <BudgetEditModal />
    <RecurringModal />
    <RecurringAddModal />
    <RecurringEditModal />
    <SettingsDrawer />
    <OnboardingModal />
    <WhatsNewModal />
    <SupportModal v-if="authStore.isAuthenticated" />
    <AgentDrawer v-if="authStore.isAuthenticated" />

    <div class="container mx-auto p-4 flex flex-col min-h-dvh"
        :class="{ 'max-md:pb-[calc(6rem+env(safe-area-inset-bottom))]': authStore.isAuthenticated }">
        <header class="flex gap-2 justify-end mb-4 flex-wrap items-center">
            <a href="/" class="mr-auto flex items-center gap-2 rounded-field hover:bg-base-200 px-1.5 py-1 -ml-1.5" @click.prevent="goHome">
                <img src="/logo.png" alt="" width="28" height="28" class="size-7 rounded-lg" />
                <span class="font-semibold">FundsFlow</span>
            </a>

            <a href="/docs/" target="_blank" rel="noopener"
                class="btn btn-ghost btn-square tooltip tooltip-bottom"
                :class="{ 'max-md:hidden': authStore.isAuthenticated }"
                data-tip="Docs" aria-label="Docs (opens in a new tab)">
                <CircleHelp :size="24" />
            </a>

            <button v-if="authStore.isAuthenticated" type="button" @click="agentStore.toggle()"
                class="btn btn-ghost btn-square tooltip tooltip-bottom" data-tip="Assistant (/)" aria-label="Assistant"
                aria-keyshortcuts="/ Control+K Meta+K" :aria-expanded="agentStore.isOpen" data-agent-toggle>
                <Sparkles :size="24" />
            </button>

            <template v-if="authStore.isAuthenticated">
                <button type="button" @click="showModal('transactions_add_modal')"
                    class="btn btn-primary hidden md:inline-flex" aria-label="Add Transaction">
                    <Plus :size="20" />
                    <span>Add Transaction</span>
                </button>

                <button type="button" @click="showModal('recurring_modal')"
                    class="btn btn-ghost btn-square tooltip tooltip-bottom" data-tip="Recurring"
                    aria-label="Recurring">
                    <Repeat :size="24" />
                </button>

                <button type="button" @click="showModal('tags_modal')"
                    class="btn btn-ghost btn-square tooltip tooltip-bottom" data-tip="Tags"
                    aria-label="Tags">
                    <Tag :size="24" />
                </button>

                <!-- Phones reach Types from More (the header already wraps there), so no duplicate. -->
                <button type="button" @click="showModal('types_modal')"
                    class="btn btn-ghost btn-square tooltip tooltip-bottom max-md:hidden" data-tip="Types"
                    aria-label="Types">
                    <Shapes :size="24" />
                </button>

                <button type="button" @click="openSettings()"
                    class="btn btn-ghost btn-square tooltip tooltip-bottom" data-tip="Settings"
                    aria-label="Settings">
                    <Settings :size="24" />
                </button>

                <button type="button" @click="authStore.logout"
                    class="btn btn-ghost btn-square tooltip tooltip-bottom hover:text-error"
                    data-tip="Logout" aria-label="Logout" :disabled="authStore.isLoading">
                    <span v-if="authStore.isLoading" class="loading loading-spinner"></span>
                    <LogOut v-else :size="24" />
                </button>
            </template>

            <button v-else-if="!isBootstrapping" @click="showModal('auth_modal')" class="btn btn-secondary">
                <LogIn :size="24" />
                Login
            </button>
        </header>

        <main>
            <ImportView v-if="importRoute !== null" :uuid="importRoute" />
            <template v-else>
            <div class="flex gap-2 mb-3 items-center flex-wrap">
                <div class="join" role="radiogroup" aria-label="Date range type">
                    <button v-for="option in periodTypes" :key="option.value" type="button" role="radio"
                        class="join-item btn btn-square border-base-300 tooltip tooltip-bottom"
                        :class="dateSelectionType === option.value ? 'btn-primary' : 'bg-base-100 hover:bg-base-200'"
                        :data-tip="option.label" :aria-label="option.label"
                        :aria-checked="dateSelectionType === option.value" @click="setDateSelectionType(option.value)">
                        <component :is="option.icon" :size="18" />
                    </button>
                </div>

                <div class="flex items-center gap-1 min-w-0 max-sm:flex-1">
                    <button type="button" class="btn btn-ghost btn-square" @click="shiftPeriod(-1)"
                        aria-label="Previous period">
                        <ChevronLeft :size="20" />
                    </button>

                    <div class="min-w-0 flex-1 sm:flex-none" aria-label="Selected period">
                        <VueDatePicker v-model="selectedRange" hide-offset-dates :enable-time-picker="false" auto-apply
                            :transitions="false" :week-picker="dateSelectionType === 'week'"
                            :month-picker="dateSelectionType === 'month'" :year-picker="dateSelectionType === 'year'"
                            :week-numbers="{ type: 'local' }" :min-date="new Date('2000-01-05')" :clearable="false"
                            prevent-min-max-navigation :year-range="[2020, 2040]" :hide-input-icon="true" ref="datePicker">
                            <template #dp-input="{ isMenuOpen }">
                                <button type="button"
                                    class="btn btn-ghost w-full sm:w-52 px-2 text-base sm:text-lg font-semibold gap-1 flex-nowrap"
                                    :aria-expanded="isMenuOpen" aria-haspopup="dialog">
                                    <span class="truncate sm:hidden">{{ periodLabelShort }}</span>
                                    <span class="truncate hidden sm:inline">{{ periodLabel }}</span>
                                    <ChevronDown :size="16" class="shrink-0 opacity-50 transition-transform"
                                        :class="{ 'rotate-180': isMenuOpen }" />
                                </button>
                            </template>
                        </VueDatePicker>
                    </div>

                    <button type="button" class="btn btn-ghost btn-square" @click="shiftPeriod(1)"
                        aria-label="Next period">
                        <ChevronRight :size="20" />
                    </button>
                </div>

                <PopoverMenu v-model="tagFilterOpen" label="Filter tags" root-class="max-md:basis-full max-md:w-full md:basis-auto">
                    <template #trigger="{ toggle, open }">
                        <button type="button"
                            class="btn border-base-300 text-base-content/50 text-sm max-md:w-full"
                            :aria-expanded="open" aria-haspopup="dialog" @click="toggle">
                            <Tag :size="16" />
                            Filter tags
                            <span v-if="tagFilterBadgeCount" class="badge badge-outline badge-sm">{{ tagFilterBadgeCount }}</span>
                        </button>
                    </template>
                    <button type="button" class="badge badge-info gap-0 px-1 text-lg cursor-pointer mb-2 w-fit hover:opacity-80"
                        :class="{ 'badge-soft': !transactionsStore.filterUntagged }"
                        :aria-pressed="transactionsStore.filterUntagged"
                        @click="transactionsStore.filterUntagged = !transactionsStore.filterUntagged">
                        <span>🏷️</span>
                        <span>Untagged</span>
                    </button>
                    <TagPicker v-model="transactionsStore.selectedTagIds" />
                    <button v-if="transactionsStore.tagFilterActive" type="button" class="btn btn-ghost btn-xs"
                        @click="transactionsStore.clearTagFilter()">
                        Clear
                    </button>
                </PopoverMenu>

                <div v-if="tagFilterChips.length" class="flex flex-wrap items-center gap-1.5 max-md:basis-full">
                    <span v-for="chip in tagFilterChips" :key="chip.key" class="badge badge-info gap-1 pl-2 pr-0.5">
                        {{ chip.emoji }} {{ chip.title }}
                        <button type="button" class="btn btn-ghost btn-xs btn-circle" :aria-label="`Remove ${chip.title} filter`"
                            @click="chip.remove()">
                            <X :size="12" />
                        </button>
                    </span>
                    <button v-if="tagFilterChips.length > 1" type="button" class="btn btn-ghost btn-xs"
                        @click="transactionsStore.clearTagFilter()">
                        Clear
                    </button>
                </div>
            </div>

            <GroupedTabs :groups="viewGroups" :model-value="currentKey" @update:model-value="goView" />
            <GroupSegments :groups="viewGroups" :active-group="activeGroup" :model-value="currentKey"
                @update:model-value="goView" />

            <!-- First data load: a loader instead of the tab (its empty state would flash). Invisible for the
                 first LOADER_DELAY_MS so fast loads show nothing. -->
            <BootLoader v-if="isBootstrapping" :class="{ invisible: !loaderVisible }" />
            <component v-else :is="selectedTab" :dateRange="getDateRange" :dateSelectionType
                @jump-to-month="handleJumpToMonth" @jump-to-list="handleJumpToList" />
            </template>
        </main>

        <AppFooter />

        <GroupDock v-if="authStore.isAuthenticated" :groups="viewGroups" :active-group="activeGroup"
            v-model:more-open="moreOpen" @select-group="selectGroup" @add="showModal('transactions_add_modal')"
            @import="goImport(); moreOpen = false" @recurring="showModal('recurring_modal'); moreOpen = false"
            @settings="openSettings(); moreOpen = false" @assistant="agentStore.toggle(); moreOpen = false"
            @tags="showModal('tags_modal'); moreOpen = false" @types="showModal('types_modal'); moreOpen = false" @feedback="showModal('support_modal'); moreOpen = false"
            @logout="authStore.logout" />
    </div>
</template>

<script setup>
import VueDatePicker from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'
import {
    ArrowLeftRight,
    Calendar,
    CalendarDays,
    CalendarFold,
    CalendarRange,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleHelp,
    LayoutDashboard,
    LogIn,
    LogOut,
    PiggyBank,
    Plus,
    Presentation,
    Repeat,
    Settings,
    Shapes,
    Sparkles,
    Table,
    Tag,
    Tags,
    TrendingUp,
    X,
} from '@lucide/vue'
import { computed, defineAsyncComponent, markRaw, nextTick, onMounted, provide, ref, watch } from 'vue'
import AgentDrawer from './components/AgentDrawer.vue'
import AppFooter from './components/AppFooter.vue'
import BootLoader from './components/BootLoader.vue'
import GroupDock from './components/GroupDock.vue'
import GroupedTabs from './components/GroupedTabs.vue'
import GroupSegments from './components/GroupSegments.vue'
import ImportView from './components/ImportView.vue'
import PopoverMenu from './components/PopoverMenu.vue'
import SettingsDrawer from './components/SettingsDrawer.vue'
import TagPicker from './components/TagPicker.vue'
import Toasts from './components/Toasts.vue'
import WhatsNewModal from './components/WhatsNewModal.vue'
import AttachmentPreviewModal from './modals/AttachmentPreviewModal.vue'
import AuthModal from './modals/AuthModal.vue'
import BudgetAddModal from './modals/BudgetAddModal.vue'
import BudgetEditModal from './modals/BudgetEditModal.vue'
import OnboardingModal from './modals/OnboardingModal.vue'
import RecurringAddModal from './modals/RecurringAddModal.vue'
import RecurringEditModal from './modals/RecurringEditModal.vue'
import RecurringModal from './modals/RecurringModal.vue'
import SupportModal from './modals/SupportModal.vue'
import TagsAddModal from './modals/TagsAddModal.vue'
import TagsEditModal from './modals/TagsEditModal.vue'
import TagsModal from './modals/TagsModal.vue'
import TransactionsAddModal from './modals/TransactionsAddModal.vue'
import TransactionsEditModal from './modals/TransactionsEditModal.vue'
import TypesModal from './modals/TypesModal.vue'
import { useAgentStore } from './services/agent.js'
import { useAuthStore } from './services/auth.js'
import { bootstrapStores, isBootstrapping } from './services/bootstrap.js'
import { useBudgetsStore } from './services/budgets.js'
import { resetCachedNotice } from './services/cache.js'
import { parseLocalDate } from './services/formatters.js'
import { closeSettings, openSettingsTab, showModal } from './services/modal.js'
import { reportOnboarding, shouldShowOnboarding, syncOnboardingFromUser } from './services/onboarding.js'
import { useRecurringTransactionsStore } from './services/recurringTransactions.js'
import { lazyImport } from './services/staleChunk.js'
import { useTagsStore } from './services/tags.js'
import { installTelegramChrome } from './services/telegramChrome.js'
import {
    getInitData,
    haptic,
    isTelegramWebApp,
    peekStashedInitData,
    stashInitData,
    takeStashedInitData,
    ready as telegramReady,
} from './services/telegramWebApp.js'
import { useTransactionsStore } from './services/transactions.js'
import { hasUnseenWhatsNew, markWhatsNewSeen } from './services/whatsNew.js'
import Analytics from './tabs/Analytics.vue'
import TabLoadError from './tabs/TabLoadError.vue'
import TabLoading from './tabs/TabLoading.vue'

// Analytics is the default tab (no charts) and ships in the main bundle; every other tab is
// its own chunk, so Chart.js only loads with Tags / Flow / Trend (or a chart in the assistant).
const lazyTab = (loader) =>
    markRaw(
        defineAsyncComponent({
            loader: lazyImport(loader),
            loadingComponent: TabLoading,
            errorComponent: TabLoadError,
            delay: 120,
            timeout: 30000,
        }),
    )

const BalanceTrend = lazyTab(() => import('./tabs/BalanceTrend.vue'))
const Budgets = lazyTab(() => import('./tabs/Budgets.vue'))
const CalendarTab = lazyTab(() => import('./tabs/Calendar.vue'))
const MoneyFlow = lazyTab(() => import('./tabs/MoneyFlow.vue'))
const TableTab = lazyTab(() => import('./tabs/TableTab.vue'))
const TagDistribution = lazyTab(() => import('./tabs/TagDistribution.vue'))

const authStore = useAuthStore()
const agentStore = useAgentStore()
const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()
const recurringTransactionsStore = useRecurringTransactionsStore()
const budgetsStore = useBudgetsStore()

const dateSelectionType = ref('month')
const datePicker = ref(null)
const selectedRange = ref(rangeFor('month'))
const selectedTab = ref(markRaw(Analytics))

const parseImportRoute = () => {
    const path = window.location.pathname.replace(/\/+$/, '') || '/'
    if (path === '/import') return ''
    const match = path.match(/^\/import\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i)
    return match ? match[1] : null
}
const importRoute = ref(parseImportRoute())
window.addEventListener('popstate', () => {
    importRoute.value = parseImportRoute()
})


const viewItems = {
    analytics: { key: 'analytics', label: 'Analytics', icon: markRaw(Presentation), tab: Analytics },
    calendar: { key: 'calendar', label: 'Calendar', icon: markRaw(CalendarDays), tab: CalendarTab },
    list: { key: 'list', label: 'List', icon: markRaw(Table), tab: TableTab },
    budgets: { key: 'budgets', label: 'Budgets', icon: markRaw(PiggyBank), tab: Budgets },
    tags: { key: 'tags', label: 'Tags', icon: markRaw(Tags), tab: TagDistribution },
    flow: { key: 'flow', label: 'Flow', icon: markRaw(ArrowLeftRight), tab: MoneyFlow },
    trend: { key: 'trend', label: 'Trend', icon: markRaw(TrendingUp), tab: BalanceTrend },
}
const viewGroups = [
    { id: 'overview', label: 'Overview', icon: markRaw(LayoutDashboard), items: [viewItems.analytics, viewItems.calendar, viewItems.list] },
    { id: 'plan', label: 'Plan', icon: markRaw(PiggyBank), items: [viewItems.budgets, viewItems.tags] },
    { id: 'charts', label: 'Charts', icon: markRaw(TrendingUp), items: [viewItems.flow, viewItems.trend] },
]
const LAST_TABS_KEY = 'ff:last-tabs'
const defaultLastTabs = { overview: 'analytics', plan: 'budgets', charts: 'flow' }
const readLastTabs = () => {
    try {
        return { ...defaultLastTabs, ...JSON.parse(sessionStorage.getItem(LAST_TABS_KEY) || '{}') }
    } catch {
        return { ...defaultLastTabs }
    }
}
const lastByGroup = ref(readLastTabs())
watch(lastByGroup, (value) => sessionStorage.setItem(LAST_TABS_KEY, JSON.stringify(value)), { deep: true })

const moreOpen = ref(false)

const currentKey = computed(() => {
    if (importRoute.value !== null) return 'import'
    const found = Object.values(viewItems).find((item) => item.tab === selectedTab.value)
    return found?.key || 'analytics'
})
const activeGroup = computed(() => {
    if (importRoute.value !== null) return ''
    const group = viewGroups.find((g) => g.items.some((item) => item.key === currentKey.value))
    return group?.id || 'overview'
})

const leaveImport = () => {
    if (importRoute.value === null) return
    importRoute.value = null
    if (window.location.pathname !== '/') {
        window.history.pushState({}, '', '/')
    }
}

const goView = (key) => {
    const item = viewItems[key]
    if (!item) return
    selectedTab.value = item.tab
    const group = viewGroups.find((g) => g.items.some((entry) => entry.key === key))
    if (group) lastByGroup.value[group.id] = key
    moreOpen.value = false
    leaveImport()
}

const selectGroup = (id) => {
    moreOpen.value = false
    const key = lastByGroup.value[id] || viewGroups.find((g) => g.id === id)?.items[0]?.key
    if (key) goView(key)
}

const goHome = () => {
    moreOpen.value = false
    leaveImport()
}

const openSettings = () => openSettingsTab()

const goImport = () => {
    moreOpen.value = false
    closeSettings()
    window.history.pushState({}, '', '/import')
    importRoute.value = ''
}

watch(currentKey, (key) => {
    const group = viewGroups.find((g) => g.items.some((item) => item.key === key))
    if (group) lastByGroup.value[group.id] = key
})



watch(selectedTab, () => haptic.select())

const periodTypes = [
    { value: 'week', label: 'Week', icon: markRaw(CalendarRange) },
    { value: 'month', label: 'Month', icon: markRaw(Calendar) },
    { value: 'year', label: 'Year', icon: markRaw(CalendarFold) },
]

// Switching Week / Month / Year keeps the period on screen: Month → Year stays in the viewed year,
// Year → Month opens today's month in the current year (January otherwise). The range is set here,
// not in a watcher on the type, so callers that pick a range themselves (handleJumpToMonth) keep it.
function setDateSelectionType(type) {
    if (dateSelectionType.value === type) return

    haptic.select()
    const { currentStart, currentEnd } = getDateRange.value
    const today = new Date()
    selectRange(type, today >= currentStart && today <= currentEnd ? today : currentStart)
}

function selectRange(type, date) {
    dateSelectionType.value = type
    selectedRange.value = rangeFor(type, date)
    nextTick(() => datePicker.value?.parseModel())
}

const tagFilterChips = computed(() => {
    const chips = tagsStore.tags
        .filter((tag) => transactionsStore.selectedTagIds.includes(tag.id))
        .map((tag) => ({
            key: tag.id,
            emoji: tag.emoji,
            title: tag.title,
            remove: () => {
                transactionsStore.selectedTagIds = transactionsStore.selectedTagIds.filter((id) => id !== tag.id)
            },
        }))

    if (transactionsStore.filterUntagged) {
        chips.unshift({
            key: 'untagged',
            emoji: '🏷️',
            title: 'Untagged',
            remove: () => {
                transactionsStore.filterUntagged = false
            },
        })
    }

    return chips
})

const tagFilterOpen = ref(false)

const tagFilterBadgeCount = computed(
    () => transactionsStore.selectedTagIds.length + (transactionsStore.filterUntagged ? 1 : 0),
)

watch(
    () => transactionsStore.tagFilterDraft,
    () => {
        transactionsStore.setTagFilterFromDraft()
    },
    { immediate: true },
)

const LOADER_DELAY_MS = 180
const loaderVisible = ref(false)
let loaderTimer = null

// The delay counts from mount (or from a later login), not from setup, so it measures what the user waits.
function armLoader(busy) {
    clearTimeout(loaderTimer)
    loaderVisible.value = false
    if (busy) loaderTimer = setTimeout(() => (loaderVisible.value = true), LOADER_DELAY_MS)
}

watch(isBootstrapping, armLoader)

onMounted(async () => {
    const onKeydown = (event) => {
        if (event.key === 'Escape') moreOpen.value = false
    }
    window.addEventListener('keydown', onKeydown)
    armLoader(isBootstrapping.value)
    await restoreSession()

    // No session after all (bad token, Telegram sign-in needed): show the app as signed out.
    if (!authStore.isAuthenticated) isBootstrapping.value = false
})

async function restoreSession() {
    if (isTelegramWebApp()) {
        telegramReady()
        installTelegramChrome()

        const initData = getInitData()

        if (initData) {
            isBootstrapping.value = true
            const result = await authStore.loginWithTelegramWebApp(initData)

            if (result === true) {
                return
            }

            if (result === 'needs_auth') {
                stashInitData(initData)
            }
        }
    }

    const ok = await authStore.checkAuth()

    if (ok && isTelegramWebApp()) {
        const initData = peekStashedInitData() || getInitData()

        if (initData) {
            await authStore.linkTelegramWebApp(initData)
            takeStashedInitData()
        }

        return
    }

    if (peekStashedInitData()) {
        showModal('auth_modal')
    }
}

watch(
    () => authStore.isAuthenticated,
    async (auth) => {
        if (auth) {
            resetCachedNotice()
            await bootstrapStores()

            syncOnboardingFromUser(authStore.user)
            if (shouldShowOnboarding(transactionsStore.transactions.length, authStore.user)) {
                // A brand-new account starts on the current version: no "What's new" for it.
                markWhatsNewSeen()
                showModal('onboarding_modal')
                reportOnboarding('welcome')
            } else if (hasUnseenWhatsNew.value) {
                showModal('whats_new_modal')
            }
        } else {
            isBootstrapping.value = false
            tagsStore.tags = []
            transactionsStore.transactions = []
            budgetsStore.budgets = []
            recurringTransactionsStore.rules = []
            agentStore.clearLocal()
        }
    },
)

function shiftPeriod(delta) {
    haptic.select()

    if (dateSelectionType.value === 'week') {
        const start = new Date(selectedRange.value[0])
        const end = new Date(selectedRange.value[1])
        start.setDate(start.getDate() + delta * 7)
        end.setDate(end.getDate() + delta * 7)
        selectedRange.value = [start, end]
    } else if (dateSelectionType.value === 'month') {
        const date = new Date(selectedRange.value.year, selectedRange.value.month + delta, 1)
        selectedRange.value = { month: date.getMonth(), year: date.getFullYear() }
    } else {
        selectedRange.value = selectedRange.value + delta
    }

    datePicker.value?.parseModel()
}
// Year view month card → that month of the viewed year.
function handleJumpToMonth(date) {
    haptic.select()
    selectRange('month', date)
}

// "Open in List" from a tab (Analytics) or a Flow bar ({ groupBy, date }): a week / month bar moves the
// date picker to that week / month; a day bar keeps the period and narrows the List to that day.
const listDay = ref(null)
provide('listDay', listDay)

function handleJumpToList(bar) {
    if (bar?.groupBy === 'week' || bar?.groupBy === 'month') {
        selectRange(bar.groupBy, parseLocalDate(bar.date))
    }
    selectedTab.value = markRaw(TableTab)
    listDay.value = bar?.groupBy === 'day' ? bar.date : null
}

// The day filter only lives while the List shows the period it was picked in.
watch([selectedRange, dateSelectionType], () => {
    listDay.value = null
})
watch(selectedTab, (tab) => {
    if (tab !== TableTab) listDay.value = null
})

function rangeFor(type, date = new Date()) {
    const anchor = new Date(date)

    if (type === 'week') {
        const day = anchor.getDay()
        const daysFromMonday = day === 0 ? 6 : day - 1
        const start = new Date(anchor)
        start.setDate(anchor.getDate() - daysFromMonday)
        start.setHours(0, 0, 0, 0)

        const end = new Date(start)
        end.setDate(start.getDate() + 6)
        end.setHours(23, 59, 59, 999)

        return [start, end]
    }

    if (type === 'month') {
        return { month: anchor.getMonth(), year: anchor.getFullYear() }
    }

    return anchor.getFullYear()
}

const getDateRange = computed(() => {
    let currentStart
    let currentEnd
    let previousStart
    let previousEnd

    switch (dateSelectionType.value) {
        case 'week': {
            currentStart = new Date(selectedRange.value[0])
            currentEnd = new Date(selectedRange.value[1])

            previousStart = new Date(currentStart)
            previousStart.setDate(currentStart.getDate() - 7)
            previousEnd = new Date(currentStart)
            previousEnd.setDate(currentStart.getDate() - 1)
            previousEnd.setHours(23, 59, 59, 999)
            break
        }

        case 'month': {
            const monthDate = new Date(selectedRange.value.year, selectedRange.value.month)

            currentStart = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1)
            currentEnd = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0, 23, 59, 59)

            previousStart = new Date(monthDate.getFullYear(), monthDate.getMonth() - 1, 1)
            previousEnd = new Date(monthDate.getFullYear(), monthDate.getMonth(), 0, 23, 59, 59)
            break
        }

        case 'year': {
            const year = selectedRange.value

            currentStart = new Date(year, 0, 1)
            currentEnd = new Date(year, 11, 31, 23, 59, 59)

            previousStart = new Date(year - 1, 0, 1)
            previousEnd = new Date(year - 1, 11, 31, 23, 59, 59)
            break
        }
    }

    return { currentStart, currentEnd, previousStart, previousEnd }
})
provide('insightDateRange', getDateRange)

const formatPeriodLabel = (monthStyle) => {
    const { currentStart: start, currentEnd: end } = getDateRange.value

    if (dateSelectionType.value === 'year') return String(start.getFullYear())
    if (dateSelectionType.value === 'month') {
        return start.toLocaleDateString('en-US', { month: monthStyle, year: 'numeric' })
    }

    const sameYear = start.getFullYear() === end.getFullYear()
    const sameMonth = sameYear && start.getMonth() === end.getMonth()
    const month = (d) => d.toLocaleDateString('en-US', { month: 'short' })

    if (sameMonth) return `${month(start)} ${start.getDate()}–${end.getDate()}, ${end.getFullYear()}`
    if (sameYear) return `${month(start)} ${start.getDate()} – ${month(end)} ${end.getDate()}, ${end.getFullYear()}`

    return `${month(start)} ${start.getDate()}, ${start.getFullYear()} – ${month(end)} ${end.getDate()}, ${end.getFullYear()}`
}
const periodLabel = computed(() => formatPeriodLabel('long'))
const periodLabelShort = computed(() => formatPeriodLabel('short'))
provide('insightDateSelectionType', dateSelectionType)

// Screen context for the assistant drawer: which tab, period and tag filter the user is looking at.
const AGENT_TAB_KEYS = new Map([
    [Analytics, 'analytics'],
    [CalendarTab, 'calendar'],
    [Budgets, 'budgets'],
    [TagDistribution, 'tags'],
    [MoneyFlow, 'money_flow'],
    [BalanceTrend, 'balance_trend'],
    [TableTab, 'table'],
])
const ymd = (date) => {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) return null
    const pad = (n) => String(n).padStart(2, '0')
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}
const agentScreenContext = computed(() => {
    const { currentStart, currentEnd } = getDateRange.value
    const from = ymd(currentStart)
    const to = ymd(currentEnd)
    const tagIds = (transactionsStore.selectedTagIds ?? []).filter((id) => Number.isInteger(id)).slice(0, 50)

    return {
        tab: AGENT_TAB_KEYS.get(selectedTab.value) ?? null,
        period_type: dateSelectionType.value,
        ...(from && to ? { from, to } : {}),
        ...(tagIds.length ? { tag_ids: tagIds } : {}),
        ...(transactionsStore.filterUntagged ? { untagged: true } : {}),
    }
})
watch(agentScreenContext, (context) => agentStore.setScreenContext(context), { immediate: true })
</script>

<style scoped>
.tabs-box {
    box-shadow: none;
}

.tab {
    border: var(--border) solid var(--color-base-300) !important;
    border-radius: var(--radius-field) !important;
    box-shadow: none;
}
</style>
