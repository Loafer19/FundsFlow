import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

// Chart.js draws on a canvas, so DaisyUI colours are read from the CSS variables on <html> and
// re-read whenever the theme changes (data-theme is set by Settings, including its live preview).
const themeVersion = ref(0)
let observer = null

const watchTheme = () => {
    if (observer || typeof MutationObserver === 'undefined') return
    observer = new MutationObserver(() => themeVersion.value++)
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] })
}

let probe = null

// A theme colour as rgba(), so it can be faded (DaisyUI 5 variables are oklch() strings).
export const themeColor = (name, alpha = 1) => {
    const value = window.getComputedStyle(document.documentElement).getPropertyValue(name).trim()
    if (!value || alpha === 1) return value

    probe ??= document.createElement('canvas').getContext('2d', { willReadFrequently: true })
    probe.clearRect(0, 0, 1, 1)
    probe.fillStyle = '#000'
    probe.fillStyle = value
    probe.fillRect(0, 0, 1, 1)
    const [r, g, b] = probe.getImageData(0, 0, 1, 1).data

    return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

const NARROW_QUERY = '(max-width: 639px)'

// Theme colours for charts plus a `narrow` flag (phone width) for denser ticks and shorter labels.
export const useChartTheme = () => {
    watchTheme()

    const media = window.matchMedia(NARROW_QUERY)
    const narrow = ref(media.matches)
    const onMedia = (event) => {
        narrow.value = event.matches
    }
    onMounted(() => media.addEventListener('change', onMedia))
    onBeforeUnmount(() => media.removeEventListener('change', onMedia))

    const colors = computed(() => {
        themeVersion.value

        return {
            success: themeColor('--color-success'),
            error: themeColor('--color-error'),
            info: themeColor('--color-info'),
            text: themeColor('--color-base-content', 0.7),
            muted: themeColor('--color-base-content', 0.35),
            grid: themeColor('--color-base-content', 0.08),
        }
    })

    return { colors, narrow }
}
