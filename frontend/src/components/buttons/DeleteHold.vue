<template>
    <button v-if="isLoading" class="btn btn-outline btn-error btn-square btn-sm" disabled>
        <span class="loading loading-spinner text-error"></span>
    </button>
    <div v-else class="tooltip tooltip-left">
        <div class="tooltip-content w-30 flex flex-col">
            <span v-show="!isHolding">Hold to delete</span>
            <progress v-show="isHolding" class="progress progress-error my-1" value="100"></progress>
        </div>
        <button class="btn btn-outline btn-error btn-square btn-sm" aria-label="Hold to delete"
            @touchstart="startHold" @touchend="stopHold" @touchcancel="stopHold" @mousedown="startHold"
            @mouseup="stopHold" @mouseleave="stopHold" @keydown.enter.prevent="startHold"
            @keydown.space.prevent="startHold" @keyup.enter="stopHold" @keyup.space="stopHold"
            :disabled="disabled">
            <Trash2 :size="24" />
        </button>
    </div>
</template>

<script setup>
import { Trash2 } from 'lucide-vue-next'
import { ref } from 'vue'

const emit = defineEmits(['delete'])

const props = defineProps({
    id: {
        type: Number,
        required: true,
    },
    disabled: {
        type: [Boolean, Number],
        required: true,
    },
    isLoading: {
        type: Boolean,
        required: true,
    },
})

const isHolding = ref(false)
let timeout = null

const startHold = () => {
    if (props.disabled || isHolding.value) return

    isHolding.value = true

    timeout = setTimeout(() => {
        stopHold()
        emit('delete', props.id)
    }, 900)
}

const stopHold = () => {
    isHolding.value = false

    clearTimeout(timeout)
}
</script>

<style scoped>
.btn:active:not(.btn-active) {
    scale: 0.95;
}

.progress {
    --time: 1s;
    height: 9.5px;
    transform-origin: left top;
    transform: scaleX(0);
    animation: scale var(--time) forwards;
}

@keyframes scale {
    0% {
        transform: scaleX(0);
    }

    100% {
        transform: scaleX(1);
    }
}
</style>
