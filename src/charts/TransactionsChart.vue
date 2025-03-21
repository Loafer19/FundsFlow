<template>
    <div class="card card-border border-base-300 bg-base-100 mb-4">
        <div class="card-body">
            <h2 class="card-title">Cash Flow by Day</h2>
            <LineChart :data="chartData" :options="chartOptions" />
        </div>
    </div>
</template>

<script setup>
import { inject, computed } from 'vue'
import { Line as LineChart } from 'vue-chartjs'
import {
    Chart as ChartJS,
    Tooltip,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale
} from 'chart.js'

ChartJS.register(Tooltip, LineElement, PointElement, CategoryScale, LinearScale)

const props = defineProps({
    transactions: {
        type: Array,
        required: true
    }
})

const transactionsFormatted = computed(() => {
    return props.transactions
        .sort((a, b) => new Date(a.date) - new Date(b.date))
        .reduce((acc, t) => {
            const date = t.date.split('T')[0]
            if (acc[date]) {
                acc[date] += t.amount
            } else {
                acc[date] = t.amount
            }
            return acc
        }, {})
})

const formatDate = inject('formatDate')

const chartData = computed(() => ({
    labels: Object.keys(transactionsFormatted.value),
    datasets: [
        {
            data: Object.values(transactionsFormatted.value),
            borderColor: 'oklch(85% 0.199 91.936)',
            backgroundColor: 'oklch(85% 0.199 91.936 / 0.2)',
            tension: 0.3
        }
    ]
}))

const chartOptions = {
    plugins: { legend: { display: false } },
}

</script>
