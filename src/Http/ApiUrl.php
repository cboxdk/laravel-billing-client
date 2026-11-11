<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Where the Billing API lives: the versioned base every transport appends its paths to
 * (`https://api.cboxbilling.com/v1` + `/leases`).
 *
 * Since 0.6.0 Billing serves its API on its own host at `/v1`. The app host still answers the
 * old `/api/v1` address (with `Deprecation` and `Link` headers), so an app that only set the old
 * root URL keeps working until it changes its env:
 *
 *  - `api_url` (`BILLING_CLIENT_API_URL`) is used as it is.
 *  - Otherwise a URL from the old settings (`base_url`, `service_access.url`) is taken as the app
 *    root and gets `/api/v1`, unless its path already ends in `/v1`.
 *  - With neither, the hosted API, {@see self::HOSTED}.
 *
 * An app that calls Billing outside the client builds its URLs on {@see fromConfig()}, so it
 * follows the same setting: `ApiUrl::fromConfig(config()).'/subscriptions/'.$org`.
 */
class ApiUrl
{
    public const string HOSTED = 'https://api.cboxbilling.com/v1';

    /** The API URL the client's own transports use, from `billing-client.api_url` / `base_url`. */
    public static function fromConfig(Config $config): string
    {
        $api = $config->get('billing-client.api_url');
        $legacy = $config->get('billing-client.base_url');

        return self::resolve(is_string($api) ? $api : null, is_string($legacy) ? $legacy : null);
    }

    public static function resolve(?string $apiUrl, ?string $legacyBaseUrl = null): string
    {
        $apiUrl = self::filled($apiUrl);
        if ($apiUrl !== null) {
            return rtrim($apiUrl, '/');
        }

        $legacy = self::filled($legacyBaseUrl);

        return $legacy === null ? self::HOSTED : self::fromLegacy($legacy);
    }

    /**
     * An old-style root URL (`https://app.cboxbilling.com`) as the versioned API base
     * (`https://app.cboxbilling.com/api/v1`). A URL whose path already ends in `/v1` is kept.
     */
    public static function fromLegacy(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path) && preg_match('#(^|/)v1$#D', $path) === 1) {
            return $url;
        }

        return $url.'/api/v1';
    }

    private static function filled(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
