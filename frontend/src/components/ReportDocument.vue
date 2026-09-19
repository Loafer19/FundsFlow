<template>
    <div id="fundsflow-report" class="fundsflow-report" aria-hidden="true">
        <header class="report-header">
            <img src="/logo.png" alt="" class="report-logo" width="40" height="40" />
            <div class="report-header-text">
                <div class="report-brand">FundsFlow</div>
                <div class="report-sub">{{ accountName }} · {{ periodLabel }} · {{ tagFilterLabel }}</div>
            </div>
        </header>

        <section v-if="sections.analytics" class="report-section">
            <h2 class="report-tab-title">Analytics</h2>
            <Analytics :dateRange="dateRange" />
        </section>

        <section v-if="sections.calendar" class="report-section">
            <h2 class="report-tab-title">Calendar</h2>
            <CalendarTab :dateRange="dateRange" :dateSelectionType="dateSelectionType" />
        </section>

        <section v-if="sections.budgets" class="report-section">
            <h2 class="report-tab-title">Budgets</h2>
            <Budgets />
        </section>

        <section v-if="sections.tags" class="report-section">
            <h2 class="report-tab-title">Tags · Donuts</h2>
            <TagDistribution :dateRange="dateRange" forced-view-mode="donuts" />
        </section>

        <section v-if="sections.tags" class="report-section">
            <h2 class="report-tab-title">Tags · List</h2>
            <TagDistribution :dateRange="dateRange" forced-view-mode="list" />
        </section>

        <section v-if="sections.flow" class="report-section">
            <h2 class="report-tab-title">Flow</h2>
            <MoneyFlow :dateRange="dateRange" :dateSelectionType="dateSelectionType" />
        </section>

        <section v-if="sections.trend" class="report-section">
            <h2 class="report-tab-title">Trend</h2>
            <BalanceTrend :dateRange="dateRange" />
        </section>

        <section v-if="sections.list" class="report-section">
            <h2 class="report-tab-title">List</h2>
            <TableTab :dateRange="dateRange" />
        </section>
    </div>
</template>

<script setup>
import { computed, inject } from 'vue'
import { useAuthStore } from '../services/auth'
import Analytics from '../tabs/Analytics.vue'
import BalanceTrend from '../tabs/BalanceTrend.vue'
import Budgets from '../tabs/Budgets.vue'
import CalendarTab from '../tabs/Calendar.vue'
import MoneyFlow from '../tabs/MoneyFlow.vue'
import TableTab from '../tabs/TableTab.vue'
import TagDistribution from '../tabs/TagDistribution.vue'

const props = defineProps({
    sections: {
        type: Object,
        required: true,
    },
    dateRange: {
        type: Object,
        required: true,
    },
    dateSelectionType: {
        type: String,
        default: 'month',
    },
    tagFilterLabel: {
        type: String,
        default: 'All tags',
    },
})

const formatDate = inject('formatDate')
const authStore = useAuthStore()

const accountName = computed(() => authStore.user?.name || authStore.user?.email || 'Account')
const periodTypeLabel = computed(() => {
    const type = props.dateSelectionType
    if (type === 'week') return 'Week'
    if (type === 'year') return 'Year'
    return 'Month'
})
const periodLabel = computed(() => {
    const range = props.dateRange
    if (!range?.currentStart || !range?.currentEnd) return ''
    return `${periodTypeLabel.value} · ${formatDate(range.currentStart)} – ${formatDate(range.currentEnd)}`
})

const dateRange = computed(() => props.dateRange)
</script>

<style>
/* Off-screen but laid out so Chart.js can paint (not display:none). */
.fundsflow-report {
    position: absolute;
    left: -12000px;
    top: 0;
    width: 1100px;
    padding: 16px;
    background: var(--color-base-200);
    color: var(--color-base-content);
    pointer-events: none;
}

.fundsflow-report .report-header {
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 2px solid var(--color-base-content);
    padding-bottom: 10px;
    margin-bottom: 20px;
}

.fundsflow-report .report-logo {
    width: 40px;
    height: 40px;
}

.fundsflow-report .report-brand {
    font-size: 1.25rem;
    font-weight: 700;
}

.fundsflow-report .report-header-text {
    min-width: 0;
    flex: 1;
}

.fundsflow-report .report-sub,
.fundsflow-report .report-generated {
    color: color-mix(in oklab, var(--color-base-content) 65%, transparent);
    font-size: 0.8rem;
}

.fundsflow-report .report-generated {
    margin-left: auto;
    text-align: right;
}

.fundsflow-report .report-section {
    margin-bottom: 28px;
}

.fundsflow-report .report-tab-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0 0 12px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--color-base-300);
}

@media print {
    /* Explicit landscape dimensions — "A4 landscape" is ignored when Chrome remembered Portrait */
    @page {
        size: 297mm 210mm;
        margin: 6mm;
        background: var(--color-base-200);
    }

    html.printing-report body > *:not(#fundsflow-report) {
        display: none !important;
    }

    html.printing-report #fundsflow-report {
        position: static !important;
        left: auto !important;
        top: auto !important;
        width: 100% !important;
        max-width: none !important;
        padding: 0 !important;
        margin: 0 !important;
        pointer-events: auto !important;
        background: var(--color-base-200) !important;
        color: var(--color-base-content) !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Balances Per Tag card: full page width in PDF */
    html.printing-report #fundsflow-report .md\:col-span-2 {
        grid-column: 1 / -1 !important;
        width: 100% !important;
        max-width: none !important;
    }

    html.printing-report #fundsflow-report .grid.md\:grid-cols-2 {
        width: 100% !important;
    }

    /* Print media often ignores Tailwind md: breakpoints → force Analytics-style 2-col grids */
    html.printing-report #fundsflow-report .grid.md\:grid-cols-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    /* Each tab on its own page (keep header + first tab together) */
    html.printing-report #fundsflow-report .report-section + .report-section {
        break-before: page;
        page-break-before: always;
    }

    html.printing-report #fundsflow-report .overflow-x-auto,
    html.printing-report #fundsflow-report .tags-list {
        overflow: visible !important;
        max-height: none !important;
    }

    /* Tag tables: full card width; amount column stays compact */
    html.printing-report #fundsflow-report .tags-list .table {
        width: 100% !important;
        max-width: none !important;
        table-layout: auto;
    }

    html.printing-report #fundsflow-report .tags-list .table td.w-full {
        width: auto !important;
    }

    html.printing-report #fundsflow-report .tags-list .table td:last-child {
        width: 1%;
        white-space: nowrap;
        text-align: right;
    }

    html.printing-report #fundsflow-report .grid.sm\:grid-cols-2,
    html.printing-report #fundsflow-report .sm\:grid-cols-2,
    html.printing-report #fundsflow-report .lg\:grid-cols-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    /* List note: keep in its cell, wrap instead of floating */
    html.printing-report #fundsflow-report table.table td {
        vertical-align: top;
    }

    html.printing-report #fundsflow-report table.table td.max-w-xs {
        max-width: 16rem !important;
        width: 16rem !important;
    }

    html.printing-report #fundsflow-report table.table td.max-w-xs .line-clamp-2 {
        display: block !important;
        -webkit-line-clamp: unset !important;
        line-clamp: unset !important;
        -webkit-box-orient: unset !important;
        overflow: visible !important;
        white-space: normal !important;
        word-break: break-word;
        overflow-wrap: anywhere;
    }

    html.printing-report #fundsflow-report canvas {
        max-width: 100% !important;
    }

    html.printing-report #fundsflow-report .btn,
    html.printing-report #fundsflow-report .input,
    html.printing-report #fundsflow-report select {
        display: none !important;
    }

    /* Page fill = app chrome (base-200); cards stay base-100 for contrast */
    html.printing-report,
    html.printing-report body,
    html.printing-report body::before,
    html.printing-report body::after {
        background: var(--color-base-200) !important;
        background-color: var(--color-base-200) !important;
        color: var(--color-base-content) !important;
        margin: 0 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    html.printing-report #fundsflow-report .report-section {
        background: transparent !important;
        overflow: visible;
        min-height: 0 !important;
        height: auto !important;
    }

    /* Cards: base-100 on base-200 page so they read as cards, not flat slabs */
    html.printing-report #fundsflow-report .card {
        height: auto !important;
        min-height: 0 !important;
        break-inside: avoid;
        page-break-inside: avoid;
        background-color: var(--color-base-100) !important;
        border-color: var(--color-base-300) !important;
    }

    html.printing-report #fundsflow-report .overflow-x-auto {
        background: transparent !important;
    }

    /* Hide Actions column in PDF */
    html.printing-report #fundsflow-report .table-actions-col {
        display: none !important;
    }

    /* Hide List search row in PDF */
    html.printing-report #fundsflow-report .report-section .flex.flex-wrap.items-center.gap-2.mb-3 {
        display: none !important;
    }

}
</style>
