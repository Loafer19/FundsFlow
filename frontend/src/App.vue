<template>
    <Toasts />
    <AuthModal />
    <TagsModal />
    <TagsAddModal />
    <TagsEditModal />
    <TransactionsAddModal />
    <TransactionsEditModal />
    <BudgetAddModal />
    <BudgetEditModal />
    <SettingsModal />

    <div class="container mx-auto p-4">
        <div class="flex gap-2 justify-end mb-4">
            <template v-if="authStore.isAuthenticated">
                <button type="button" onclick="transactions_add_modal.showModal()" class="btn btn-primary">
                    <Plus :size="20" />
                    Add Transaction
                </button>

                <button onclick="tags_modal.showModal()"
                    class="btn btn-outline btn-info btn-square tooltip tooltip-bottom" data-tip="Tags"
                    aria-label="Tags">
                    <Tag :size="24" />
                </button>

                <button onclick="settings_modal.showModal()"
                    class="btn btn-outline btn-warning btn-square tooltip tooltip-bottom" data-tip="Settings"
                    aria-label="Settings">
                    <Settings :size="24" />
                </button>

                <button @click="authStore.logout" class="btn btn-outline btn-error btn-square tooltip tooltip-bottom"
                    data-tip="Logout" aria-label="Logout" :disabled="authStore.isLoading">
                    <span v-if="authStore.isLoading" class="loading loading-spinner"></span>
                    <LogOut v-else :size="24" />
                </button>
            </template>

            <button v-else onclick="auth_modal.showModal()" class="btn btn-secondary">
                <LogIn :size="24" />
                Login
            </button>
        </div>

        <div class="flex gap-2 mb-3 items-stretch">
            <select class="select w-30 border-base-300 focus:border-base-content cursor-pointer"
                v-model="dateSelectionType">
                <option value="week">Week</option>
                <option value="month">Month</option>
                <option value="year">Year</option>
            </select>

            <button type="button" class="btn btn-square border-base-300" @click="shiftPeriod(-1)"
                aria-label="Previous period">
                <ChevronLeft :size="20" />
            </button>

            <div class="w-40">
                <VueDatePicker v-model="selectedRange" hide-offset-dates :enable-time-picker="false" auto-apply
                    :transitions="false" :week-picker="dateSelectionType === 'week'"
                    :month-picker="dateSelectionType === 'month'" :year-picker="dateSelectionType === 'year'"
                    :week-numbers="{ type: 'local' }" :min-date="new Date('2000-01-05')" :clearable="false"
                    prevent-min-max-navigation :year-range="[2020, 2040]" :hide-input-icon="true" ref="datePicker">
                </VueDatePicker>
            </div>

            <button type="button" class="btn btn-square border-base-300" @click="shiftPeriod(1)"
                aria-label="Next period">
                <ChevronRight :size="20" />
            </button>
        </div>

        <div class="mb-4">
            <div class="tabs tabs-box gap-2 p-0">
                <label class="tab gap-1 text-lg font-medium hover:text-info">
                    <input v-model="selectedTab" type="radio" name="tabs_main" class="tab" checked="checked"
                        :value="markRaw(Analytics)" />

                    <Presentation :size="24" />

                    Analytics
                </label>

                <label class="tab gap-1 text-lg font-medium hover:text-info">
                    <input v-model="selectedTab" type="radio" name="tabs_main" class="tab"
                        :value="markRaw(Budgets)" />

                    <PiggyBank :size="24" />

                    Budgets
                </label>

                <label class="tab gap-1 text-lg font-medium hover:text-info">
                    <input v-model="selectedTab" type="radio" name="tabs_main" class="tab"
                        :value="markRaw(BalanceTrend)" />

                    <TrendingUp :size="24" />

                    Trend
                </label>

                <label class="tab gap-1 text-lg font-medium hover:text-info">
                    <input v-model="selectedTab" type="radio" name="tabs_main" class="tab"
                        :value="markRaw(MoneyFlow)" />

                    <ArrowLeftRight :size="24" />

                    Flow
                </label>

                <label class="tab gap-1 text-lg font-medium hover:text-info">
                    <input v-model="selectedTab" type="radio" name="tabs_main" class="tab" :value="markRaw(TableTab)" />

                    <Table :size="24" />

                    List
                </label>

                <label class="tab gap-1 text-lg font-medium hover:text-info">
                    <input v-model="selectedTab" type="radio" name="tabs_main" class="tab"
                        :value="markRaw(TagDistribution)" />

                    <Tags :size="24" />

                    Tags
                </label>

            </div>
        </div>

        <component :is="selectedTab" :dateRange="getDateRange" :dateSelectionType />
    </div>

    <button v-if="authStore.isAuthenticated" type="button"
        class="btn btn-primary btn-circle btn-lg fixed bottom-6 right-6 z-50"
        onclick="transactions_add_modal.showModal()" aria-label="Add Transaction">
        <Plus :size="28" />
    </button>
</template>

<script setup>
import VueDatePicker from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'
import { ArrowLeftRight, ChevronLeft, ChevronRight, LogIn, LogOut, PiggyBank, Plus, Presentation, Settings, Table, Tag, Tags, TrendingUp } from 'lucide-vue-next'
import { computed, markRaw, onMounted, ref, watch } from 'vue'
import Toasts from './components/Toasts.vue'
import AuthModal from './modals/AuthModal.vue'
import BudgetAddModal from './modals/BudgetAddModal.vue'
import BudgetEditModal from './modals/BudgetEditModal.vue'
import SettingsModal from './modals/SettingsModal.vue'
import TagsAddModal from './modals/TagsAddModal.vue'
import TagsEditModal from './modals/TagsEditModal.vue'
import TagsModal from './modals/TagsModal.vue'
import TransactionsAddModal from './modals/TransactionsAddModal.vue'
import TransactionsEditModal from './modals/TransactionsEditModal.vue'
import { useAuthStore } from './services/auth.js'
import { useBudgetsStore } from './services/budgets.js'
import { useTagsStore } from './services/tags.js'
import { useTransactionsStore } from './services/transactions.js'
import Analytics from './tabs/Analytics.vue'
import BalanceTrend from './tabs/BalanceTrend.vue'
import Budgets from './tabs/Budgets.vue'
import MoneyFlow from './tabs/MoneyFlow.vue'
import TableTab from './tabs/TableTab.vue'
import TagDistribution from './tabs/TagDistribution.vue'

const authStore = useAuthStore()
const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()
const budgetsStore = useBudgetsStore()

const dateSelectionType = ref('month')
const datePicker = ref(null)
const selectedRange = ref(getDefaultRange('month'))
const selectedTab = ref(markRaw(Analytics))

onMounted(() => authStore.checkAuth())

watch(
    () => authStore.isAuthenticated,
    (auth) => {
        if (auth) {
            tagsStore.load()
            transactionsStore.load()
            budgetsStore.load()
        } else {
            tagsStore.tags = []
            transactionsStore.transactions = []
            budgetsStore.budgets = []
        }
    },
)

watch(
    () => dateSelectionType.value,
    (type) => {
        selectedRange.value = getDefaultRange(type)
        datePicker.value.parseModel()
    },
)

function shiftPeriod(delta) {
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
function getDefaultRange(type) {
    const today = new Date()

    if (type === 'week') {
        const day = today.getDay()
        const daysFromMonday = day === 0 ? 6 : day - 1
        const start = new Date(today)
        start.setDate(today.getDate() - daysFromMonday)
        start.setHours(0, 0, 0, 0)

        const end = new Date(start)
        end.setDate(start.getDate() + 6)
        end.setHours(23, 59, 59, 999)

        return [start, end]
    }

    if (type === 'month') {
        return { month: today.getMonth(), year: today.getFullYear() }
    }

    return today.getFullYear()
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
