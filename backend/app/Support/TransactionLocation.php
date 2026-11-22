<?php

namespace App\Support;

/**
 * A transaction's place: {lat, lng, title?, address?} from a Telegram location or venue.
 */
final class TransactionLocation
{
    /**
     * @param array<string, mixed> $message Telegram message with location (and maybe venue)
     * @return null|array{lat: float, lng: float, title?: string, address?: string}
     */
    public static function fromTelegram(array $message): ?array
    {
        $location = $message['venue']['location'] ?? $message['location'] ?? null;

        if (!is_array($location) || !is_numeric($location['latitude'] ?? null) || !is_numeric($location['longitude'] ?? null)) {
            return null;
        }

        $place = [
            'lat' => round((float) $location['latitude'], 6),
            'lng' => round((float) $location['longitude'], 6),
        ];

        foreach (['title' => 120, 'address' => 255] as $key => $max) {
            $value = trim((string) ($message['venue'][$key] ?? ''));

            if ($value !== '') {
                $place[$key] = mb_substr($value, 0, $max);
            }
        }

        return $place;
    }

    /**
     * @param array<string, mixed> $location
     */
    public static function mapUrl(array $location): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . $location['lat'] . ',' . $location['lng'];
    }
}
