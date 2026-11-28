<template>
    <div v-if="templates.length" class="flex flex-col gap-1">
        <span class="text-sm text-base-content/80">Start from a template</span>
        <div class="flex flex-wrap gap-1.5" :class="left ? 'justify-start' : 'justify-center'">
            <button v-for="template in templates" :key="template.name" type="button"
                class="btn btn-sm btn-outline border-base-300" @click="$emit('pick', template)">
                <span>{{ template.emoji }}</span>
                {{ template.name }}
            </button>
        </div>
    </div>
</template>

<script setup>
defineProps({
    templates: { type: Array, required: true },
    left: { type: Boolean, default: false },
})

defineEmits(['pick'])
</script>
