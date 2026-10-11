<template>
    <div class="flex gap-2 mb-4 flex-wrap" role="radiogroup" aria-label="Date">
        <button type="button" role="radio" class="btn btn-sm" :class="chipClass(isToday)" :aria-checked="isToday"
            @click="pick(today)">
            Today
        </button>

        <button type="button" role="radio" class="btn btn-sm" :class="chipClass(isYesterday)"
            :aria-checked="isYesterday" @click="pick(yesterday)">
            Yesterday
        </button>

        <label role="radio" class="btn btn-sm relative" :class="chipClass(isOther)" :aria-checked="isOther">
            <CalendarDays :size="16" />
            {{ isOther ? formatDate(parseLocalDate(model)) : 'Other date' }}
            <!-- Invisible native picker over the chip: a tap opens the OS date sheet on mobile. -->
            <input type="date" class="absolute inset-0 opacity-0 cursor-pointer" :value="model"
                aria-label="Pick a date" @click="openPicker" @change="pick($event.target.value)" />
        </label>
    </div>
</template>

<script setup>
import { CalendarDays } from 'lucide-vue-next'
import { computed } from 'vue'
import { today } from '../services/clock.js'
import { formatDate, parseLocalDate, toLocalDateStr } from '../services/formatters.js'
import { haptic } from '../services/telegramWebApp.js'

// Empty until the parent fills it (e.g. the Edit modal before a row is picked).
const model = defineModel({ type: String, default: '' })

const yesterday = computed(() => {
    const date = parseLocalDate(today.value)
    date.setDate(date.getDate() - 1)

    return toLocalDateStr(date)
})

const isToday = computed(() => model.value === today.value)
const isYesterday = computed(() => model.value === yesterday.value)
const isOther = computed(() => Boolean(model.value) && !isToday.value && !isYesterday.value)

const chipClass = (active) => (active ? 'btn-primary' : 'btn-ghost border-base-300')

const pick = (value) => {
    if (!value || value === model.value) return

    model.value = value
    haptic.select()
}

// Desktop browsers only open the calendar from its tiny icon; open it from anywhere on the chip.
const openPicker = (event) => {
    try {
        event.target.showPicker?.()
    } catch {
        // Not allowed here (e.g. cross-origin iframe) — the native input still works.
    }
}
</script>
