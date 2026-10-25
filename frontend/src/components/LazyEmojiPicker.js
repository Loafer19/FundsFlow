import { defineAsyncComponent, h } from 'vue'

/** Emoji picker as a separate chunk; a same-size skeleton holds the space while it loads. */
export default defineAsyncComponent({
    loader: () => import('./EmojiPicker.vue'),
    loadingComponent: {
        render: () => h('div', { class: 'skeleton w-full h-60 mb-4', 'aria-label': 'Loading emoji picker' }),
    },
    delay: 0,
})
