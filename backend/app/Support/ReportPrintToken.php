<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One-time, read-only Sanctum token that lets Gotenberg's Chromium open the SPA
 * in print mode (/?print=report#t=…) and load exactly one report.
 *
 * - ability `report:print` only; accepted on GET report.print-data and nowhere else
 *   (enforced for every Sanctum request in AppServiceProvider)
 * - expires after TTL_SECONDS; revoked as soon as the page loaded its data, and again
 *   (idempotently) when the PDF render finishes or fails
 * - the plain token travels in the URL fragment, so it never reaches an HTTP request
 *   line, access log or Referer; never log it — use redact() on any message that may carry the URL
 */
final class ReportPrintToken
{
    public const NAME = 'report-print';

    public const ABILITY = 'report:print';

    public const TTL_SECONDS = 120;

    /** Route names a report token may call (GET only). */
    public const ROUTES = ['report.print-data'];

    /**
     * @param array<string, mixed> $payload report parameters the print page reads back
     * @return array{token: string, id: int}
     */
    public static function mint(User $user, array $payload): array
    {
        $new = $user->createToken(self::NAME, [self::ABILITY], now()->addSeconds(self::TTL_SECONDS));
        $id = (int) $new->accessToken->getKey();

        Cache::put(self::cacheKey($id), $payload, self::TTL_SECONDS + 30);

        return ['token' => $new->plainTextToken, 'id' => $id];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function payload(PersonalAccessToken $token): ?array
    {
        $payload = Cache::get(self::cacheKey((int) $token->getKey()));

        return is_array($payload) ? $payload : null;
    }

    public static function revoke(int $id): void
    {
        PersonalAccessToken::query()->whereKey($id)->where('name', self::NAME)->delete();
        Cache::forget(self::cacheKey($id));
    }

    public static function isReportToken(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken
            && $token->name === self::NAME
            && $token->can(self::ABILITY);
    }

    /**
     * Whether a (otherwise valid) Sanctum token may authenticate this request.
     * Full tokens (`*`) pass; a report token only on its read endpoint; any other
     * scoped token is denied, so new scoped tokens are closed by default.
     */
    public static function allows(PersonalAccessToken $token, ?Request $request): bool
    {
        if ($token->can('*')) {
            return true;
        }

        if (! self::isReportToken($token) || $request === null) {
            return false;
        }

        return $request->isMethod('GET')
            && in_array($request->route()?->getName(), self::ROUTES, true);
    }

    /** Mask a print token in a URL / error text before it is logged or returned. */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/([#?&]t=)[^&\s"\'<>]+/', '$1[redacted]', $text);
    }

    private static function cacheKey(int $id): string
    {
        return 'report-print:' . $id;
    }
}
