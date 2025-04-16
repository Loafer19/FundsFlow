<template>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <h2 class="card-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg" class="text-primary">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M7.09872 1.25004C7.14677 1.25006 7.19553 1.25008 7.24502 1.25008H16.755C16.8045 1.25008 16.8532 1.25006 16.9013 1.25004C17.918 1.2496 18.6178 1.24929 19.2071 1.45435C20.3201 1.84161 21.1842 2.73726 21.5546 3.86559L20.8421 4.09954L21.5546 3.86559C21.7507 4.46271 21.7504 5.17254 21.75 6.22655C21.75 6.27372 21.75 6.32158 21.75 6.37014V20.3743C21.75 21.8395 20.023 22.7118 18.8856 21.671C18.8061 21.5983 18.6939 21.5983 18.6144 21.671L18.1313 22.1131C17.2031 22.9624 15.7969 22.9624 14.8687 22.1131C14.5137 21.7882 13.9863 21.7882 13.6313 22.1131C12.7031 22.9624 11.2969 22.9624 10.3687 22.1131C10.0137 21.7882 9.48631 21.7882 9.13132 22.1131C8.20313 22.9624 6.79688 22.9624 5.86869 22.1131L5.3856 21.671C5.30612 21.5983 5.19389 21.5983 5.11442 21.671C3.97699 22.7118 2.25001 21.8395 2.25001 20.3743V6.37014C2.25001 6.32158 2.24999 6.27372 2.24997 6.22655C2.24959 5.17255 2.24933 4.46271 2.44539 3.86559C2.81585 2.73726 3.67996 1.84161 4.79292 1.45435C5.38224 1.24929 6.08197 1.2496 7.09872 1.25004ZM7.24502 2.75008C6.02394 2.75008 5.60334 2.76057 5.28587 2.87103C4.62649 3.10047 4.09913 3.63728 3.87054 4.3335C3.75945 4.67183 3.75001 5.11796 3.75001 6.37014V20.3743C3.75001 20.4933 3.80993 20.5661 3.88511 20.6009C3.92427 20.619 3.96258 20.6237 3.99449 20.6194C4.02263 20.6156 4.05904 20.6035 4.10179 20.5644C4.75447 19.9671 5.74555 19.9671 6.39822 20.5644L6.88132 21.0065C7.23631 21.3313 7.76371 21.3313 8.11869 21.0065C9.04688 20.1571 10.4531 20.1571 11.3813 21.0065C11.7363 21.3313 12.2637 21.3313 12.6187 21.0065C13.5469 20.1571 14.9531 20.1571 15.8813 21.0065C16.2363 21.3313 16.7637 21.3313 17.1187 21.0065L17.6018 20.5644C18.2545 19.9671 19.2455 19.9671 19.8982 20.5644C19.941 20.6035 19.9774 20.6156 20.0055 20.6194C20.0374 20.6237 20.0757 20.619 20.1149 20.6009C20.1901 20.5661 20.25 20.4934 20.25 20.3743V6.37014C20.25 5.11797 20.2406 4.67183 20.1295 4.3335C19.9009 3.63728 19.3735 3.10047 18.7141 2.87103C18.3967 2.76057 17.9761 2.75008 16.755 2.75008H7.24502ZM6.25001 7.50008C6.25001 7.08587 6.58579 6.75008 7.00001 6.75008H7.50001C7.91422 6.75008 8.25001 7.08587 8.25001 7.50008C8.25001 7.9143 7.91422 8.25008 7.50001 8.25008H7.00001C6.58579 8.25008 6.25001 7.9143 6.25001 7.50008ZM9.75001 7.50008C9.75001 7.08587 10.0858 6.75008 10.5 6.75008H17C17.4142 6.75008 17.75 7.08587 17.75 7.50008C17.75 7.9143 17.4142 8.25008 17 8.25008H10.5C10.0858 8.25008 9.75001 7.9143 9.75001 7.50008ZM6.25001 11.0001C6.25001 10.5859 6.58579 10.2501 7.00001 10.2501H7.50001C7.91422 10.2501 8.25001 10.5859 8.25001 11.0001C8.25001 11.4143 7.91422 11.7501 7.50001 11.7501H7.00001C6.58579 11.7501 6.25001 11.4143 6.25001 11.0001ZM9.75001 11.0001C9.75001 10.5859 10.0858 10.2501 10.5 10.2501H17C17.4142 10.2501 17.75 10.5859 17.75 11.0001C17.75 11.4143 17.4142 11.7501 17 11.7501H10.5C10.0858 11.7501 9.75001 11.4143 9.75001 11.0001ZM6.25001 14.5001C6.25001 14.0859 6.58579 13.7501 7.00001 13.7501H7.50001C7.91422 13.7501 8.25001 14.0859 8.25001 14.5001C8.25001 14.9143 7.91422 15.2501 7.50001 15.2501H7.00001C6.58579 15.2501 6.25001 14.9143 6.25001 14.5001ZM9.75001 14.5001C9.75001 14.0859 10.0858 13.7501 10.5 13.7501H17C17.4142 13.7501 17.75 14.0859 17.75 14.5001C17.75 14.9143 17.4142 15.2501 17 15.2501H10.5C10.0858 15.2501 9.75001 14.9143 9.75001 14.5001Z" />
                    </svg>
                    Total Transactions
                </h2>
                <div class="flex items-center space-x-2">
                    <p class="text-2xl">{{ filteredTransactions.length }}</p>
                    <div class="tooltip tooltip-left"
                         :data-tip="`Previous period: ${previousTransactions.length}`">
                        <div class="badge badge-outline badge-secondary">
                            {{ formatPercentage(calculatePercentageDiff(filteredTransactions.length, previousTransactions.length)) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <h2 class="card-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg" class="text-info">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M20.9235 11.7502C20.9032 11.75 20.8766 11.75 20.8333 11.75H18.2308C16.8074 11.75 15.75 12.8087 15.75 14C15.75 15.1913 16.8074 16.25 18.2308 16.25H20.8333C20.8766 16.25 20.9032 16.25 20.9235 16.2498C20.9427 16.2496 20.948 16.2492 20.948 16.2492C21.154 16.2367 21.2427 16.0976 21.2495 16.0139C21.2495 16.0139 21.2497 16.0077 21.2498 15.9986C21.25 15.9808 21.25 15.9572 21.25 15.9167V12.0833C21.25 12.0609 21.25 12.0437 21.25 12.0297C21.2499 12.0185 21.2499 12.0093 21.2498 12.0014C21.2497 11.9924 21.2495 11.9861 21.2495 11.9861C21.2427 11.9024 21.154 11.7633 20.9479 11.7508C20.9479 11.7508 20.943 11.7504 20.9235 11.7502ZM20.8499 10.25C20.9163 10.25 20.9803 10.2499 21.0391 10.2535C21.9104 10.3066 22.681 10.9638 22.7458 11.8818C22.7501 11.942 22.75 12.0069 22.75 12.067C22.75 12.0725 22.75 12.0779 22.75 12.0833V15.9167C22.75 15.9221 22.75 15.9275 22.75 15.933C22.75 15.9931 22.7501 16.058 22.7458 16.1182C22.681 17.0362 21.9104 17.6934 21.0391 17.7465C20.9803 17.7501 20.9163 17.75 20.8499 17.75C20.8444 17.75 20.8389 17.75 20.8333 17.75H18.2308C16.0856 17.75 14.25 16.1224 14.25 14C14.25 11.8776 16.0856 10.25 18.2308 10.25H20.8333C20.8389 10.25 20.8444 10.25 20.8499 10.25Z" />
                        <path
                            d="M19 14C19 14.5523 18.5523 15 18 15C17.4477 15 17 14.5523 17 14C17 13.4477 17.4477 13 18 13C18.5523 13 19 13.4477 19 14Z" />
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M20.8499 10.25C20.9163 10.25 20.9803 10.2499 21.0391 10.2535C21.2645 10.2672 21.4832 10.3214 21.6847 10.4101C21.5777 8.80363 21.2831 7.56563 20.3588 6.64124C19.6104 5.89288 18.6614 5.56076 17.489 5.40313L17.4467 5.39754C17.4362 5.38977 17.4255 5.38223 17.4145 5.37492L13.679 2.89806C12.3758 2.03398 10.6242 2.03398 9.32102 2.89806L5.58554 5.37492C5.57453 5.38223 5.56377 5.38977 5.55327 5.39754L5.51098 5.40313C4.33856 5.56076 3.38961 5.89288 2.64124 6.64124C1.89288 7.38961 1.56076 8.33856 1.40314 9.51098C1.24997 10.6502 1.24998 12.1058 1.25 13.9436V14.0564C1.24998 15.8942 1.24997 17.3498 1.40314 18.489C1.56076 19.6614 1.89288 20.6104 2.64124 21.3588C3.38961 22.1071 4.33856 22.4392 5.51098 22.5969C6.65019 22.75 8.10583 22.75 9.94359 22.75H13.0564C14.8942 22.75 16.3498 22.75 17.489 22.5969C18.6614 22.4392 19.6104 22.1071 20.3588 21.3588C21.2831 20.4344 21.5777 19.1964 21.6847 17.5899C21.4832 17.6786 21.2645 17.7328 21.0391 17.7465C20.9803 17.7501 20.9163 17.75 20.8499 17.75L20.8333 17.75H20.1679C20.0541 19.0915 19.7966 19.7996 19.2981 20.2981C18.8749 20.7213 18.2952 20.975 17.2892 21.1102C16.2615 21.2484 14.9068 21.25 13 21.25H10C8.09318 21.25 6.73851 21.2484 5.71085 21.1102C4.70476 20.975 4.12511 20.7213 3.7019 20.2981C3.27869 19.8749 3.02502 19.2952 2.88976 18.2892C2.75159 17.2615 2.75 15.9068 2.75 14C2.75 12.0932 2.75159 10.7385 2.88976 9.71085C3.02502 8.70476 3.27869 8.12511 3.7019 7.7019C4.12511 7.27869 4.70476 7.02502 5.71085 6.88976C6.73851 6.75159 8.09318 6.75 10 6.75H13C14.9068 6.75 16.2615 6.75159 17.2892 6.88976C18.2952 7.02502 18.8749 7.27869 19.2981 7.7019C19.7966 8.20043 20.0541 8.90854 20.1679 10.25H20.8333L20.8499 10.25ZM9.94358 5.25H13.0564C13.5729 5.24999 14.0592 5.24999 14.5168 5.25339L12.8501 4.14821C12.0493 3.61726 10.9507 3.61726 10.15 4.14821L8.48318 5.25339C8.94077 5.24999 9.42708 5.24999 9.94358 5.25Z" />
                        <path
                            d="M6 9.25C5.58579 9.25 5.25 9.58579 5.25 10C5.25 10.4142 5.58579 10.75 6 10.75H10C10.4142 10.75 10.75 10.4142 10.75 10C10.75 9.58579 10.4142 9.25 10 9.25H6Z" />
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M19 14C19 14.5523 18.5523 15 18 15C17.4477 15 17 14.5523 17 14C17 13.4477 17.4477 13 18 13C18.5523 13 19 13.4477 19 14Z" />
                    </svg>
                    Balance Change
                </h2>
                <div class="flex items-center space-x-2">
                    <p class="text-2xl">{{ formatMoney(balanceChange) }}</p>
                    <div class="tooltip tooltip-left"
                         :data-tip="`Previous period: ${formatMoney(previousBalanceChange)}`">
                        <div class="badge badge-outline"
                             :class="{
                                 'badge-success': balanceChange > previousBalanceChange,
                                 'badge-error': balanceChange < previousBalanceChange,
                             }">
                            {{ formatPercentage(calculatePercentageDiff(balanceChange, previousBalanceChange)) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <h2 class="card-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg" class="text-error">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M1.25 7.02036C1.25 4.40946 3.30359 2.25 5.88889 2.25H18.1111C20.6964 2.25 22.75 4.40946 22.75 7.02036C22.75 9.0483 21.5139 10.8004 19.75 11.4846V16.052C19.75 16.3624 19.75 16.655 19.7467 16.9295C19.7489 16.9527 19.75 16.9762 19.75 17C19.75 17.0314 19.7481 17.0623 19.7443 17.0927C19.7363 17.5455 19.7169 17.9464 19.6701 18.2945C19.5857 18.9223 19.4 19.4891 18.9445 19.9445C18.4891 20.4 17.9223 20.5857 17.2945 20.6701C16.6997 20.7501 15.9505 20.75 15.052 20.75H8.94801C8.04952 20.75 7.3003 20.7501 6.70552 20.6701C6.07773 20.5857 5.51093 20.4 5.05546 19.9445C4.59999 19.4891 4.41432 18.9223 4.32991 18.2945C4.28312 17.9464 4.26371 17.5455 4.25567 17.0927C4.25193 17.0623 4.25 17.0314 4.25 17C4.25 16.9762 4.25111 16.9527 4.25327 16.9295C4.24998 16.655 4.24999 16.3624 4.25 16.052L4.25 11.4846C2.48614 10.8004 1.25 9.0483 1.25 7.02036ZM4.25001 9.80955C4.25018 8.9723 4.25409 8.26948 4.32991 7.70552C4.41432 7.07773 4.59999 6.51093 5.05546 6.05546C5.51093 5.59999 6.07773 5.41432 6.70552 5.32991C7.3003 5.24994 8.04952 5.24997 8.948 5.25H15.052C15.9505 5.24997 16.6997 5.24994 17.2945 5.32991C17.9223 5.41432 18.4891 5.59999 18.9445 6.05546C19.4 6.51093 19.5857 7.07773 19.6701 7.70552C19.7459 8.26948 19.7498 8.9723 19.75 9.80954C20.6439 9.23798 21.25 8.21032 21.25 7.02036C21.25 5.19049 19.8214 3.75 18.1111 3.75H5.88889C4.17864 3.75 2.75 5.19049 2.75 7.02036C2.75 8.21032 3.35611 9.23799 4.25001 9.80955ZM11.25 6.75H9C8.03599 6.75 7.38843 6.75159 6.90539 6.81653C6.44393 6.87858 6.24643 6.9858 6.11612 7.11612C5.9858 7.24643 5.87858 7.44393 5.81653 7.90539C5.75159 8.38843 5.75 9.03599 5.75 10V16C5.75 16.0858 5.75001 16.1691 5.75008 16.25H18.2499C18.25 16.1691 18.25 16.0858 18.25 16V10C18.25 9.03599 18.2484 8.38843 18.1835 7.90539C18.1214 7.44393 18.0142 7.24643 17.8839 7.11612C17.7536 6.9858 17.5561 6.87858 17.0946 6.81653C16.6116 6.75159 15.964 6.75 15 6.75H12.75V10.9726L13.4306 10.1786C13.7001 9.86408 14.1736 9.82766 14.4881 10.0972C14.8026 10.3668 14.839 10.8403 14.5694 11.1548L12.5694 13.4881C12.427 13.6543 12.2189 13.75 12 13.75C11.7811 13.75 11.573 13.6543 11.4306 13.4881L9.43056 11.1548C9.16099 10.8403 9.19741 10.3668 9.51191 10.0972C9.8264 9.82766 10.2999 9.86408 10.5694 10.1786L11.25 10.9726V6.75ZM18.2178 17.75H5.78219C5.79101 17.8739 5.80224 17.9883 5.81653 18.0946C5.87858 18.5561 5.9858 18.7536 6.11612 18.8839C6.24643 19.0142 6.44393 19.1214 6.90539 19.1835C7.38843 19.2484 8.03599 19.25 9 19.25H15C15.964 19.25 16.6116 19.2484 17.0946 19.1835C17.5561 19.1214 17.7536 19.0142 17.8839 18.8839C18.0142 18.7536 18.1214 18.5561 18.1835 18.0946C18.1978 17.9883 18.209 17.8739 18.2178 17.75Z" />
                    </svg>
                    Average Daily Expenses
                </h2>
                <div class="flex items-center space-x-2">
                    <p class="text-2xl">{{ formatMoney(averageDailyExpenses) }}</p>
                    <div class="tooltip tooltip-left"
                         :data-tip="`Previous period: ${formatMoney(previousAverageDailyExpenses)}`">
                        <div class="badge badge-outline"
                             :class="{
                                 'badge-success': averageDailyExpenses > previousAverageDailyExpenses,
                                 'badge-error': averageDailyExpenses < previousAverageDailyExpenses,
                             }">
                            {{ formatPercentage(calculatePercentageDiff(averageDailyExpenses, previousAverageDailyExpenses)) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-border border-base-300 bg-base-100">
            <div class="card-body">
                <h2 class="card-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                        xmlns="http://www.w3.org/2000/svg" class="text-success">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M12 2.75C6.89137 2.75 2.75 6.89137 2.75 12C2.75 17.1086 6.89137 21.25 12 21.25C17.1086 21.25 21.25 17.1086 21.25 12C21.25 6.89137 17.1086 2.75 12 2.75ZM1.25 12C1.25 6.06294 6.06294 1.25 12 1.25C17.9371 1.25 22.75 6.06294 22.75 12C22.75 17.9371 17.9371 22.75 12 22.75C6.06294 22.75 1.25 17.9371 1.25 12ZM12 5.25C12.4142 5.25 12.75 5.58579 12.75 6V6.31673C14.3804 6.60867 15.75 7.83361 15.75 9.5C15.75 9.91421 15.4142 10.25 15 10.25C14.5858 10.25 14.25 9.91421 14.25 9.5C14.25 8.65573 13.3765 7.75 12 7.75C10.6235 7.75 9.75 8.65573 9.75 9.5C9.75 10.3443 10.6235 11.25 12 11.25C13.9372 11.25 15.75 12.5828 15.75 14.5C15.75 16.1664 14.3804 17.3913 12.75 17.6833V18C12.75 18.4142 12.4142 18.75 12 18.75C11.5858 18.75 11.25 18.4142 11.25 18V17.6833C9.61957 17.3913 8.25 16.1664 8.25 14.5C8.25 14.0858 8.58579 13.75 9 13.75C9.41421 13.75 9.75 14.0858 9.75 14.5C9.75 15.3443 10.6235 16.25 12 16.25C13.3765 16.25 14.25 15.3443 14.25 14.5C14.25 13.6557 13.3765 12.75 12 12.75C10.0628 12.75 8.25 11.4172 8.25 9.5C8.25 7.83361 9.61957 6.60867 11.25 6.31673V6C11.25 5.58579 11.5858 5.25 12 5.25Z" />
                    </svg>
                    Average Daily Income
                </h2>
                <div class="flex items-center space-x-2">
                    <p class="text-2xl">{{ formatMoney(averageDailyIncome) }}</p>
                    <div class="tooltip tooltip-left"
                         :data-tip="`Previous period: ${formatMoney(previousAverageDailyIncome)}`">
                        <div class="badge badge-outline"
                             :class="{
                                 'badge-success': averageDailyIncome > previousAverageDailyIncome,
                                 'badge-error': averageDailyIncome < previousAverageDailyIncome,
                             }">
                            {{ formatPercentage(calculatePercentageDiff(averageDailyIncome, previousAverageDailyIncome)) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue'
import { useTransactionsStore } from '../services/transactions.js'

const transactionsStore = useTransactionsStore()
const formatMoney = inject('formatMoney')
const formatPercentage = inject('formatPercentage')

const props = defineProps({
    dateRange: {
        type: Object,
        required: true,
    },
})

const calculatePercentageDiff = (current, previous) => {
    if (previous === 0) return current > 0 ? Number.POSITIVE_INFINITY : Number.POSITIVE_INFINITY
    return ((current - previous) / previous) * 100
}

const computeMetrics = (transactions, startDate, endDate) => {
    const totals = (transactions || []).reduce(
        (acc, t) => {
            t.amount >= 0 ? (acc.positive += t.amount) : (acc.negative += t.amount)
            return acc
        },
        {
            positive: 0,
            negative: 0,
        },
    )

    const diffTime = endDate - startDate
    const days = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) || 1 // Avoid division by zero

    return {
        balanceChange: totals.positive + totals.negative,
        averageDailyExpenses: totals.negative / days || 0,
        averageDailyIncome: totals.positive / days || 0,
    }
}

const filteredTransactions = computed(() =>
    transactionsStore.filteredByDateRange(props.dateRange.currentStart, props.dateRange.currentEnd),
)

const previousTransactions = computed(() =>
    transactionsStore.filteredByDateRange(props.dateRange.previousStart, props.dateRange.previousEnd),
)

const currentMetrics = computed(() =>
    computeMetrics(filteredTransactions.value, props.dateRange.currentStart, props.dateRange.currentEnd),
)

const previousMetrics = computed(() =>
    computeMetrics(previousTransactions.value, props.dateRange.previousStart, props.dateRange.previousEnd),
)

const balanceChange = computed(() => currentMetrics.value.balanceChange)
const averageDailyExpenses = computed(() => currentMetrics.value.averageDailyExpenses)
const averageDailyIncome = computed(() => currentMetrics.value.averageDailyIncome)

const previousBalanceChange = computed(() => previousMetrics.value.balanceChange)
const previousAverageDailyExpenses = computed(() => previousMetrics.value.averageDailyExpenses)
const previousAverageDailyIncome = computed(() => previousMetrics.value.averageDailyIncome)
</script>

<style scoped></style>
