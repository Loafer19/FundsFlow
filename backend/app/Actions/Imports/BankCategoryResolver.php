<?php

namespace App\Actions\Imports;

/**
 * Default mapping from a bank file's category names to the user's tags:
 * case-insensitive exact title, then a small UA/RU → English synonym list
 * that matches the default tag tree (DefaultTagsSeeder).
 */
final class BankCategoryResolver
{
    public const ACTION_MAP = 'map';

    public const ACTION_CREATE = 'create';

    public const ACTION_IGNORE = 'ignore';

    /**
     * Lowercase synonym (UA / RU / extra English) => default English tag title.
     *
     * @var array<string, string>
     */
    public const SYNONYMS = [
        'продукти' => 'Groceries',
        'продукты' => 'Groceries',
        'супермаркети' => 'Groceries',
        'супермаркеты' => 'Groceries',
        'супермаркет' => 'Groceries',
        'продукти харчування' => 'Groceries',
        'продукты питания' => 'Groceries',
        'groceries' => 'Groceries',

        'ресторани' => 'Restaurants',
        'рестораны' => 'Restaurants',
        'ресторан' => 'Restaurants',
        'кафе' => 'Restaurants',
        'кафе та ресторани' => 'Restaurants',
        'кафе и рестораны' => 'Restaurants',
        'restaurants' => 'Restaurants',

        'кава' => 'Coffee',
        'кофе' => 'Coffee',
        'coffee' => 'Coffee',

        'доставка' => 'Delivery',
        'delivery' => 'Delivery',

        'їжа' => 'Food & dining',
        'еда' => 'Food & dining',
        'харчування' => 'Food & dining',
        'питание' => 'Food & dining',
        'food' => 'Food & dining',
        'food & dining' => 'Food & dining',

        'покупки' => 'Shopping',
        'шопінг' => 'Shopping',
        'шопинг' => 'Shopping',
        'магазини' => 'Shopping',
        'магазины' => 'Shopping',
        'shopping' => 'Shopping',

        'одяг' => 'Clothes',
        'одежда' => 'Clothes',
        'clothes' => 'Clothes',

        'техніка' => 'Electronics',
        'техника' => 'Electronics',
        'електроніка' => 'Electronics',
        'электроника' => 'Electronics',
        'electronics' => 'Electronics',

        'побут' => 'Household',
        'быт' => 'Household',
        'господарство' => 'Household',
        'household' => 'Household',

        'таксі' => 'Taxi / ride-hail',
        'такси' => 'Taxi / ride-hail',
        'taxi' => 'Taxi / ride-hail',

        'пальне' => 'Fuel',
        'паливо' => 'Fuel',
        'топливо' => 'Fuel',
        'заправка' => 'Fuel',
        'азс' => 'Fuel',
        'fuel' => 'Fuel',

        'авто' => 'Transport',
        'транспорт' => 'Transport',
        'автомобіль' => 'Transport',
        'автомобиль' => 'Transport',
        'transport' => 'Transport',

        'громадський транспорт' => 'Public transit',
        'общественный транспорт' => 'Public transit',
        'проїзд' => 'Public transit',
        'проезд' => 'Public transit',
        'public transit' => 'Public transit',

        'парковка' => 'Parking',
        'parking' => 'Parking',

        'дохід' => 'Income',
        'доход' => 'Income',
        'доходы' => 'Income',
        'надходження' => 'Income',
        'поступления' => 'Income',
        'income' => 'Income',

        'зарплата' => 'Salary',
        'зп' => 'Salary',
        'salary' => 'Salary',

        "зв'язок" => 'Phone',
        'связь' => 'Phone',
        'телефон' => 'Phone',
        'мобільний' => 'Phone',
        'мобильный' => 'Phone',
        'phone' => 'Phone',

        'підписки' => 'Bills & subscriptions',
        'подписки' => 'Bills & subscriptions',
        'підписка' => 'Bills & subscriptions',
        'подписка' => 'Bills & subscriptions',
        'bills & subscriptions' => 'Bills & subscriptions',

        'streaming' => 'Streaming',
        'стрімінг' => 'Streaming',

        'розваги' => 'Entertainment',
        'развлечения' => 'Entertainment',
        'entertainment' => 'Entertainment',

        "здоров'я" => 'Health',
        'здоровье' => 'Health',
        'медицина' => 'Health',
        'health' => 'Health',

        'аптека' => 'Pharmacy',
        'pharmacy' => 'Pharmacy',

        'лікар' => 'Doctor',
        'врач' => 'Doctor',
        'doctor' => 'Doctor',

        'спорт' => 'Fitness',
        'фітнес' => 'Fitness',
        'фитнес' => 'Fitness',
        'fitness' => 'Fitness',

        'житло' => 'Housing',
        'жилье' => 'Housing',
        'housing' => 'Housing',

        'комунальні' => 'Utilities',
        'коммунальные' => 'Utilities',
        'utilities' => 'Utilities',

        'інтернет' => 'Internet',
        'интернет' => 'Internet',
        'internet' => 'Internet',

        'оренда' => 'Rent / mortgage',
        'аренда' => 'Rent / mortgage',
        'rent / mortgage' => 'Rent / mortgage',

        'особисті' => 'Personal',
        'личные' => 'Personal',
        'personal' => 'Personal',

        'освіта' => 'Education',
        'образование' => 'Education',
        'education' => 'Education',

        'подорожі' => 'Travel',
        'путешествия' => 'Travel',
        'travel' => 'Travel',

        'готелі' => 'Hotels',
        'отели' => 'Hotels',
        'hotels' => 'Hotels',

        'авіаквитки' => 'Flights',
        'авиабилеты' => 'Flights',
        'flights' => 'Flights',

        'інше' => 'Other',
        'другое' => 'Other',
        'other' => 'Other',
    ];

    /**
     * @param  array<string, int>  $tagsByTitle  lowercase title => tag id
     * @return array{action: string, tag_id?: int, title?: string}
     */
    public function defaultDecision(string $name, array $tagsByTitle): array
    {
        $lower = mb_strtolower(trim($name));
        if ($lower === '') {
            return ['action' => self::ACTION_IGNORE];
        }
        if (isset($tagsByTitle[$lower])) {
            return ['action' => self::ACTION_MAP, 'tag_id' => $tagsByTitle[$lower]];
        }
        $mapped = self::SYNONYMS[$lower] ?? null;
        if ($mapped !== null && isset($tagsByTitle[mb_strtolower($mapped)])) {
            return ['action' => self::ACTION_MAP, 'tag_id' => $tagsByTitle[mb_strtolower($mapped)]];
        }

        return ['action' => self::ACTION_IGNORE];
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<int, true>  $ownedTagIds
     * @return array{action: string, tag_id?: int, title?: string}|null
     */
    public function normalizeDecision(array $decision, array $ownedTagIds, ?string $fallbackTitle = null): ?array
    {
        $action = $decision['action'] ?? null;
        if ($action === self::ACTION_IGNORE) {
            return ['action' => self::ACTION_IGNORE];
        }
        if ($action === self::ACTION_MAP) {
            $id = isset($decision['tag_id']) ? (int) $decision['tag_id'] : 0;
            if ($id > 0 && isset($ownedTagIds[$id])) {
                return ['action' => self::ACTION_MAP, 'tag_id' => $id];
            }

            return null;
        }
        if ($action === self::ACTION_CREATE) {
            $title = trim((string) ($decision['title'] ?? $fallbackTitle ?? ''));
            if ($title === '') {
                return null;
            }

            return ['action' => self::ACTION_CREATE, 'title' => mb_substr($title, 0, 80)];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>|null
     */
    public function findDecision(array $map, string $name): ?array
    {
        if (isset($map[$name]) && is_array($map[$name])) {
            return $map[$name];
        }
        $lower = mb_strtolower($name);
        foreach ($map as $key => $decision) {
            if (is_string($key) && mb_strtolower($key) === $lower && is_array($decision)) {
                return $decision;
            }
        }

        return null;
    }

    /**
     * Existing key for $name, or $name itself when the map has no match.
     *
     * @param  array<string, mixed>  $map
     */
    public function canonicalName(array $map, string $name): string
    {
        if (array_key_exists($name, $map)) {
            return $name;
        }
        $lower = mb_strtolower($name);
        foreach (array_keys($map) as $key) {
            if (is_string($key) && mb_strtolower($key) === $lower) {
                return $key;
            }
        }

        return $name;
    }
}
