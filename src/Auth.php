<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class Auth
{
    // -------------------------------------------------------------------------
    // Configuration constants
    // -------------------------------------------------------------------------

    /** bcrypt cost factor — increase for stronger hashing on faster hardware. */
    private const BCRYPT_COST = 12;

    /** Session key that stores the logged-in user's ID. */
    public const SESSION_USER_ID   = 'user_id';

    /** Session key that stores the logged-in user's username. */
    public const SESSION_USERNAME  = 'username';

    /** Session key that stores the logged-in user's email. */
    public const SESSION_EMAIL     = 'email';

    /** CSRF token session key. */
    public const SESSION_CSRF      = '_csrf_token';

    /** Rate-limit: max failed login attempts per window. */
    private const RATE_LIMIT_MAX     = 5;

    /** Rate-limit window in seconds (15 minutes). */
    private const RATE_LIMIT_WINDOW  = 900;

    /** Session key for rate-limit state. */
    private const SESSION_RL_ATTEMPTS = '_login_attempts';
    private const SESSION_RL_WINDOW   = '_login_window';

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(private PDO $pdo) {}

    // =========================================================================
    // REGISTRATION
    // =========================================================================

    /**
     * Register a new user account.
     *
     * @param  string $username  Desired username (3–50 chars, alphanumeric + _ -)
     * @param  string $email     Valid email address
     * @param  string $password  Plain-text password (min 8 chars)
     * @return array{ok: bool, errors?: string[], user_id?: int}
     */
    public function register(string $username, string $email, string $password): array
    {
        // ── Validate inputs ──────────────────────────────────────────────────
        $errors = $this->validateRegistration($username, $email, $password);
        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        // ── Check uniqueness ─────────────────────────────────────────────────
        if ($this->usernameExists($username)) {
            return ['ok' => false, 'errors' => ['That username is already taken.']];
        }
        if ($this->emailExists($email)) {
            return ['ok' => false, 'errors' => ['An account with that email already exists.']];
        }

        // ── Hash & persist ───────────────────────────────────────────────────
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `users` (`username`, `email`, `password_hash`)
                 VALUES (:username, :email, :hash)'
            );
            $stmt->execute([
                ':username' => $username,
                ':email'    => mb_strtolower(trim($email)),
                ':hash'     => $hash,
            ]);
            $userId = (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('[Auth::register] DB error: ' . $e->getMessage());
            // Handle race-condition duplicate key
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'errors' => ['Username or email is already registered.']];
            }
            return ['ok' => false, 'errors' => ['Registration failed due to a server error. Please try again.']];
        }

        return ['ok' => true, 'user_id' => $userId];
    }

    // =========================================================================
    // LOGIN
    // =========================================================================

    /**
     * Attempt to log in a user by email or username + password.
     *
     * @param  string $identifier  Email address or username
     * @param  string $password    Plain-text password
     * @return array{ok: bool, error?: string, user?: array}
     */
    public function login(string $identifier, string $password): array
    {
        // ── Rate limiting ────────────────────────────────────────────────────
        if ($this->isRateLimited()) {
            $remaining = $this->rateLimitSecondsRemaining();
            $minutes   = (int) ceil($remaining / 60);
            return [
                'ok'    => false,
                'error' => "Too many failed attempts. Please wait {$minutes} minute(s) and try again.",
            ];
        }

        // ── Input sanity ─────────────────────────────────────────────────────
        $identifier = trim($identifier);
        $password   = trim($password);

        if ($identifier === '' || $password === '') {
            return ['ok' => false, 'error' => 'Please fill in all fields.'];
        }

        // ── Lookup user ──────────────────────────────────────────────────────
        $user = $this->findByIdentifier($identifier);

        // Timing-safe: always call password_verify even on no match
        $hash = $user['password_hash'] ?? '$2y$12$invalidhashpadding00000000000000000000000000000000000';
        $valid = password_verify($password, $hash);

        if (!$user || !$valid) {
            $this->recordFailedAttempt();
            return ['ok' => false, 'error' => 'Invalid credentials. Please check your email and password.'];
        }

        // ── Rehash if needed (future-proof) ──────────────────────────────────
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            $this->rehashPassword((int) $user['id'], $password);
        }

        // ── Session fixation protection ──────────────────────────────────────
        if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // ── Persist auth state ───────────────────────────────────────────────
        $_SESSION[self::SESSION_USER_ID]  = (int)    $user['id'];
        $_SESSION[self::SESSION_USERNAME] = (string) $user['username'];
        $_SESSION[self::SESSION_EMAIL]    = (string) $user['email'];

        // Clear any rate-limit counters on successful login
        $this->clearRateLimit();

        return ['ok' => true, 'user' => $user];
    }

    // =========================================================================
    // LOGOUT
    // =========================================================================

    /**
     * Destroy the current session (log the user out).
     */
    public function logout(): void
    {
        // Clear session data
        $_SESSION = [];

        // Delete the session cookie
        if (!headers_sent() && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    // =========================================================================
    // SESSION HELPERS
    // =========================================================================

    /**
     * Returns true if a user is currently logged in.
     */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION[self::SESSION_USER_ID]);
    }

    /**
     * Returns the logged-in user's ID, or null.
     */
    public static function currentUserId(): ?int
    {
        return isset($_SESSION[self::SESSION_USER_ID])
            ? (int) $_SESSION[self::SESSION_USER_ID]
            : null;
    }

    /**
     * Returns the logged-in user's username, or null.
     */
    public static function currentUsername(): ?string
    {
        return $_SESSION[self::SESSION_USERNAME] ?? null;
    }

    /**
     * Redirect to login page if not authenticated.
     * Call at the top of protected pages.
     * @param string $loginUrl  The login page URL (default: /login)
     */
    public static function requireAuth(string $loginUrl = '/login'): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . $loginUrl, true, 302);
            exit;
        }
    }

    // =========================================================================
    // CSRF PROTECTION
    // =========================================================================

    /**
     * Generate (or retrieve) a CSRF token for the current session.
     * Call in every form page to embed in a hidden <input>.
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION[self::SESSION_CSRF])) {
            $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_CSRF];
    }

    /**
     * Validate the CSRF token from a POST request.
     * Returns false if missing or mismatched (hash_equals prevents timing attacks).
     */
    public static function validateCsrf(string $token): bool
    {
        $stored = $_SESSION[self::SESSION_CSRF] ?? '';
        if ($stored === '' || $token === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    // =========================================================================
    // RATE LIMITING (session-based)
    // =========================================================================

    /**
     * Returns true when the client has exceeded the allowed failed attempts.
     */
    private function isRateLimited(): bool
    {
        $attempts = (int) ($_SESSION[self::SESSION_RL_ATTEMPTS] ?? 0);
        $window   = (int) ($_SESSION[self::SESSION_RL_WINDOW]   ?? 0);

        if ($window > 0 && time() > $window) {
            // Window expired — reset
            $this->clearRateLimit();
            return false;
        }

        return $attempts >= self::RATE_LIMIT_MAX;
    }

    /** Seconds remaining until the rate-limit window expires. */
    private function rateLimitSecondsRemaining(): int
    {
        $window = (int) ($_SESSION[self::SESSION_RL_WINDOW] ?? time());
        return max(0, $window - time());
    }

    /** Record one failed login attempt. */
    private function recordFailedAttempt(): void
    {
        $attempts = (int) ($_SESSION[self::SESSION_RL_ATTEMPTS] ?? 0);
        $attempts++;

        $_SESSION[self::SESSION_RL_ATTEMPTS] = $attempts;

        // Start/extend the window only when we reach the first failure
        if ($attempts === 1) {
            $_SESSION[self::SESSION_RL_WINDOW] = time() + self::RATE_LIMIT_WINDOW;
        }
    }

    /** Clear rate-limit counters after a successful login. */
    private function clearRateLimit(): void
    {
        unset($_SESSION[self::SESSION_RL_ATTEMPTS], $_SESSION[self::SESSION_RL_WINDOW]);
    }

    // =========================================================================
    // PRIVATE DB HELPERS
    // =========================================================================

    /**
     * Find a user by email address OR username (case-insensitive on email).
     * Returns an associative row array, or false.
     */
    private function findByIdentifier(string $identifier): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT `id`, `username`, `email`, `password_hash`
               FROM `users`
              WHERE `email` = :email
                 OR `username` = :username
              LIMIT 1'
        );
        $stmt->execute([
            ':email'    => mb_strtolower($identifier),
            ':username' => $identifier,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** Returns true if the given username already exists (case-insensitive). */
    private function usernameExists(string $username): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM `users` WHERE LOWER(`username`) = LOWER(:u) LIMIT 1'
        );
        $stmt->execute([':u' => $username]);
        return $stmt->fetchColumn() !== false;
    }

    /** Returns true if the given email already exists (case-insensitive). */
    private function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM `users` WHERE `email` = :e LIMIT 1'
        );
        $stmt->execute([':e' => mb_strtolower(trim($email))]);
        return $stmt->fetchColumn() !== false;
    }

    /** Re-hash a user's password if the stored hash is outdated. */
    private function rehashPassword(int $userId, string $plainPassword): void
    {
        $newHash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE `users` SET `password_hash` = :hash WHERE `id` = :id'
            );
            $stmt->execute([':hash' => $newHash, ':id' => $userId]);
        } catch (PDOException $e) {
            error_log('[Auth::rehashPassword] Failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * Validate registration fields.
     * Returns an array of human-readable error strings (empty = valid).
     */
    private function validateRegistration(string $username, string $email, string $password): array
    {
        $errors = [];

        // Username
        $username = trim($username);
        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (mb_strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters.';
        } elseif (mb_strlen($username) > 50) {
            $errors[] = 'Username may not exceed 50 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            $errors[] = 'Username may only contain letters, numbers, underscores, and hyphens.';
        }

        // Email
        $email = trim($email);
        if ($email === '') {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (mb_strlen($email) > 255) {
            $errors[] = 'Email address is too long.';
        }

        // Password
        if ($password === '') {
            $errors[] = 'Password is required.';
        } elseif (mb_strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (mb_strlen($password) > 72) {
            // bcrypt silently truncates at 72 bytes — warn the user.
            $errors[] = 'Password may not exceed 72 characters.';
        }

        return $errors;
    }
}
