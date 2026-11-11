<?php

namespace App\Support\Currency;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Official National Bank of Ukraine exchange rates (UAH per 1 unit of a currency) for a date.
 * NBU sets a rate for every calendar day (weekends repeat the last business day), so past dates
 * are cached for a long time and today for an hour. Returns null when NBU has no rate.
 */
final class NbuRates
{
    public const ENDPOINT = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange';

    /** Words and symbols people use for currencies (Ukrainian, Russian, English). */
    private const ALIASES = [
        'UAH' => ['uah', '₴', 'грн', 'гривня', 'гривні', 'гривень', 'hryvnia'],
        'USD' => ['usd', '$', 'долар', 'доларів', 'долари', 'долара', 'доллар', 'долларов', 'dollar', 'dollars', 'бакс', 'баксів'],
        'EUR' => ['eur', '€', 'євро', 'евро', 'euro', 'euros'],
        'PLN' => ['pln', 'zł', 'zl', 'злотий', 'злотих', 'злоті', 'zloty'],
        'GBP' => ['gbp', '£', 'фунт', 'фунтів', 'pound', 'pounds'],
        'CHF' => ['chf', 'франк', 'франків', 'franc', 'francs'],
        'CZK' => ['czk', 'крона', 'крон', 'koruna'],
        'JPY' => ['jpy', '¥', 'єна', 'єн', 'yen'],
        'CAD' => ['cad'],
        'TRY' => ['try', '₺', 'ліра', 'лір', 'lira'],
    ];

    /**
     * ISO 4217 code for a code, symbol or word ("доларів" → USD), or null.
     */
    public static function normalize(string $currency): ?string
    {
        $value = mb_strtolower(trim($currency));
        if ($value === '') {
            return null;
        }
        foreach (self::ALIASES as $code => $aliases) {
            if (in_array($value, $aliases, true)) {
                return $code;
            }
        }

        return preg_match('/^[a-z]{3}$/', $value) === 1 ? strtoupper($value) : null;
    }

    /**
     * @return array{currency: string, rate: float, rate_date: string}|null
     */
    public function rate(string $currency, string $date): ?array
    {
        $code = self::normalize($currency);
        if ($code === null) {
            return null;
        }
        if ($code === 'UAH') {
            return ['currency' => 'UAH', 'rate' => 1.0, 'rate_date' => $date];
        }

        $day = CarbonImmutable::parse($date)->startOfDay();
        $today = CarbonImmutable::now('Europe/Kyiv')->startOfDay();
        // NBU sets tomorrow's rate in the afternoon; never ask for a later day than today.
        if ($day->greaterThan($today)) {
            $day = $today;
        }

        $key = "nbu-rate:{$code}:" . $day->format('Ymd');
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get(self::ENDPOINT, [
                'valcode' => $code,
                'date' => $day->format('Ymd'),
                'json' => '',
            ]);
            $row = $response->ok() ? ($response->json()[0] ?? null) : null;
        } catch (Throwable $e) {
            Log::warning('NbuRates: request failed', ['currency' => $code, 'message' => $e->getMessage()]);

            return null;
        }

        if (!is_array($row) || !is_numeric($row['rate'] ?? null) || (float) $row['rate'] <= 0) {
            return null;
        }

        $rateDate = is_string($row['exchangedate'] ?? null)
            ? CarbonImmutable::createFromFormat('d.m.Y', $row['exchangedate'])->toDateString()
            : $day->toDateString();
        $result = ['currency' => $code, 'rate' => (float) $row['rate'], 'rate_date' => $rateDate];
        Cache::put($key, $result, $day->equalTo($today) ? now()->addHour() : now()->addDays(30));

        return $result;
    }

    /**
     * Convert an amount in $currency to UAH at the NBU rate for $date (sign kept).
     *
     * @return array{amount: float, currency: string, rate: float, rate_date: string, uah: float}|null
     */
    public function toUah(float $amount, string $currency, string $date): ?array
    {
        $rate = $this->rate($currency, $date);
        if ($rate === null) {
            return null;
        }

        return [
            'amount' => $amount,
            'currency' => $rate['currency'],
            'rate' => $rate['rate'],
            'rate_date' => $rate['rate_date'],
            'uah' => round($amount * $rate['rate'], 2),
        ];
    }
}
