<template>
    <span v-if="type" class="type-segments inline-flex shrink-0 align-middle">
        <span
            class="inline-flex items-center overflow-hidden rounded-field whitespace-nowrap border border-base-300 bg-base-100"
            role="group"
            :aria-label="ariaLabel"
        >
            <button
                v-for="(seg, i) in shown"
                :key="seg.key"
                type="button"
                class="type-seg truncate px-1.5 font-medium transition-colors duration-200"
                :class="[segClass(seg), i > 0 && 'border-l', sizeClass, segMaxClass(seg), seg.kind === 'type' && 'cursor-pointer']"
                :style="segStyle(seg)"
                :data-seg="seg.key"
                :tabindex="0"
                :aria-label="seg.tip"
                @mouseenter="tipOn(seg, $event)"
                @mouseleave="tipOff"
                @focus="tipOn(seg, $event)"
                @blur="tipOff"
                @click.stop="onSegClick(seg, $event)"
            >
                <template v-if="seg.kind === 'type'">
                    <span v-if="type.emoji" class="mr-0.5" aria-hidden="true">{{ type.emoji }}</span>{{ type.name }}
                </template>
                <template v-else-if="seg.kind === 'more'">+{{ hidden.length }}</template>
                <template v-else>{{ seg.text }}</template>
            </button>
        </span>

        <Teleport to="body">
            <div
                v-if="tipMounted && current?.kind === 'more'"
                class="type-seg-tip card card-border bg-base-100 rounded-box shadow w-52 p-3 text-left transition-opacity duration-200"
                :class="tipVisible ? 'opacity-100' : 'opacity-0'"
                :style="tipPos"
                role="tooltip"
            >
                <div class="font-semibold text-sm mb-1">+{{ hidden.length }} more</div>
                <dl class="text-xs leading-relaxed space-y-0.5">
                    <div v-for="row in hidden" :key="row.key" class="flex justify-between gap-3">
                        <dt class="text-base-content/60">{{ row.label }}</dt>
                        <dd class="font-medium">{{ row.text }}</dd>
                    </div>
                </dl>
            </div>
            <div
                v-else-if="tipMounted && current"
                class="type-seg-tip z-50 max-w-xs rounded-field bg-neutral text-neutral-content text-xs leading-relaxed px-2 py-1 shadow text-left transition-opacity duration-200"
                :class="tipVisible ? 'opacity-100' : 'opacity-0'"
                :style="tipPos"
                role="tooltip"
            >
                {{ current.tip }}
            </div>
        </Teleport>
    </span>
</template>

<script setup>
// Mix look: colored type segment, grey value segments, DaisyUI tip per segment.
// List: "⛽ Fuel | 40 l | 58,90 ₴ | +1". Calendar/cards use a tighter cap.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useTransactionTypesStore } from '../services/transactionTypes.js'
import { fillField, typeRows } from '../services/typeFields.js'

const props = defineProps({
    transaction: { type: Object, required: true },
    size: { type: String, default: 'md' },
    maxVisible: { type: Number, default: null },
    // Already formatted rows (assistant cards send fields as text, not raw data).
    rows: { type: Array, default: null },
})

const typesStore = useTransactionTypesStore()
const type = computed(() => typesStore.byId(props.transaction?.transaction_type_id))

const values = computed(() => {
    if (!type.value) return []
    const fill = fillField(type.value)?.key
    const source = props.rows
        ? props.rows.map((row) => ({
              key: row.key,
              label: row.label ?? row.key,
              value: row.value ?? row.text,
          }))
        : typeRows(type.value, props.transaction?.data ?? {})
    return source.filter((row) => row.key !== fill && row.value != null && String(row.value) !== '')
})

const segments = computed(() => {
    if (!type.value) return []
    return [
        {
            key: 'type',
            kind: 'type',
            text: type.value.name,
            label: type.value.name,
            tip: `View ${type.value.name} analytics`,
        },
        ...values.value.map((row) => ({
            key: row.key,
            kind: 'value',
            text: String(row.value),
            label: row.label,
            tip: `${row.label}: ${row.value}`,
        })),
    ]
})

// Type + 2 values on a list row; type + 1 on calendar/cards (sm/xs).
// Below 640px the Type column is tight, so show the type and put the rest in +N.
const narrow = ref(typeof window !== 'undefined' && window.innerWidth < 640)
const cap = computed(() => {
    if (props.maxVisible != null) return Math.max(1, props.maxVisible)
    if (props.size === 'xs' || props.size === 'sm') return 2
    return narrow.value ? 1 : 3
})

const hidden = computed(() => segments.value.slice(cap.value))
const shown = computed(() => {
    const head = segments.value.slice(0, cap.value)
    if (!hidden.value.length) return head
    return [
        ...head,
        {
            key: 'more',
            kind: 'more',
            text: `+${hidden.value.length}`,
            label: 'More',
            tip: hidden.value.map((row) => `${row.label}: ${row.text}`).join('\n'),
        },
    ]
})

const ariaLabel = computed(() => {
    if (!type.value) return undefined
    return [type.value.name, ...values.value.map((row) => `${row.label}: ${row.value}`)].join(', ')
})

const sizeClass = computed(() =>
    props.size === 'xs'
        ? 'text-[10px] leading-5 h-5'
        : props.size === 'sm'
          ? 'text-xs leading-5 h-5'
          : 'text-sm leading-6 h-6',
)

const color = computed(() => type.value?.color)

const segClass = (seg) => (seg.kind === 'more' ? 'opacity-70 font-semibold shrink-0' : 'shrink-0')

const segMaxClass = (seg) => {
    if (seg.kind === 'more') return ''
    if (narrow.value && seg.kind === 'type' && props.size === 'md') return 'max-w-[4.75rem]'
    return 'max-w-[7.5rem]'
}

const segStyle = (seg) => {
    const c = color.value
    const rule = { borderColor: 'color-mix(in oklab, currentColor 20%, transparent)' }
    if (seg.kind === 'type' && c) {
        return {
            ...rule,
            backgroundColor: `color-mix(in oklab, ${c} 22%, var(--color-base-100))`,
            borderColor: `color-mix(in oklab, ${c} 30%, transparent)`,
        }
    }
    return rule
}

const current = ref(null)
const tipMounted = ref(false)
const tipVisible = ref(false)
const tipPos = ref({})
let hideTimer
let anchor = null

const place = () => {
    if (!anchor) return
    const r = anchor.getBoundingClientRect()
    const width = current.value?.kind === 'more' ? 208 : 220
    let left = Math.min(r.left, window.innerWidth - width - 8)
    left = Math.max(8, left)
    const above = r.top > 120
    tipPos.value = {
        position: 'fixed',
        left: `${left}px`,
        top: above ? 'auto' : `${r.bottom + 8}px`,
        bottom: above ? `${window.innerHeight - r.top + 8}px` : 'auto',
        zIndex: 50,
        pointerEvents: 'none',
        width: current.value?.kind === 'more' ? '13rem' : 'max-content',
        maxWidth: '20rem',
    }
}

const tipOn = (seg, event) => {
    clearTimeout(hideTimer)
    current.value = seg
    anchor = event.currentTarget
    tipMounted.value = true
    place()
    requestAnimationFrame(() => {
        tipVisible.value = true
    })
}

const tipOff = () => {
    tipVisible.value = false
    hideTimer = setTimeout(() => {
        tipMounted.value = false
        current.value = null
        anchor = null
    }, 200)
}

const onSegClick = (seg) => {
    if (seg.kind !== 'type' || !type.value) return
    tipOff()
    typesStore.openAnalytics(type.value.id)
}

const onReposition = () => {
    narrow.value = window.innerWidth < 640
    if (tipMounted.value) place()
}

onMounted(() => {
    narrow.value = window.innerWidth < 640
})
window.addEventListener('scroll', onReposition, true)
window.addEventListener('resize', onReposition)
onBeforeUnmount(() => {
    clearTimeout(hideTimer)
    window.removeEventListener('scroll', onReposition, true)
    window.removeEventListener('resize', onReposition)
})
</script>
