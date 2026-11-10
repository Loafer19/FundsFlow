<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Web = 'web';
    case Telegram = 'telegram';
    case Recurring = 'recurring';
    case Mcp = 'mcp';
    case Monobank = 'monobank';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web app',
            self::Telegram => 'Telegram',
            self::Recurring => 'Recurring',
            self::Mcp => 'MCP / AI clients',
            self::Monobank => 'Monobank',
            self::Import => 'File import',
        };
    }

    /** Chart.js hex, readable on both the light and the dark theme. */
    public function chartColor(): string
    {
        return match ($this) {
            self::Web => '#3b82f6',
            self::Telegram => '#0ea5e9',
            self::Recurring => '#a855f7',
            self::Mcp => '#10b981',
            self::Monobank => '#f97316',
            self::Import => '#ec4899',
        };
    }

    /** Filament badge color name (registered on the admin panel). */
    public function badgeColor(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $source) {
            $options[$source->value] = $source->label();
        }

        return $options;
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom((string) $value);
    }
}
