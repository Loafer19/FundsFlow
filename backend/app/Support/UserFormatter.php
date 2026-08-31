<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use NumberFormatter;

class UserFormatter
{
    /**
     * Mirrors frontend/src/services/formatters.js dateFormatMap.
     *
     * @var array<string, array{locale: ?string, pattern: string}>
     */
    private const DATE_FORMATS = [
        'YYYY/MM/DD' => ['locale' => 'zh-CN', 'pattern' => 'yyyy/MM/dd'],
        'MM/DD/YYYY' => ['locale' => 'en-US', 'pattern' => 'MM/dd/yyyy'],
        'MM/DD' => ['locale' => 'en-US', 'pattern' => 'MM/dd'],
        'long Month with Day & Year' => ['locale' => null, 'pattern' => 'dd MMMM yyyy'],
        'short Month with Day & Year' => ['locale' => null, 'pattern' => 'dd MMM yyyy'],
        'long Month with Day' => ['locale' => null, 'pattern' => 'dd MMMM'],
        'short Month with Day' => ['locale' => null, 'pattern' => 'dd MMM'],
        'DD-MM-YYYY' => ['locale' => 'nl-NL', 'pattern' => 'dd-MM-yyyy'],
        'DD/MM/YYYY' => ['locale' => 'en-GB', 'pattern' => 'dd/MM/yyyy'],
        'DD.MM.YYYY' => ['locale' => 'uk-UA', 'pattern' => 'dd.MM.yyyy'],
        'DD-MM' => ['locale' => 'nl-NL', 'pattern' => 'dd-MM'],
        'DD/MM' => ['locale' => 'en-GB', 'pattern' => 'dd/MM'],
        'DD.MM' => ['locale' => 'uk-UA', 'pattern' => 'dd.MM'],
    ];

    public static function formatMoney(User $user, float|string $amount): string
    {
        $amount = (float) $amount;
        $locale = $user->moneyFormat();
        $decimals = $user->showDecimals();

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals ? 2 : 0);

            $formatted = $formatter->format($amount);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        return number_format($amount, $decimals ? 2 : 0, '.', ',');
    }

    public static function formatDate(User $user, CarbonInterface|string $date): string
    {
        $carbon = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
        $key = $user->dateFormat();
        $config = self::DATE_FORMATS[$key] ?? self::DATE_FORMATS['DD.MM.YYYY'];
        $locale = $config['locale'] ?? (string) config('app.locale', 'en');

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $carbon->getTimezone()->getName(),
                \IntlDateFormatter::GREGORIAN,
                $config['pattern'],
            );

            $formatted = $formatter->format($carbon->toDateTime());

            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $carbon->format(match ($key) {
            'YYYY/MM/DD' => 'Y/m/d',
            'MM/DD/YYYY' => 'm/d/Y',
            'MM/DD' => 'm/d',
            'DD-MM-YYYY' => 'd-m-Y',
            'DD/MM/YYYY' => 'd/m/Y',
            'DD.MM.YYYY' => 'd.m.Y',
            'DD-MM' => 'd-m',
            'DD/MM' => 'd/m',
            'DD.MM' => 'd.m',
            default => 'd.m.Y',
        });
    }
}
