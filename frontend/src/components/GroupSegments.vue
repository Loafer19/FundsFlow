<template>
    <div class="mb-4 md:hidden">
        <SegmentedControl v-if="segments.length" :options="segments" :label="activeLabel" size="sm"
            v-model="segmentProxy" />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import SegmentedControl from './SegmentedControl.vue'

const props = defineProps({
    groups: { type: Array, required: true },
    activeGroup: { type: String, required: true },
    modelValue: { type: String, required: true },
})
const emit = defineEmits(['update:modelValue'])

const current = computed(() => props.groups.find((g) => g.id === props.activeGroup) ?? props.groups[0])
const activeLabel = computed(() => current.value?.label || 'Views')
const segments = computed(() =>
    (current.value?.items ?? []).map((item) => ({
        value: item.key,
        label: item.label,
        icon: item.icon,
    })),
)
const segmentProxy = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
})
</script>
