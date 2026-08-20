<template>
    <div class="card card-border border-base-300 bg-base-100">
        <div class="card-body items-center text-center py-12 gap-3">
            <div class="text-4xl opacity-60">{{ icon }}</div>
            <h3 class="text-lg font-semibold">{{ title }}</h3>
            <p v-if="description" class="text-base-content/60 max-w-sm">{{ description }}</p>
            <button v-if="actionLabel" type="button" class="btn btn-primary btn-sm mt-2" @click="$emit('action')">
                {{ actionLabel }}
            </button>
        </div>
    </div>
</template>

<script setup>
defineProps({
    icon: {
        type: String,
        default: '📭',
    },
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    actionLabel: {
        type: String,
        default: '',
    },
})

defineEmits(['action'])
</script>
