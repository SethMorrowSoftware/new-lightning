<?php
/**
 * Authenticated encryption for secrets stored in the database.
 *
 * Slack bot tokens and SMTP passwords are encrypted at rest with the
 * installation's app key, so a database dump or a stray backup does not hand
 * over a token that can post into someone's Slack workspace.
 */
declare(strict_types=1);

namespace StormWatch;

final class Crypto
{
    private const PREFIX_SODIUM = 'sw1s:';
    private const PREFIX_OPENSSL = 'sw1o:';

    /** Resolve the 32-byte binary key from the configured app key. */
    private static function key(): string
    {
        $appKey = (string) Config::get('app_key', '');
        if ($appKey === '') {
            throw new \RuntimeException('No app key configured. Run the setup wizard.');
        }
        if (strncmp($appKey, 'base64:', 7) === 0) {
            $raw = base64_decode(substr($appKey, 7), true);
            if ($raw === false) {
                throw new \RuntimeException('The configured app key is not valid base64.');
            }
        } else {
            $raw = $appKey;
        }
        // Normalise whatever length we were given to exactly 32 bytes.
        return hash('sha256', $raw, true);
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $key = self::key();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return self::PREFIX_SODIUM . base64_encode($nonce . $cipher);
        }

        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('Neither sodium nor openssl is available; cannot store secrets safely.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return self::PREFIX_OPENSSL . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Decrypt a stored secret. Returns '' when the value cannot be read —
     * for example after the app key was regenerated — so the app degrades to
     * "the token is missing, please re-enter it" instead of fataling.
     */
    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        if (strncmp($stored, self::PREFIX_SODIUM, 5) === 0) {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                return '';
            }
            $raw = base64_decode(substr($stored, 5), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return '';
            }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            try {
                $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
            } catch (\Throwable $e) {
                return '';
            }
            return $plain === false ? '' : $plain;
        }

        if (strncmp($stored, self::PREFIX_OPENSSL, 5) === 0) {
            $raw = base64_decode(substr($stored, 5), true);
            if ($raw === false || strlen($raw) <= 28) {
                return '';
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            try {
                $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
            } catch (\Throwable $e) {
                return '';
            }
            return $plain === false ? '' : $plain;
        }

        // Unprefixed values predate encryption (or were hand-edited) — pass through.
        return $stored;
    }

    /** URL-safe random token, used for ingest, kiosk and cron secrets. */
    public static function randomToken(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
