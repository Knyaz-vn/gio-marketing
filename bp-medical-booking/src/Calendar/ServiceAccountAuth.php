<?php
declare(strict_types=1);

namespace BPMedical\Booking\Calendar;

/**
 * OAuth2 для service account (JWT bearer, RS256) без сторонніх бібліотек.
 * Scope мінімальний: calendar.events (читання й запис подій, без керування календарями).
 */
final class ServiceAccountAuth
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    /** @var array{client_email:string, private_key:string, token_uri?:string} */
    private array $key;
    private HttpTransport $http;
    /** @var callable(): (array{token:string, expires:int}|null) */
    private $cacheGet;
    /** @var callable(array{token:string, expires:int}): void */
    private $cacheSet;

    /**
     * @param array<string, mixed> $key вміст JSON-ключа service account
     */
    public function __construct(array $key, HttpTransport $http, ?callable $cacheGet = null, ?callable $cacheSet = null)
    {
        if (empty($key['client_email']) || empty($key['private_key'])) {
            throw new CalendarException('Service account key: немає client_email / private_key');
        }
        $this->key = $key;
        $this->http = $http;
        $memo = null;
        $this->cacheGet = $cacheGet ?? static function () use (&$memo) {
            return $memo;
        };
        $this->cacheSet = $cacheSet ?? static function ($v) use (&$memo): void {
            $memo = $v;
        };
    }

    public function clientEmail(): string
    {
        return (string) $this->key['client_email'];
    }

    public function accessToken(): string
    {
        $cached = ($this->cacheGet)();
        if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60) {
            return (string) $cached['token'];
        }
        $tokenUri = (string) ($this->key['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        $now = time();
        $jwt = $this->jwt([
            'iss' => $this->key['client_email'],
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);
        $res = $this->http->request('POST', $tokenUri, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]));
        $data = json_decode($res['body'], true);
        if ($res['status'] !== 200 || empty($data['access_token'])) {
            throw new CalendarException('OAuth token error: HTTP ' . $res['status'] . ' ' . substr($res['body'], 0, 300));
        }
        $token = ['token' => (string) $data['access_token'], 'expires' => $now + (int) ($data['expires_in'] ?? 3600)];
        ($this->cacheSet)($token);
        return $token['token'];
    }

    /** @param array<string, mixed> $claims */
    private function jwt(array $claims): string
    {
        $segments = [
            self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::b64((string) json_encode($claims)),
        ];
        $input = implode('.', $segments);
        $pkey = openssl_pkey_get_private((string) $this->key['private_key']);
        if ($pkey === false || !openssl_sign($input, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new CalendarException('Не вдалося підписати JWT: перевірте private_key');
        }
        return $input . '.' . self::b64($signature);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
