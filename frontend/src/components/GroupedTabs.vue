<template>
    <div class="-mx-4 px-4 mb-4 hidden md:block">
        <div class="flex items-end gap-3 flex-wrap">
            <section v-for="(group, index) in groups" :key="group.id" class="flex flex-col min-w-0">
                <div class="flex items-end gap-3">
                    <div v-if="index > 0" class="w-px bg-base-300 hidden sm:block mb-1 h-10" aria-hidden="true"></div>
                    <div class="flex flex-col">
                        <div class="px-1 pb-1 text-[11px] uppercase tracking-wide text-base-content/40 font-medium">
                            {{ group.label }}
                        </div>
                        <div class="tabs tabs-box gap-1.5 p-0" role="radiogroup" :aria-label="group.label">
                            <label v-for="item in group.items" :key="item.key"
                                class="tab gap-1 text-base font-medium hover:text-info hover:bg-base-200 shrink-0">
                                <input type="radio" :name="'tabs_' + group.id" class="tab" :checked="item.key === modelValue"
                                    @change="$emit('update:modelValue', item.key)" />
                                <component :is="item.icon" :size="20" />
                                {{ item.label }}
                            </label>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup>
defineProps({
    groups: { type: Array, required: true },
    modelValue: { type: String, required: true },
})
defineEmits(['update:modelValue'])
</script>

<style scoped>
.tabs-box { box-shadow: none; }
.tab {
    border: var(--border) solid var(--color-base-300) !important;
    border-radius: var(--radius-field) !important;
    box-shadow: none;
}
</style>
