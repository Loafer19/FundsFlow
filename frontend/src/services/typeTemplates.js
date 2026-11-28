// Starting points for "New type": the types people usually want. A field's key follows its label.
export const TYPE_TEMPLATES = [
    {
        name: 'Fuel',
        emoji: '⛽',
        color: '#f59e0b',
        fields: [
            { label: 'Liters', type: 'number', unit: 'l' },
            { label: 'Price per liter', type: 'number', unit: '₴' },
            { label: 'Fuel', type: 'select', options: ['A-95', 'A-95+', 'Diesel', 'LPG'] },
            { label: 'Station', type: 'text' },
            { label: 'Total', type: 'computed', formula: 'liters * price_per_liter', unit: '₴', fill_amount: true },
        ],
    },
    {
        name: 'Restaurant',
        emoji: '🍽️',
        color: '#ec4899',
        fields: [
            { label: 'Place', type: 'text' },
            { label: 'Format', type: 'select', options: ['Dine-in', 'Takeaway', 'Delivery'] },
            { label: 'Tip', type: 'number', unit: '₴' },
            { label: 'Rating', type: 'rating', max: 5 },
        ],
    },
    {
        name: 'Subscription',
        emoji: '🔄',
        color: '#6366f1',
        fields: [
            { label: 'Service', type: 'text' },
            { label: 'Period', type: 'select', options: ['Monthly', 'Yearly'] },
            { label: 'Next charge', type: 'date' },
        ],
    },
    {
        name: 'Workout',
        emoji: '🏋️',
        color: '#14b8a6',
        fields: [
            { label: 'Gym', type: 'select', options: ['Gym', 'Home', 'Outdoors'] },
            { label: 'Duration', type: 'duration' },
            { label: 'Effort', type: 'rating', max: 5 },
            { label: 'Muscles', type: 'multiselect', options: ['Legs', 'Back', 'Chest', 'Arms'] },
            { label: 'Cardio', type: 'boolean' },
        ],
    },
    {
        name: 'Trip',
        emoji: '✈️',
        color: '#3b82f6',
        fields: [
            { label: 'City', type: 'text' },
            { label: 'Nights', type: 'number', min: 1 },
            { label: 'Price per night', type: 'number', unit: '₴' },
            { label: 'Total', type: 'computed', formula: 'nights * price_per_night', unit: '₴', fill_amount: true },
        ],
    },
]
