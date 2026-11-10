<?php

namespace App\Services\Monobank;

class TransferDetector
{
    /**
     * Transfers between the user's own Mono cards/jars must not become income or expense.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $ownIbans  Uppercase, no spaces
     * @param  list<string>  $jarTitles
     */
    public static function isInternal(array $item, array $ownIbans, array $jarTitles): bool
    {
        $counterIban = strtoupper(preg_replace('/\s+/', '', (string) ($item['counterIban'] ?? '')) ?? '');

        if ($counterIban !== '' && in_array($counterIban, $ownIbans, true)) {
            return true;
        }

        $description = (string) ($item['description'] ?? '');

        foreach ([
            '/переказ між своїми/iu',
            '/на свою картк/iu',
            '/зі своєї картк/iu',
            '/з своєї картк/iu',
            '/на свій (рахунок|карт)/iu',
            '/from own (card|account)/iu',
            '/to own (card|account)/iu',
        ] as $pattern) {
            if (preg_match($pattern, $description)) {
                return true;
            }
        }

        foreach ($jarTitles as $title) {
            $title = trim($title);

            if ($title === '') {
                continue;
            }

            if (preg_match('/^на\s+' . preg_quote($title, '/') . '\b/iu', $description)) {
                return true;
            }
        }

        return false;
    }
}
