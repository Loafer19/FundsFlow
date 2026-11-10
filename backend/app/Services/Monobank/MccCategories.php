<?php

namespace App\Services\Monobank;

/**
 * MCC → default FundsFlow tag title (DefaultTagsSeeder names).
 * Applied only when the user still has a tag with that title.
 */
class MccCategories
{
    public static function tagTitleFor(?int $mcc): ?string
    {
        if ($mcc === null || $mcc <= 0) {
            return null;
        }

        if ($mcc >= 3000 && $mcc <= 3299) {
            return 'Flights';
        }

        return match ($mcc) {
            5411, 5412, 5422, 5441, 5451, 5462, 5499 => 'Groceries',
            5812, 5813 => 'Restaurants',
            5814 => 'Delivery',
            5811 => 'Restaurants',
            5541, 5542, 5983 => 'Fuel',
            4111, 4112, 4131 => 'Public transit',
            4121 => 'Taxi / ride-hail',
            4784, 7523 => 'Parking',
            5912 => 'Pharmacy',
            8011, 8021, 8031, 8041, 8042, 8043, 8049, 8062, 8099 => 'Doctor',
            7941, 7997 => 'Fitness',
            5611, 5621, 5631, 5641, 5651, 5661, 5691, 5699 => 'Clothes',
            5732, 5045 => 'Electronics',
            5200, 5211, 5251, 5261, 5712, 5719, 5722 => 'Household',
            5300, 5309, 5310, 5311, 5331, 5399 => 'Shopping',
            4814 => 'Phone',
            4899, 5815, 5818, 7841 => 'Streaming',
            5816, 7993, 7994 => 'Games',
            5734, 7372 => 'Software',
            6300, 6381 => 'Insurance',
            4900 => 'Utilities',
            4816 => 'Internet',
            6513 => 'Rent / mortgage',
            1520, 1711, 1731, 1740, 1750, 1761, 1771, 1799 => 'Home maintenance',
            8211, 8220, 8241, 8244, 8249, 8299, 8351 => 'Education',
            742, 5995 => 'Pets',
            7230, 7297, 7298 => 'Care',
            7011 => 'Hotels',
            4511 => 'Flights',
            7512 => 'Local transport',
            7832, 7922, 7929, 7932, 7933, 7991, 7998, 7999 => 'Entertainment',
            5947 => 'Gifts',
            default => null,
        };
    }
}
