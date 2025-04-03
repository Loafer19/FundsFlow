<template>
    <Toasts />
    <AuthModal />
    <TagsModal />
    <TransactionsModal :tags="tagsStore.tags" />

    <div class="container mx-auto p-4">
        <div class="flex gap-2 justify-end mb-4">
            <template v-if="authStore.isAuthenticated">
                <button onclick="transactions_modal.showModal()" class="btn btn-primary">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M7.94513 4.25H13.0549C14.4225 4.24998 15.5248 4.24996 16.3918 4.36652C17.2919 4.48754 18.0497 4.74643 18.6517 5.34835C19.2061 5.90277 19.4695 6.58951 19.6022 7.39783C20.4106 7.53047 21.0974 7.79391 21.6519 8.3484C22.2538 8.95032 22.5127 9.70819 22.6337 10.6083C22.7503 11.4753 22.7503 12.5776 22.7503 13.9452V14.0549C22.7503 15.4225 22.7503 16.5248 22.6337 17.3918C22.5127 18.2919 22.2538 19.0498 21.6519 19.6517C21.05 20.2536 20.2921 20.5125 19.392 20.6335C18.525 20.7501 17.4227 20.7501 16.0551 20.7501H10.9454C9.57779 20.7501 8.47547 20.7501 7.6085 20.6335C6.7084 20.5125 5.95052 20.2536 5.3486 19.6517C4.79417 19.0973 4.53072 18.4105 4.39807 17.6022C3.58964 17.4695 2.90283 17.2061 2.34835 16.6517C1.74643 16.0497 1.48754 15.2919 1.36652 14.3918C1.24996 13.5248 1.24998 12.4225 1.25 11.0549V10.9451C1.24998 9.57754 1.24996 8.47522 1.36652 7.60825C1.48754 6.70814 1.74643 5.95027 2.34835 5.34835C2.95027 4.74643 3.70814 4.48754 4.60825 4.36652C5.47522 4.24996 6.57754 4.24998 7.94513 4.25ZM5.95571 17.7316C6.0606 18.1345 6.20999 18.3918 6.40926 18.591C6.68603 18.8678 7.0746 19.0483 7.80837 19.1469C8.56373 19.2485 9.56484 19.2501 11.0003 19.2501H16.0003C17.4357 19.2501 18.4368 19.2485 19.1921 19.1469C19.9259 19.0483 20.3145 18.8678 20.5912 18.591C20.868 18.3143 21.0485 17.9257 21.1471 17.1919C21.2487 16.4366 21.2503 15.4355 21.2503 14.0001C21.2503 12.5646 21.2487 11.5635 21.1471 10.8082C21.0485 10.0744 20.868 9.68583 20.5912 9.40906C20.3919 9.20976 20.1346 9.06034 19.7316 8.95545C19.75 9.54434 19.75 10.206 19.75 10.9451V11.0549C19.75 12.4225 19.75 13.5248 19.6335 14.3918C19.5125 15.2919 19.2536 16.0497 18.6517 16.6517C18.0497 17.2536 17.2919 17.5125 16.3918 17.6335C15.5248 17.75 14.4225 17.75 13.0549 17.75H7.94513C7.2061 17.75 6.54453 17.75 5.95571 17.7316ZM4.80812 5.85315C4.07435 5.9518 3.68577 6.13225 3.40901 6.40901C3.13225 6.68577 2.9518 7.07435 2.85315 7.80812C2.75159 8.56347 2.75 9.56459 2.75 11C2.75 12.4354 2.75159 13.4365 2.85315 14.1919C2.9518 14.9257 3.13225 15.3142 3.40901 15.591C3.68577 15.8678 4.07435 16.0482 4.80812 16.1469C5.56347 16.2484 6.56458 16.25 8 16.25H13C14.4354 16.25 15.4365 16.2484 16.1919 16.1469C16.9257 16.0482 17.3142 15.8678 17.591 15.591C17.8678 15.3142 18.0482 14.9257 18.1469 14.1919C18.2484 13.4365 18.25 12.4354 18.25 11C18.25 9.56459 18.2484 8.56347 18.1469 7.80812C18.0482 7.07435 17.8678 6.68577 17.591 6.40901C17.3142 6.13225 16.9257 5.9518 16.1919 5.85315C15.4365 5.75159 14.4354 5.75 13 5.75H8C6.56458 5.75 5.56347 5.75159 4.80812 5.85315ZM10.5 9.25C9.5335 9.25 8.75 10.0335 8.75 11C8.75 11.9665 9.5335 12.75 10.5 12.75C11.4665 12.75 12.25 11.9665 12.25 11C12.25 10.0335 11.4665 9.25 10.5 9.25ZM7.25 11C7.25 9.20508 8.70508 7.75 10.5 7.75C12.2949 7.75 13.75 9.20508 13.75 11C13.75 12.7949 12.2949 14.25 10.5 14.25C8.70508 14.25 7.25 12.7949 7.25 11ZM5 8.25C5.41421 8.25 5.75 8.58579 5.75 9V13C5.75 13.4142 5.41421 13.75 5 13.75C4.58579 13.75 4.25 13.4142 4.25 13L4.25 9C4.25 8.58579 4.58579 8.25 5 8.25ZM16 8.25C16.4142 8.25 16.75 8.58579 16.75 9V13C16.75 13.4142 16.4142 13.75 16 13.75C15.5858 13.75 15.25 13.4142 15.25 13V9C15.25 8.58579 15.5858 8.25 16 8.25Z" />
                    </svg>
                    Add Transaction
                </button>

                <button onclick="tags_modal.showModal()" class="btn btn-info btn-square tooltip tooltip-bottom"
                    data-tip="Tags">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M11.2383 2.79888C10.6243 2.88003 9.86602 3.0542 8.7874 3.30311L7.55922 3.58654C6.6482 3.79677 6.02082 3.94252 5.54162 4.10698C5.07899 4.26576 4.81727 4.42228 4.61978 4.61978C4.42228 4.81727 4.26576 5.07899 4.10698 5.54162C3.94252 6.02082 3.79677 6.6482 3.58654 7.55922L3.30311 8.7874C3.0542 9.86602 2.88003 10.6243 2.79888 11.2383C2.71982 11.8365 2.73805 12.2413 2.84358 12.6092C2.94911 12.9772 3.14817 13.3301 3.53226 13.7954C3.92651 14.2731 4.47607 14.8238 5.25882 15.6066L7.08845 17.4362C8.44794 18.7957 9.41533 19.7608 10.247 20.3954C11.0614 21.0167 11.6569 21.25 12.2623 21.25C12.8678 21.25 13.4633 21.0167 14.2776 20.3954C15.1093 19.7608 16.0767 18.7957 17.4362 17.4362C18.7957 16.0767 19.7608 15.1093 20.3954 14.2776C21.0167 13.4633 21.25 12.8678 21.25 12.2623C21.25 11.6569 21.0167 11.0614 20.3954 10.247C19.7608 9.41533 18.7957 8.44794 17.4362 7.08845L15.6066 5.25882C14.8238 4.47607 14.2731 3.92651 13.7954 3.53226C13.3301 3.14817 12.9772 2.94911 12.6092 2.84358C12.2413 2.73805 11.8365 2.71982 11.2383 2.79888ZM11.0418 1.31181C11.7591 1.21701 12.3881 1.21969 13.0227 1.4017C13.6574 1.58372 14.1922 1.91482 14.7502 2.37538C15.2897 2.82061 15.8905 3.4214 16.641 4.17197L18.5368 6.06774C19.8474 7.37835 20.8851 8.41598 21.5879 9.33714C22.311 10.2849 22.75 11.197 22.75 12.2623C22.75 13.3276 22.311 14.2397 21.5879 15.1875C20.8851 16.1087 19.8474 17.1463 18.5368 18.4569L18.4569 18.5368C17.1463 19.8474 16.1087 20.8851 15.1875 21.5879C14.2397 22.311 13.3276 22.75 12.2623 22.75C11.197 22.75 10.2849 22.311 9.33714 21.5879C8.41598 20.8851 7.37833 19.8474 6.06771 18.5368L4.17196 16.641C3.4214 15.8905 2.82061 15.2897 2.37538 14.7502C1.91482 14.1922 1.58372 13.6574 1.4017 13.0227C1.21969 12.3881 1.21701 11.7591 1.31181 11.0418C1.40345 10.3484 1.59451 9.52048 1.83319 8.48622L2.13385 7.18334C2.33302 6.32023 2.49543 5.61639 2.68821 5.05469C2.88955 4.46806 3.14313 3.9751 3.55912 3.55912C3.9751 3.14313 4.46806 2.88955 5.05469 2.68821C5.61639 2.49543 6.32023 2.33302 7.18335 2.13385L8.48622 1.83319C9.52047 1.59451 10.3484 1.40345 11.0418 1.31181ZM9.49094 7.99514C9.00278 7.50699 8.21133 7.50699 7.72317 7.99514C7.23502 8.4833 7.23502 9.27476 7.72317 9.76291C8.21133 10.2511 9.00278 10.2511 9.49094 9.76291C9.97909 9.27476 9.97909 8.4833 9.49094 7.99514ZM6.66251 6.93448C7.73645 5.86054 9.47766 5.86054 10.5516 6.93448C11.6255 8.00843 11.6255 9.74963 10.5516 10.8236C9.47766 11.8975 7.73645 11.8975 6.66251 10.8236C5.58857 9.74963 5.58857 8.00843 6.66251 6.93448ZM19.0511 10.9902C19.344 11.2831 19.344 11.7579 19.0511 12.0508L12.0721 19.0301C11.7792 19.323 11.3043 19.323 11.0114 19.0301C10.7185 18.7372 10.7185 18.2623 11.0114 17.9694L17.9904 10.9902C18.2833 10.6973 18.7582 10.6973 19.0511 10.9902Z" />
                    </svg>
                </button>

                <button @click="authStore.logout" class="btn btn-error btn-square tooltip tooltip-bottom"
                    data-tip="Logout" :disabled="authStore.isLoading">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M12.75 11C12.75 10.5858 12.4142 10.25 12 10.25C11.5858 10.25 11.25 10.5858 11.25 11V13C11.25 13.4142 11.5858 13.75 12 13.75C12.4142 13.75 12.75 13.4142 12.75 13V11Z" />
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M13.7247 2.02709L16.1585 2.43272C17.3143 2.62533 18.2506 2.78137 18.9831 2.99943C19.7459 3.22653 20.3761 3.54467 20.8613 4.11746C21.3465 4.69024 21.5568 5.36421 21.6554 6.15397C21.75 6.91231 21.75 7.86155 21.75 9.03325V14.9667C21.75 16.1384 21.75 17.0877 21.6554 17.846C21.5568 18.6358 21.3465 19.3097 20.8613 19.8825C20.3761 20.4553 19.7459 20.7734 18.9831 21.0005C18.2506 21.2186 17.3143 21.3746 16.1586 21.5672L13.7247 21.9729C12.6915 22.1451 11.8373 22.2875 11.155 22.304C10.4394 22.3213 9.77599 22.2063 9.22247 21.7374C8.75523 21.3416 8.52385 20.827 8.40256 20.25H7.94632C6.8135 20.25 5.88774 20.25 5.15689 20.1518C4.39294 20.0491 3.7306 19.8268 3.20191 19.2981C2.67321 18.7694 2.45093 18.1071 2.34822 17.3431C2.24996 16.6123 2.24998 15.6865 2.25 14.5537V9.4463C2.24998 8.31348 2.24996 7.38773 2.34822 6.65688C2.45093 5.89293 2.67321 5.23059 3.20191 4.7019C3.7306 4.1732 4.39294 3.95092 5.15689 3.84821C5.88775 3.74995 6.81348 3.74997 7.94631 3.74999L8.40256 3.74999C8.52384 3.17295 8.75523 2.65841 9.22247 2.2626C9.77599 1.7937 10.4394 1.67869 11.155 1.69597C11.8373 1.71246 12.6916 1.85487 13.7247 2.02709ZM8.25 17.3351C8.24999 17.8511 8.24997 18.3231 8.26143 18.75H8C6.80029 18.75 5.97595 18.7484 5.35676 18.6652C4.75914 18.5848 4.46611 18.441 4.26257 18.2374C4.05903 18.0339 3.91519 17.7409 3.83484 17.1432C3.7516 16.524 3.75 15.6997 3.75 14.5V9.49999C3.75 8.30028 3.7516 7.47594 3.83484 6.85675C3.91519 6.25913 4.05903 5.9661 4.26257 5.76256C4.46611 5.55902 4.75914 5.41518 5.35676 5.33483C5.97595 5.25159 6.80029 5.24999 8 5.24999H8.26143C8.24997 5.6769 8.24999 6.14889 8.25 6.6649V17.3351ZM11.1188 3.19554C10.5765 3.18243 10.3458 3.2769 10.192 3.40713C10.0383 3.53736 9.90719 3.7494 9.83097 4.28646C9.75179 4.84427 9.75 5.60296 9.75 6.72183V17.2781C9.75 18.397 9.75179 19.1557 9.83097 19.7135C9.90719 20.2506 10.0383 20.4626 10.192 20.5928C10.3458 20.7231 10.5765 20.8175 11.1188 20.8044C11.682 20.7908 12.4307 20.6679 13.5343 20.4839L15.8631 20.0958C17.0793 19.8931 17.9228 19.7511 18.5551 19.5629C19.1672 19.3806 19.4911 19.1794 19.7168 18.9129C19.9425 18.6465 20.0878 18.294 20.1669 17.6602C20.2486 17.0056 20.25 16.1502 20.25 14.9172V9.08275C20.25 7.84973 20.2486 6.99439 20.1669 6.33981C20.0878 5.706 19.9425 5.35347 19.7168 5.08702C19.4911 4.82057 19.1672 4.61933 18.5551 4.43707C17.9228 4.24885 17.0793 4.10688 15.8631 3.90418L13.5343 3.51604C12.4307 3.33211 11.682 3.20914 11.1188 3.19554Z" />
                    </svg>
                </button>
            </template>

            <button v-else onclick="auth_modal.showModal()" class="btn btn-secondary">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd"
                        d="M12 1.25C9.37666 1.25 7.25001 3.37665 7.25001 6C7.25001 8.62335 9.37666 10.75 12 10.75C14.6234 10.75 16.75 8.62335 16.75 6C16.75 3.37665 14.6234 1.25 12 1.25ZM8.75001 6C8.75001 4.20507 10.2051 2.75 12 2.75C13.7949 2.75 15.25 4.20507 15.25 6C15.25 7.79493 13.7949 9.25 12 9.25C10.2051 9.25 8.75001 7.79493 8.75001 6Z" />
                    <path fill-rule="evenodd" clip-rule="evenodd"
                        d="M12 12.25C9.68646 12.25 7.55494 12.7759 5.97546 13.6643C4.4195 14.5396 3.25001 15.8661 3.25001 17.5L3.24995 17.602C3.24882 18.7638 3.2474 20.222 4.52642 21.2635C5.15589 21.7761 6.03649 22.1406 7.22622 22.3815C8.41927 22.6229 9.97424 22.75 12 22.75C14.0258 22.75 15.5808 22.6229 16.7738 22.3815C17.9635 22.1406 18.8441 21.7761 19.4736 21.2635C20.7526 20.222 20.7512 18.7638 20.7501 17.602L20.75 17.5C20.75 15.8661 19.5805 14.5396 18.0246 13.6643C16.4451 12.7759 14.3136 12.25 12 12.25ZM4.75001 17.5C4.75001 16.6487 5.37139 15.7251 6.71085 14.9717C8.02681 14.2315 9.89529 13.75 12 13.75C14.1047 13.75 15.9732 14.2315 17.2892 14.9717C18.6286 15.7251 19.25 16.6487 19.25 17.5C19.25 18.8078 19.2097 19.544 18.5264 20.1004C18.1559 20.4022 17.5365 20.6967 16.4762 20.9113C15.4193 21.1252 13.9742 21.25 12 21.25C10.0258 21.25 8.58075 21.1252 7.5238 20.9113C6.46354 20.6967 5.84413 20.4022 5.4736 20.1004C4.79033 19.544 4.75001 18.8078 4.75001 17.5Z" />
                </svg>
                Login
            </button>
        </div>

        <div class="flex gap-2 mb-4">
            <select class="select w-30 border-base-300 cursor-pointer" v-model="dateSelectionType">
                <option value="week">Week</option>
                <option value="month">Month</option>
                <option value="year">Year</option>
            </select>

            <div class="w-40">
                <VueDatePicker v-model="selectedRange" hide-offset-dates :enable-time-picker="false" auto-apply
                    :transitions="false" :week-picker="dateSelectionType === 'week'"
                    :month-picker="dateSelectionType === 'month'" :year-picker="dateSelectionType === 'year'"
                    :week-numbers="{ type: 'local' }" :min-date="new Date('2000-01-05')" :clearable="false"
                    prevent-min-max-navigation :year-range="[2020, 2040]" :hide-input-icon="true" ref="datePicker">
                </VueDatePicker>
            </div>
        </div>

        <div class="mb-4">
            <div class="tabs tabs-box gap-2 px-0">
                <input v-model="selectedTab" type="radio" name="tabs_main" class="tab" aria-label="Analytics"
                    checked="checked" :value="markRaw(Analytics)" />

                <input v-model="selectedTab" type="radio" name="tabs_main" class="tab" aria-label="Balance Trend"
                    :value="markRaw(BalanceTrend)" />

                <input v-model="selectedTab" type="radio" name="tabs_main" class="tab" aria-label="Money Flow"
                    :value="markRaw(MoneyFlow)" />

                <input v-model="selectedTab" type="radio" name="tabs_main" class="tab" aria-label="Table"
                    :value="markRaw(TableTab)" />

                <input v-model="selectedTab" type="radio" name="tabs_main" class="tab" aria-label="Tag Distribution"
                    :value="markRaw(TagDistribution)" />
            </div>
        </div>

        <component :is="selectedTab" :dateRange="getDateRange" :transactions="transactionsStore.transactions" />
    </div>
</template>

<script setup>
import VueDatePicker from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'
import { computed, markRaw, onMounted, ref, watch } from 'vue'
import Toasts from './components/Toasts.vue'
import AuthModal from './modals/AuthModal.vue'
import TagsModal from './modals/TagsModal.vue'
import TransactionsModal from './modals/TransactionsModal.vue'
import { useAuthStore } from './services/auth.js'
import { useTagsStore } from './services/tags.js'
import { useTransactionsStore } from './services/transactions.js'
import Analytics from './tabs/Analytics.vue'
import BalanceTrend from './tabs/BalanceTrend.vue'
import MoneyFlow from './tabs/MoneyFlow.vue'
import TableTab from './tabs/TableTab.vue'
import TagDistribution from './tabs/TagDistribution.vue'

const authStore = useAuthStore()
const tagsStore = useTagsStore()
const transactionsStore = useTransactionsStore()

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

function getDefaultRange(type) {
    const today = new Date()

    if (type === 'week') {
        const start = new Date(today)
        start.setDate(today.getDate() - today.getDay() + 1)

        const end = new Date(start)
        end.setDate(start.getDate() + 6)

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
.tab {
    border: var(--border) solid var(--color-base-300) !important;
    border-radius: var(--radius-field) !important;
}
</style>
