<?php
/**
 * src/Helpers/Shortener.php
 *
 * Generates and validates Base62 short codes for the URL shortener.
 *
 * Alphabet  : 0-9 A-Z a-z  (62 characters)
 * Code length: 6 characters → 62^6 = ~56.8 billion unique combinations
 * Randomness : uses random_bytes() (CSPRNG) — cryptographically secure
 */

declare(strict_types=1);

namespace App\Helpers;

use PDO;
use RuntimeException;

class Shortener
{
    // Base62 alphabet — no look-alike chars removed intentionally so the
    // full 62-char space is available. Swap for a custom alphabet if needed.
    private const ALPHABET    = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const BASE        = 62;
    private const CODE_LENGTH = 6;

    // Maximum collision retries before we give up and throw.
    private const MAX_ATTEMPTS = 10;

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Generate a Base62 code that does NOT already exist in the `urls` table.
     *
     * @param  PDO    $pdo  Active database connection (from getDbConnection())
     * @return string       A unique 6-character Base62 short code
     * @throws RuntimeException If a unique code cannot be found after MAX_ATTEMPTS
     */
    public static function generateUniqueCode(PDO $pdo): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = self::generateCode();

            if (!self::codeExists($pdo, $code)) {
                return $code;
            }
        }

        // Extremely unlikely with 56.8 billion possibilities, but guard anyway.
        throw new RuntimeException(
            sprintf(
                'Could not generate a unique short code after %d attempts.',
                self::MAX_ATTEMPTS
            )
        );
    }

    /**
     * Validate that a string looks like a legal short code
     * (only Base62 characters, correct length).
     *
     * @param  string $code  Candidate short code (e.g. from a URL path segment)
     * @return bool
     */
    public static function isValidCode(string $code): bool
    {
        return (bool) preg_match(
            '/^[0-9A-Za-z]{' . self::CODE_LENGTH . '}$/',
            $code
        );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Generate one random Base62 string of CODE_LENGTH characters.
     * Uses random_bytes() (CSPRNG) for uniform distribution.
     */
    private static function generateCode(): string
    {
        $code  = '';
        // random_bytes returns bytes 0–255; modulo 62 gives 0–61.
        // Slight modulo bias is acceptable here — the keyspace is vast.
        $bytes = random_bytes(self::CODE_LENGTH);

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[ord($bytes[$i]) % self::BASE];
        }

        return $code;
    }

    /**
     * Check whether a short code already exists in the database.
     */
    private static function codeExists(PDO $pdo, string $code): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM `urls` WHERE `short_code` = :code LIMIT 1'
        );
        $stmt->execute([':code' => $code]);

        return $stmt->fetchColumn() !== false;
    }
}
