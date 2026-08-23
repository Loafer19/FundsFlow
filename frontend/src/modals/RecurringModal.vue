<template>
    <dialog id="recurring_modal" class="modal">
        <div class="modal-box max-w-lg">
            <div class="flex justify-between items-center mb-4">
                <h2 class="card-title">
                    <Repeat :size="24" />
                    Recurring
                </h2>
                <button onclick="recurring_add_modal.showModal()" class="btn btn-outline btn-info btn-sm">
                    Add Recurring
                    <Plus :size="24" />
                </button>
            </div>

            <div class="recurring-list">
                <table class="table">
                    <tbody>
                        <tr v-for="rule in recurringStore.rules" :key="rule.id">
                            <td>
                                <div class="flex items-center gap-2">
                                    <span :class="rule.amount > 0 ? 'text-success' : 'text-error'">
                                        {{ formatMoney(rule.amount) }}
                                    </span>
                                    <span v-if="rule.note" class="text-base-content/60">{{ rule.note }}</span>
                                </div>
                                <div class="flex items-center gap-1 text-xs text-base-content/60 mt-1">
                                    <span v-for="tag in rule.tags" :key="tag.id" class="tooltip"
                                        :data-tip="tag.title">{{ tag.emoji }}</span>
                                    <span>{{ frequencyLabel(rule.frequency) }} · next {{ formatDate(rule.next_run_at) }}</span>
                                </div>
                            </td>
                            <td class="text-right">
                                <button v-if="recurringStore.isLoading == rule.id"
                                    class="btn btn-outline btn-error btn-square btn-sm" disabled>
                                    <span class="loading loading-spinner text-error"></span>
                                </button>
                                <div v-else class="flex justify-end gap-1">
                                    <button type="button" class="btn btn-outline btn-square btn-sm"
                                        :class="rule.active ? 'btn-warning' : 'btn-success'"
                                        :aria-label="rule.active ? 'Pause' : 'Resume'"
                                        @click="recurringStore.toggleActive(rule)">
                                        <Pause v-if="rule.active" :size="20" />
                                        <Play v-else :size="20" />
                                    </button>

                                    <button type="button" class="btn btn-outline btn-secondary btn-square btn-sm"
                                        aria-label="Edit" @click="openEdit(rule)">
                                        <Pencil :size="20" />
                                    </button>

                                    <DeleteHold :id="rule.id" :disabled="recurringStore.isLoading"
                                        :isLoading="recurringStore.isLoading === rule.id"
                                        @delete="(id) => recurringStore.delete(id)" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!recurringStore.rules.length">
                            <td colspan="2" class="text-center py-8 text-base-content/60">
                                No recurring transactions yet. Add rent, salary, or a subscription.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>
</template>

<script setup>
import { Pause, Pencil, Play, Plus, Repeat } from 'lucide-vue-next'
import DeleteHold from './../components/buttons/DeleteHold.vue'
import { formatDate, formatMoney } from './../services/formatters.js'
import { useRecurringTransactionsStore } from './../services/recurringTransactions.js'

const recurringStore = useRecurringTransactionsStore()

const frequencyLabel = (frequency) => ({ daily: 'daily', weekly: 'weekly', monthly: 'monthly', yearly: 'yearly' })[frequency]

const openEdit = (rule) => {
    recurringStore.ruleForEdit = rule
    recurring_edit_modal.showModal()
}
</script>

<style scoped>
.recurring-list {
    max-height: 65vh;
}
</style>
