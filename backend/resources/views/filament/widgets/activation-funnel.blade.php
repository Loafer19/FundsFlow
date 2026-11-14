@php
    $steps = [
        ['key' => 'signups', 'label' => 'Signed up', 'hint' => 'New accounts'],
        ['key' => 'active', 'label' => 'Active after signup', 'hint' => 'Came back, opened the app, or added a transaction'],
        ['key' => 'opened_web', 'label' => 'Opened web', 'hint' => 'Used the website or Mini App'],
        ['key' => 'first_tx', 'label' => 'First transaction', 'hint' => 'Any channel'],
    ];
    $total = max(1, (int) $signups);
    $methodLabels = [
        'email' => 'Email',
        'google' => 'Google',
        'github' => 'GitHub',
        'telegram' => 'Telegram',
    ];
    $pct = fn (int $n): int => (int) round($n / $total * 100);
    $visibleMethods = array_filter($by_method, fn (array $row): bool => $row['signups'] > 0);
@endphp

<x-filament-widgets::widget class="fi-wi-activation-funnel">
    <style>
        .ff-funnel { display: flex; flex-direction: column; gap: 1rem; }
        .ff-funnel-grid { display: grid; grid-template-columns: 1fr; gap: 0.75rem; }
        @media (min-width: 640px) { .ff-funnel-grid { grid-template-columns: 1fr 1fr; } }
        @media (min-width: 1280px) { .ff-funnel-grid { grid-template-columns: 1fr 1fr 1fr 1fr; } }
        .ff-funnel-card {
            border-radius: 0.75rem;
            padding: 1rem;
            background: rgba(var(--gray-950-rgb, 12, 10, 9), 0.04);
            box-shadow: inset 0 0 0 1px rgba(var(--gray-950-rgb, 12, 10, 9), 0.06);
        }
        html.dark .ff-funnel-card {
            background: rgba(255, 255, 255, 0.05);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1);
        }
        .ff-funnel-label { font-size: 0.875rem; font-weight: 500; color: var(--gray-500, #78716c); }
        .ff-funnel-value { margin-top: 0.25rem; display: flex; align-items: baseline; gap: 0.5rem; }
        .ff-funnel-count { font-size: 1.5rem; font-weight: 600; letter-spacing: -0.025em; color: var(--gray-950, #0c0a09); }
        html.dark .ff-funnel-count { color: #fff; }
        .ff-funnel-pct { font-size: 0.875rem; color: var(--gray-500, #78716c); }
        .ff-funnel-hint { margin: 0.25rem 0 0; font-size: 0.75rem; color: var(--gray-500, #78716c); }
        .ff-funnel-track { margin-top: 0.75rem; height: 0.5rem; overflow: hidden; border-radius: 999px; background: rgba(var(--gray-950-rgb, 12, 10, 9), 0.08); }
        html.dark .ff-funnel-track { background: rgba(255, 255, 255, 0.1); }
        .ff-funnel-bar { height: 100%; border-radius: 999px; background: var(--primary-500, #f59e0b); }
        .ff-funnel-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .ff-funnel-table th { text-align: left; font-weight: 500; color: var(--gray-500, #78716c); padding: 0 1rem 0.5rem 0; }
        .ff-funnel-table th.num, .ff-funnel-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .ff-funnel-table td { padding: 0.5rem 1rem 0.5rem 0; border-top: 1px solid rgba(var(--gray-950-rgb, 12, 10, 9), 0.08); }
        html.dark .ff-funnel-table td { border-top-color: rgba(255, 255, 255, 0.1); }
        .ff-funnel-table td.method { font-weight: 500; color: var(--gray-950, #0c0a09); }
        html.dark .ff-funnel-table td.method { color: #fff; }
        .ff-funnel-empty { margin: 0; font-size: 0.875rem; color: var(--gray-500, #78716c); }
    </style>

    <x-filament::section
        heading="Activation funnel"
        :description="'Last ' . $days . ' days' . ($median_label ? ' · median time to first transaction: ' . $median_label : '')"
    >
        <div class="ff-funnel">
            <div class="ff-funnel-grid">
                @foreach ($steps as $index => $step)
                    @php
                        $count = (int) ${$step['key']};
                        $width = $pct($count);
                    @endphp
                    <div class="ff-funnel-card">
                        <div class="ff-funnel-label">{{ $index + 1 }}. {{ $step['label'] }}</div>
                        <div class="ff-funnel-value">
                            <span class="ff-funnel-count">{{ number_format($count) }}</span>
                            @if ($index > 0)
                                <span class="ff-funnel-pct">{{ $width }}%</span>
                            @endif
                        </div>
                        <p class="ff-funnel-hint">{{ $step['hint'] }}</p>
                        <div class="ff-funnel-track">
                            <div class="ff-funnel-bar" style="width: {{ $width }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($visibleMethods !== [])
                <div style="overflow-x: auto">
                    <table class="ff-funnel-table">
                        <thead>
                            <tr>
                                <th>Sign-up method</th>
                                <th class="num">Signed up</th>
                                <th class="num">Active</th>
                                <th class="num">Opened web</th>
                                <th class="num">First transaction</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($visibleMethods as $method => $row)
                                <tr>
                                    <td class="method">{{ $methodLabels[$method] ?? $method }}</td>
                                    <td class="num">{{ number_format($row['signups']) }}</td>
                                    <td class="num">
                                        {{ number_format($row['active']) }}
                                        <span class="ff-funnel-pct">({{ $row['signups'] ? (int) round($row['active'] / $row['signups'] * 100) : 0 }}%)</span>
                                    </td>
                                    <td class="num">
                                        {{ number_format($row['opened_web']) }}
                                        <span class="ff-funnel-pct">({{ $row['signups'] ? (int) round($row['opened_web'] / $row['signups'] * 100) : 0 }}%)</span>
                                    </td>
                                    <td class="num">
                                        {{ number_format($row['first_tx']) }}
                                        <span class="ff-funnel-pct">({{ $row['signups'] ? (int) round($row['first_tx'] / $row['signups'] * 100) : 0 }}%)</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="ff-funnel-empty">No sign-ups in this period.</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
