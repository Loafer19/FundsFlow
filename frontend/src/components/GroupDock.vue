<template>
    <nav class="dock dock-md md:hidden z-50" aria-label="View groups">
        <button v-for="group in groups" :key="group.id" type="button"
            class="hover:bg-base-200"
            :class="{ 'dock-active': group.id === activeGroup && !moreOpen }"
            :aria-current="group.id === activeGroup && !moreOpen ? 'page' : false"
            @click="$emit('select-group', group.id)">
            <component :is="group.icon" :size="20" />
            <span class="dock-label">{{ group.label }}</span>
        </button>
        <button type="button" class="text-primary hover:bg-base-200" aria-label="Add Transaction" @click="$emit('add')">
            <span class="bg-primary text-primary-content rounded-full size-12 flex items-center justify-center -mt-3 shadow">
                <Plus :size="24" />
            </span>
            <span class="dock-label">Add</span>
        </button>
        <button type="button" class="hover:bg-base-200" :class="{ 'dock-active': moreOpen }" :aria-expanded="moreOpen"
            @click="$emit('update:moreOpen', !moreOpen)">
            <Menu :size="20" />
            <span class="dock-label">More</span>
        </button>
    </nav>

    <div v-if="moreOpen" class="md:hidden fixed inset-0 z-[60]">
        <button type="button" class="absolute inset-0 bg-base-content/30" aria-label="Close menu"
            @click="$emit('update:moreOpen', false)"></button>
        <div class="absolute bottom-[calc(4rem+env(safe-area-inset-bottom))] inset-x-0 bg-base-100 rounded-t-box p-4 border-t border-base-300 shadow-xl">
            <div class="font-semibold mb-3">More</div>
            <div class="grid grid-cols-3 gap-2">
                <button v-if="authenticated" type="button" class="btn btn-ghost h-auto flex-col gap-1 py-3 border border-base-300" @click="$emit('assistant')">
                    <Sparkles :size="20" /><span class="text-xs font-medium">Assistant</span>
                </button>
                <button type="button" class="btn btn-ghost h-auto flex-col gap-1 py-3 border border-base-300" @click="$emit('import')">
                    <Upload :size="20" /><span class="text-xs font-medium">Import</span>
                </button>
                <button type="button" class="btn btn-ghost h-auto flex-col gap-1 py-3 border border-base-300" @click="$emit('recurring')">
                    <Repeat :size="20" /><span class="text-xs font-medium">Recurring</span>
                </button>
                <button type="button" class="btn btn-ghost h-auto flex-col gap-1 py-3 border border-base-300" @click="$emit('tags')">
                    <Tag :size="20" /><span class="text-xs font-medium">Tags</span>
                </button>
                <button type="button" class="btn btn-ghost h-auto flex-col gap-1 py-3 border border-base-300" @click="$emit('settings')">
                    <Settings :size="20" /><span class="text-xs font-medium">Settings</span>
                </button>
                <a href="/docs/" target="_blank" rel="noopener" class="btn btn-ghost h-auto flex-col gap-1 py-3 border border-base-300">
                    <CircleHelp :size="20" /><span class="text-xs font-medium">Docs</span>
                </a>
                <button type="button" class="btn btn-ghost h-auto flex-col gap-1 py-3 border border-base-300" @click="$emit('feedback')">
                    <LifeBuoy :size="20" /><span class="text-xs font-medium">Feedback</span>
                </button>
                <template v-if="authenticated">
                    <div aria-hidden="true"></div>
                    <button type="button" class="btn btn-ghost h-auto flex-col gap-1 py-3 border border-base-300 text-error" @click="$emit('logout')">
                        <LogOut :size="20" /><span class="text-xs font-medium">Logout</span>
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { CircleHelp, LifeBuoy, LogOut, Menu, Plus, Repeat, Settings, Sparkles, Tag, Upload } from 'lucide-vue-next'

defineProps({
    groups: { type: Array, required: true },
    activeGroup: { type: String, default: '' },
    moreOpen: { type: Boolean, default: false },
    // The dock is only mounted when signed in today; keep a compact order without Assistant/Logout if reused logged out.
    authenticated: { type: Boolean, default: true },
})
defineEmits(['select-group', 'add', 'update:moreOpen', 'import', 'recurring', 'settings', 'assistant', 'tags', 'feedback', 'logout'])
</script>
