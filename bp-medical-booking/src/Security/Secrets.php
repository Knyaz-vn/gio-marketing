<?php
declare(strict_types=1);

namespace BPMedical\Booking\Security;

/**
 * Секрети НЕ зберігаються в БД і в репозиторії. Порядок пошуку:
 *  1. константа в wp-config.php (define('BPMB_TELEGRAM_BOT_TOKEN', '...'));
 *  2. змінна оточення (getenv);
 *  3. файл .env поза webroot: BPMB_ENV_FILE або <каталог над WordPress>/.env.
 *
 * Ключі: BPMB_GOOGLE_SA_FILE (шлях до JSON-ключа) або BPMB_GOOGLE_SA_JSON (JSON / base64),
 *        BPMB_TELEGRAM_BOT_TOKEN, BPMB_TURNSTILE_SECRET.
 */
final class Secrets
{
    /** @var array<string, string>|null */
    private static ?array $env = null;

    public static function get(string $name): ?string
    {
        if (defined($name)) {
            $v = (string) constant($name);
            return $v !== '' ? $v : null;
        }
        $v = getenv($name);
        if (is_string($v) && $v !== '') {
            return $v;
        }
        $env = self::envFile();
        return isset($env[$name]) && $env[$name] !== '' ? $env[$name] : null;
    }

    /** @return array<string, mixed>|null */
    public static function googleServiceAccount(): ?array
    {
        $json = null;
        $file = self::get('BPMB_GOOGLE_SA_FILE');
        if ($file !== null && is_readable($file)) {
            $json = (string) file_get_contents($file);
        } else {
            $raw = self::get('BPMB_GOOGLE_SA_JSON');
            if ($raw !== null) {
                $json = ltrim($raw)[0] === '{' ? $raw : (string) base64_decode($raw, true);
            }
        }
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) && !empty($data['client_email']) && !empty($data['private_key']) ? $data : null;
    }

    /** @return array<string, string> */
    private static function envFile(): array
    {
        if (self::$env !== null) {
            return self::$env;
        }
        self::$env = [];
        $path = defined('BPMB_ENV_FILE') ? (string) constant('BPMB_ENV_FILE') : (defined('ABSPATH') ? dirname(rtrim(ABSPATH, '/')) . '/.env' : '');
        if ($path === '' || !is_readable($path)) {
            return self::$env;
        }
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if (strpos($k, 'BPMB_') !== 0) {
                continue;
            }
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            }
            self::$env[$k] = $v;
        }
        return self::$env;
    }
}
