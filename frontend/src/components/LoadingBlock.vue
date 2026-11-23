<template>
    <div class="flex flex-col items-center justify-center gap-3 text-base-content/60"
        :class="compact ? 'py-4' : 'py-12'">
        <div class="flex flex-col gap-2 w-full" :class="compact ? 'max-w-xs' : 'max-w-sm'">
            <div class="skeleton h-4 w-3/4 mx-auto"></div>
            <div class="skeleton h-4 w-1/2 mx-auto"></div>
            <div v-if="!compact" class="skeleton h-4 w-2/3 mx-auto"></div>
        </div>
        <p :class="compact ? 'text-xs' : 'text-sm'">{{ label }}</p>
    </div>
</template>

<script setup>
defineProps({
    label: { type: String, required: true },
    compact: { type: Boolean, default: false },
})
</script>
