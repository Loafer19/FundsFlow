// One colour per tag, derived from its id, so a tag looks the same in the donut, its legend and the list
// (and after a reload). Hues are spread by the golden angle so neighbouring ids never look alike.
export const NEUTRAL_TAG_COLOR = 'oklch(68% 0.02 260)'

export const tagColor = (id) =>
    typeof id === 'number' ? `oklch(74% 0.13 ${Math.round((id * 137.508) % 360)})` : NEUTRAL_TAG_COLOR
