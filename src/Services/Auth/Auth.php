<?php
/**
 * Slate — Auth.
 *
 * Session-based auth for both admins (the `users` table) and
 * customers (the `customers` table). Granular permissions via
 * the `role_permissions` table, looked up by role_id.
 *
 * Super Admin (role_id = 1) gets everything via short-circuit in
 * Auth::can() — never query role_permissions for them.
 *
 * Patterns:
 *   Auth::require()          ← redirect to /admin/login if not logged in
 *   Auth::requirePerm($k)    ← require + 403 if permission missing
 *   Auth::can($k)            ← returns bool, no side effect
 *   Auth::requireCustomer()  ← redirect to /customer/login if no customer session
 *
 * Phase 1 (customer auth):
 *   Auth::registerCustomer(...)
 *   Auth::sendCustomerVerification($customerId)
 *   Auth::verifyCustomerEmail($token)
 *   Auth::sendCustomerPasswordReset($email)
 *   Auth::resetCustomerPassword($token, $newPassword)
 *
 * All customer tokens are SHA-256 hashed, single-use, time-bounded,
 * stored in `customer_auth_tokens` (created on demand). The plaintext
 * token only ever appears in the email link.
 */

declare(strict_types=1);

namespace Slate\Services\Auth;

// Phase 1 A3: migrated from includes/Auth.php into Slate\Services\Auth.
// The global name `Auth` is provided by a class_alias in src/compat/aliases.php.
// Behavior is identical to the pre-move class (migrated WHOLE — no SRP split;
// see docs/09-Roadmap/a3-core-reviews/auth.md).

class Auth {
    /** Permission cache for the current request, keyed by role_id. */
    private static array $permCache = [];

    /** Set true once per request after the customer auth tokens table is ensured. */
    private static bool $customerTokenSchemaChecked = false;

    /** Set true once per request after the login_attempts table is ensured. */
    private static bool $loginAttemptsSchemaChecked = false;

    /** True if the most recent attemptLogin/attemptCustomerLogin was rejected
     *  because the client is currently locked out (not a bad password). */
    private static bool $lastLoginThrottled = false;

    /** Reusable bcrypt hash for timing-equalisation on the no-such-user path. */
    private static ?string $dummyHash = null;

    /** Lifetimes for the issued customer tokens. */
    const VERIFY_TOKEN_TTL_SECONDS = 86400 * 3;   // 3 days
    const RESET_TOKEN_TTL_SECONDS  = 3600 * 2;    // 2 hours

    /** Defaults applied when the Security settings are blank (feature on by default). */
    const DEFAULT_MAX_LOGIN_ATTEMPTS = 10;
    const DEFAULT_LOCKOUT_MINUTES    = 15;

    // ── Session lifecycle ─────────────────────────────────────

    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $httpOnly = true;
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => $httpOnly,
                'samesite' => 'Lax',
            ]);
            session_name('SLATE_SID');
            session_start();
            self::enforceIdleTimeout();
        }
    }

    /**
     * Idle-timeout enforcement for the `session_timeout_minutes` Security
     * setting. If a logged-in session has been idle longer than the
     * configured window, its auth state is dropped (the session itself
     * survives so CSRF tokens etc. persist for the login page).
     */
    private static function enforceIdleTimeout(): void {
        try {
            $mins = (int) \Database::setting('session_timeout_minutes');
        } catch (\Throwable $e) {
            return; // pre-install / DB down
        }
        if ($mins <= 0) return;

        $now  = time();
        $last = (int)($_SESSION['slate_last_activity'] ?? 0);
        if (!empty($_SESSION['slate_user']) || !empty($_SESSION['slate_customer'])) {
            if ($last > 0 && ($now - $last) > $mins * 60) {
                unset($_SESSION['slate_user'], $_SESSION['slate_customer']);
                session_regenerate_id(true);
            }
        }
        $_SESSION['slate_last_activity'] = $now;
    }

    // ── Admin auth ────────────────────────────────────────────

    public static function attemptLogin(string $email, string $password): bool {
        self::$lastLoginThrottled = false;
        if (self::loginBlockedSeconds('admin') > 0) {
            self::$lastLoginThrottled = true;
            return false;
        }

        $user = \Database::row(
            "SELECT * FROM users WHERE email = ? AND tenant_id = ? AND status = 'active'",
            [$email, current_tenant_id()]
        );
        if (!$user) {
            self::dummyVerify($password);   // equalise timing vs. the real path
            self::recordLoginFailure('admin', $email);
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            self::recordLoginFailure('admin', $email);
            return false;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            \Database::update('users',
                ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
                'id = ?', [$user['id']]
            );
        }

        \Database::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        self::startSession();
        session_regenerate_id(true);
        $_SESSION['slate_user'] = [
            'id'       => (int)$user['id'],
            'email'    => $user['email'],
            'name'     => $user['name'],
            'role_id'  => (int)$user['role_id'],
            'tenant_id'=> (int)$user['tenant_id'],
        ];

        self::clearLoginFailures('admin');
        \Hook::doAction('user_logged_in', (int)$user['id']);
        return true;
    }

    public static function logout(): void {
        self::startSession();
        $uid = self::userId();
        unset($_SESSION['slate_user']);
        session_regenerate_id(true);
        if ($uid !== null) \Hook::doAction('user_logged_out', $uid);
    }

    public static function check(): bool {
        self::startSession();
        return !empty($_SESSION['slate_user']);
    }

    public static function user(): ?array {
        return self::check() ? $_SESSION['slate_user'] : null;
    }

    public static function userId(): ?int {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function roleId(): ?int {
        $u = self::user();
        return $u ? (int)$u['role_id'] : null;
    }

    public static function isSuperAdmin(): bool {
        return self::roleId() === 1;
    }

    public static function require(): void {
        if (!self::check()) {
            $next = $_SERVER['REQUEST_URI'] ?? '/admin/';
            header('Location: ' . SLATE_URL . '/admin/login.php?next=' . urlencode($next));
            exit;
        }
    }

    // ── Permissions ───────────────────────────────────────────

    public static function can(string $key): bool {
        if (!self::check()) return false;
        if (self::isSuperAdmin()) return true;

        $roleId = self::roleId();
        if ($roleId === null) return false;

        if (!isset(self::$permCache[$roleId])) {
            $rows = \Database::rows(
                "SELECT perm_key FROM role_permissions WHERE role_id = ? AND granted = 1",
                [$roleId]
            );
            self::$permCache[$roleId] = array_fill_keys(array_column($rows, 'perm_key'), true);
        }

        return isset(self::$permCache[$roleId][$key]);
    }

    public static function requirePerm(string $key): void {
        self::require();
        if (!self::can($key)) {
            http_response_code(403);
            echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
            echo '<p>Required permission: <code>' . e($key) . '</code></p>';
            exit;
        }
    }

    public static function invalidatePermCache(): void {
        self::$permCache = [];
    }

    // ── Login throttling ──────────────────────────────────────
    //
    // Brute-force protection wired to the Security settings
    // (`max_login_attempts`, `lockout_minutes`). Failures are counted
    // per client IP within the lockout window; once the cap is reached
    // the IP is blocked until `lockout_minutes` after its last attempt.
    // Keying on IP (not email) means an attacker hammering many accounts
    // from one host gets blocked, without letting an attacker lock a
    // victim out by spamming that victim's email from elsewhere.

    /** True when the last login attempt was rejected for lockout, not a bad password. */
    public static function lastLoginWasThrottled(): bool {
        return self::$lastLoginThrottled;
    }

    /** [maxAttempts, lockoutMinutes]; maxAttempts <= 0 means throttling is disabled. */
    private static function throttleConfig(): array {
        $maxRaw  = \Database::setting('max_login_attempts');
        $lockRaw = \Database::setting('lockout_minutes');
        $max  = ($maxRaw  === null || $maxRaw  === '') ? self::DEFAULT_MAX_LOGIN_ATTEMPTS : (int)$maxRaw;
        $lock = ($lockRaw === null || $lockRaw === '') ? self::DEFAULT_LOCKOUT_MINUTES    : (int)$lockRaw;
        if ($lock < 1) $lock = self::DEFAULT_LOCKOUT_MINUTES;
        return [$max, $lock];
    }

    private static function clientIp(): string {
        // REMOTE_ADDR only — never trust X-Forwarded-* (spoofable).
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** A real bcrypt verify on a throwaway hash, to flatten the timing
     *  difference between "no such user" and "wrong password". */
    private static function dummyVerify(string $password): void {
        if (self::$dummyHash === null) {
            self::$dummyHash = password_hash('slate-throttle-timing-equaliser', PASSWORD_DEFAULT);
        }
        password_verify($password, self::$dummyHash);
    }

    /**
     * Seconds the current client IP must wait before another login in
     * this scope ('admin'|'customer'). 0 = not blocked / disabled.
     */
    public static function loginBlockedSeconds(string $scope): int {
        [$max, $lockMin] = self::throttleConfig();
        if ($max <= 0) return 0;
        self::ensureLoginAttemptsSchema();

        $since = date('Y-m-d H:i:s', time() - $lockMin * 60);
        $row = \Database::row(
            "SELECT COUNT(*) AS c, MAX(attempted_at) AS last
               FROM login_attempts
              WHERE scope = ? AND ip = ? AND attempted_at > ?",
            [$scope, self::clientIp(), $since]
        );
        if (!$row || (int)$row['c'] < $max) return 0;

        $lastTs    = $row['last'] ? strtotime((string)$row['last']) : time();
        $remaining = ($lastTs + $lockMin * 60) - time();
        return $remaining > 0 ? $remaining : 0;
    }

    public static function recordLoginFailure(string $scope, string $identifier): void {
        [$max] = self::throttleConfig();
        if ($max <= 0) return;
        self::ensureLoginAttemptsSchema();
        try {
            \Database::insert('login_attempts', [
                'tenant_id'  => current_tenant_id(),
                'scope'      => $scope,
                'ip'         => self::clientIp(),
                'identifier' => mb_substr($identifier, 0, 190),
            ]);
        } catch (\Throwable $e) {
            // Throttling must never block a login on infra error.
        }
    }

    public static function clearLoginFailures(string $scope): void {
        if (!self::$loginAttemptsSchemaChecked) self::ensureLoginAttemptsSchema();
        try {
            \Database::query(
                "DELETE FROM login_attempts WHERE scope = ? AND ip = ?",
                [$scope, self::clientIp()]
            );
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    private static function ensureLoginAttemptsSchema(): void {
        if (self::$loginAttemptsSchemaChecked) return;
        self::$loginAttemptsSchemaChecked = true;
        try {
            \Database::get()->exec(
                "CREATE TABLE IF NOT EXISTS `login_attempts` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
                    `scope`        VARCHAR(16)  NOT NULL,
                    `ip`           VARCHAR(45)  NOT NULL,
                    `identifier`   VARCHAR(190) NOT NULL DEFAULT '',
                    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_scope_ip` (`scope`, `ip`, `attempted_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('Auth: failed to ensure login_attempts table: ' . $e->getMessage(), 'error');
            }
        }
    }

    public static function corePermissions(): array {
        return [
            'Users & roles' => [
                ['key' => 'users.view',     'label' => 'View users'],
                ['key' => 'users.edit',     'label' => 'Create / edit / delete users'],
            ],
            'Plugins' => [
                ['key' => 'plugins.manage', 'label' => 'View, install, activate, uninstall plugins'],
            ],
            'Contact forms' => [
                ['key' => 'contact.view',   'label' => 'View contact forms and submissions'],
                ['key' => 'contact.manage', 'label' => 'Create / edit / delete contact forms'],
            ],
            'Settings' => [
                ['key' => 'settings.view',  'label' => 'View settings'],
                ['key' => 'settings.edit',  'label' => 'Modify settings'],
            ],
            'Media' => [
                ['key' => 'media.view',   'label' => 'View the media library'],
                ['key' => 'media.upload', 'label' => 'Upload media (images & documents)'],
                ['key' => 'media.delete', 'label' => 'Delete media (blocked if a file is in use)'],
            ],
            'Customers' => [
                ['key' => 'customers.view', 'label' => 'View customer records'],
                ['key' => 'customers.edit', 'label' => 'Create / edit customer records'],
            ],
            'Audit & logs' => [
                ['key' => 'audit.view',     'label' => 'View the audit log'],
            ],
        ];
    }

    public static function knownPermissions(): array {
        $groups = self::corePermissions();
        $rows = class_exists('Database')
              ? \Database::rows("SELECT slug, name, manifest_json FROM plugins WHERE status IN ('active','inactive','installed')")
              : [];
        foreach ($rows as $row) {
            $manifest = json_decode($row['manifest_json'] ?? '{}', true) ?: [];
            $perms = $manifest['permissions'] ?? [];
            if (!is_array($perms) || empty($perms)) continue;
            $groupName = $row['name'] . ' (plugin)';
            foreach ($perms as $perm) {
                if (is_string($perm) && $perm !== '') {
                    $groups[$groupName][] = ['key' => $perm, 'label' => $perm];
                } elseif (is_array($perm) && !empty($perm['key'])) {
                    $groups[$groupName][] = [
                        'key'   => $perm['key'],
                        'label' => $perm['label'] ?? $perm['key'],
                    ];
                }
            }
        }
        return $groups;
    }

    // ── Customer auth ─────────────────────────────────────────
    //
    // Phase 2A B4 cutover: customer authentication now reads through
    // IdentityStore (the `identities` table), and every `customers` mutation is
    // DUAL-WRITTEN into the spine via ContactSeeder::syncCustomer so `customers`
    // stays a valid rollback target. Signatures, the session shape
    // ($_SESSION['slate_customer']), throttling, and timing-equalisation are
    // unchanged. id == contact_id == old customer_id (id-preservation), so every
    // caller and Auth::customerId() keep returning the same value.

    /** A per-request IdentityStore wired to the current tenant. */
    private static function identityStore(): \Slate\Services\Identity\IdentityStore {
        $tenants = new \Slate\Tenancy\TenantContext();
        return new \Slate\Services\Identity\IdentityStore(
            $tenants,
            new \Slate\Services\Identity\ContactRepository($tenants)
        );
    }

    /** Dual-write mirror: keep the spine in sync after a `customers` change.
     *  Best-effort — a mirror failure must never break the legacy write. */
    private static function syncCustomerToSpine(int $customerId): void {
        try {
            (new \Slate\Services\Identity\ContactSeeder())->syncCustomer($customerId);
        } catch (\Throwable $e) {
            slate_log('Auth: customer→spine dual-write failed for ' . $customerId . ': ' . $e->getMessage(), 'warning');
        }
    }

    public static function attemptCustomerLogin(string $email, string $password): bool {
        self::$lastLoginThrottled = false;
        if (self::loginBlockedSeconds('customer') > 0) {
            self::$lastLoginThrottled = true;
            return false;
        }

        $email = strtolower(trim($email));
        $store    = self::identityStore();
        $identity = $store->authenticate('password', $email, $password);
        if ($identity === null) {
            self::dummyVerify($password);   // equalise timing vs. the real path (incl. suspended/unknown)
            self::recordLoginFailure('customer', $email);
            return false;
        }

        $contact = $store->contactFor($identity->id);
        if ($contact === null) {                 // defensive: identity without a contact
            self::recordLoginFailure('customer', $email);
            return false;
        }

        // Dual-write last_login to the legacy table (rollback consistency).
        \Database::update('customers', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$contact->id]);

        self::startSession();
        session_regenerate_id(true);
        $_SESSION['slate_customer'] = [
            'id'        => $contact->id,
            'email'     => $contact->primaryEmail,
            'name'      => $contact->displayName,
            'tenant_id' => $contact->tenantId,
        ];

        self::clearLoginFailures('customer');
        \Hook::doAction('customer_logged_in', $contact->id);
        return true;
    }

    public static function customer(): ?array {
        self::startSession();
        return $_SESSION['slate_customer'] ?? null;
    }

    public static function customerId(): ?int {
        $c = self::customer();
        return $c ? (int)$c['id'] : null;
    }

    public static function requireCustomer(): void {
        if (!self::customer()) {
            $next = $_SERVER['REQUEST_URI'] ?? '/customer/';
            header('Location: ' . SLATE_URL . '/customer/login.php?next=' . urlencode($next));
            exit;
        }
    }

    public static function logoutCustomer(): void {
        self::startSession();
        unset($_SESSION['slate_customer']);
        session_regenerate_id(true);
    }

    /**
     * Register a customer. Returns ['ok'=>true, 'customer_id'=>int]
     * on success or ['ok'=>false, 'error'=>string] on failure.
     *
     * Sends the verification email automatically. Does NOT log the
     * customer in — registration and login are deliberately separate
     * so an attacker who guesses the registration form can't
     * impersonate an existing email.
     */
    public static function registerCustomer(
        string $email,
        string $password,
        string $name = '',
        string $phone = ''
    ): array {
        $email = strtolower(trim($email));
        $name  = trim($name);
        $phone = trim($phone);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        if (mb_strlen($email) > 190) {
            return ['ok' => false, 'error' => 'Email is too long.'];
        }

        $tid = current_tenant_id();

        // Existing record? If a verified active customer already
        // owns this email, refuse. If it's an unverified record we
        // can re-use (resend verify rather than create a duplicate).
        $existing = \Database::row(
            "SELECT * FROM customers WHERE tenant_id = ? AND email = ?",
            [$tid, $email]
        );

        if ($existing) {
            if (!empty($existing['email_verified']) || !empty($existing['password_hash'])) {
                // Account exists. Tell the user generically — don't leak
                // whether the email is verified or has a password set.
                return ['ok' => false, 'error' => 'This email is already registered. Try logging in or resetting your password.'];
            }
            // Guest/unverified shell — promote it to a real account.
            \Database::update('customers', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'name'          => $name !== '' ? $name : ($existing['name'] ?? null),
                'phone'         => $phone !== '' ? $phone : ($existing['phone'] ?? null),
                'status'        => 'active',
            ], 'id = ?', [$existing['id']]);
            $customerId = (int)$existing['id'];
        } else {
            $customerId = \Database::insert('customers', [
                'tenant_id'      => $tid,
                'email'          => $email,
                'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
                'name'           => $name !== '' ? $name : null,
                'phone'          => $phone !== '' ? $phone : null,
                'status'         => 'active',
                'email_verified' => 0,
            ]);
        }

        self::syncCustomerToSpine($customerId);   // dual-write: mirror into contacts/identities
        \AuditLog::record('customer.registered', (string)$customerId, ['email' => $email]);
        \Hook::doAction('customer_registered', $customerId);

        $sent = self::sendCustomerVerification($customerId);
        return [
            'ok'           => true,
            'customer_id'  => $customerId,
            'email_sent'   => $sent,
        ];
    }

    /**
     * Issue and email a fresh email-verification token.
     * Returns true if the email was handed to the Mailer successfully.
     */
    public static function sendCustomerVerification(int $customerId): bool {
        $cust = \Database::row(
            "SELECT * FROM customers WHERE id = ? AND tenant_id = ?",
            [$customerId, current_tenant_id()]
        );
        if (!$cust) return false;
        if (!empty($cust['email_verified'])) return false;

        $token = self::issueCustomerToken($customerId, 'verify_email', self::VERIFY_TOKEN_TTL_SECONDS);

        $verifyUrl = SLATE_URL . '/customer/verify-email.php?token=' . urlencode($token);
        $siteName  = \Database::setting('site_name') ?: 'Slate';

        $bodyHtml = '<p>Welcome to ' . e($siteName) . '.</p>'
                  . '<p>Please confirm your email address by clicking the link below. The link is valid for 3 days.</p>'
                  . '<p><a href="' . e($verifyUrl) . '">' . e($verifyUrl) . '</a></p>'
                  . '<p>If you didn\'t create an account, you can safely ignore this email.</p>';

        return (bool) \Mailer::send(
            $cust['email'],
            'Confirm your email address',
            $bodyHtml,
            $cust['name'] ?? ''
        );
    }

    /**
     * Verify the email-verification token. Returns customer_id on
     * success or null on invalid/expired/used token.
     */
    public static function verifyCustomerEmail(string $token): ?int {
        $customerId = self::consumeCustomerToken($token, 'verify_email');
        if ($customerId === null) return null;

        \Database::update('customers', [
            'email_verified' => 1,
        ], 'id = ? AND tenant_id = ?', [$customerId, current_tenant_id()]);
        self::syncCustomerToSpine($customerId);   // dual-write: mirror verified flag

        \AuditLog::record('customer.email_verified', (string)$customerId);
        \Hook::doAction('customer_email_verified', $customerId);
        return $customerId;
    }

    /**
     * Issue a password reset token and email the link. Returns true
     * if a reset email was sent. Always returns true to callers
     * (callers should not branch on this — we don't want to leak
     * whether an email exists in the database).
     */
    public static function sendCustomerPasswordReset(string $email): bool {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return true; // pretend ok

        $cust = \Database::row(
            "SELECT * FROM customers WHERE tenant_id = ? AND email = ? AND status = 'active'",
            [current_tenant_id(), $email]
        );
        if (!$cust) return true;
        if (empty($cust['password_hash'])) {
            // Guest record with no password — silently no-op (the
            // attacker can't tell us apart from the no-match case).
            return true;
        }

        $token = self::issueCustomerToken((int)$cust['id'], 'password_reset', self::RESET_TOKEN_TTL_SECONDS);

        $resetUrl = SLATE_URL . '/customer/reset-password.php?token=' . urlencode($token);
        $siteName = \Database::setting('site_name') ?: 'Slate';

        $bodyHtml = '<p>You requested a password reset for your ' . e($siteName) . ' account.</p>'
                  . '<p>Click the link below to set a new password. The link is valid for 2 hours.</p>'
                  . '<p><a href="' . e($resetUrl) . '">' . e($resetUrl) . '</a></p>'
                  . '<p>If you didn\'t request this, you can safely ignore this email — your password won\'t change.</p>';

        \Mailer::send(
            $cust['email'],
            'Reset your password',
            $bodyHtml,
            $cust['name'] ?? ''
        );

        \AuditLog::record('customer.password_reset_requested', (string)$cust['id']);
        return true;
    }

    /**
     * Consume a password reset token and set a new password.
     * Returns ['ok'=>true, 'customer_id'=>int] or ['ok'=>false, 'error'=>string].
     */
    public static function resetCustomerPassword(string $token, string $newPassword): array {
        if (strlen($newPassword) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        $customerId = self::consumeCustomerToken($token, 'password_reset');
        if ($customerId === null) {
            return ['ok' => false, 'error' => 'This reset link is invalid or has expired.'];
        }

        \Database::update('customers', [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ], 'id = ? AND tenant_id = ?', [$customerId, current_tenant_id()]);
        self::syncCustomerToSpine($customerId);   // dual-write: mirror new password into identities

        // Burn any other outstanding reset tokens for this customer.
        \Database::query(
            "UPDATE customer_auth_tokens
                SET used_at = NOW()
              WHERE customer_id = ? AND purpose = 'password_reset' AND used_at IS NULL",
            [$customerId]
        );

        \AuditLog::record('customer.password_reset', (string)$customerId);
        return ['ok' => true, 'customer_id' => $customerId];
    }

    // ── Customer token helpers (internal) ─────────────────────

    /**
     * Lazily create the customer_auth_tokens table the first time
     * we need it. Cheap CREATE TABLE IF NOT EXISTS — runs once per
     * request thanks to the static flag.
     */
    private static function ensureCustomerTokenSchema(): void {
        if (self::$customerTokenSchemaChecked) return;
        self::$customerTokenSchemaChecked = true;

        try {
            \Database::get()->exec(
                "CREATE TABLE IF NOT EXISTS `customer_auth_tokens` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`    INT UNSIGNED NOT NULL DEFAULT 1,
                    `customer_id`  INT UNSIGNED NOT NULL,
                    `purpose`      VARCHAR(32) NOT NULL,
                    `token_hash`   CHAR(64) NOT NULL,
                    `expires_at`   DATETIME NOT NULL,
                    `used_at`      DATETIME NULL,
                    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_token_purpose`    (`token_hash`, `purpose`),
                    KEY `idx_customer_purpose` (`customer_id`, `purpose`),
                    KEY `idx_expires`          (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('Auth: failed to ensure customer_auth_tokens table: ' . $e->getMessage(), 'error');
            }
        }
    }

    /**
     * Mint a random token, store SHA-256 of it, and return the
     * plaintext for embedding in an email link.
     */
    private static function issueCustomerToken(int $customerId, string $purpose, int $ttlSeconds): string {
        self::ensureCustomerTokenSchema();

        // Invalidate prior outstanding tokens for the same purpose,
        // so a reissued verification email supersedes the old one.
        \Database::query(
            "UPDATE customer_auth_tokens
                SET used_at = NOW()
              WHERE customer_id = ? AND purpose = ? AND used_at IS NULL",
            [$customerId, $purpose]
        );

        $plaintext = bin2hex(random_bytes(32));   // 64 hex chars
        $hash      = hash('sha256', $plaintext);  // 64 hex chars
        $expires   = date('Y-m-d H:i:s', time() + $ttlSeconds);

        \Database::insert('customer_auth_tokens', [
            'tenant_id'   => current_tenant_id(),
            'customer_id' => $customerId,
            'purpose'     => $purpose,
            'token_hash'  => $hash,
            'expires_at'  => $expires,
        ]);

        return $plaintext;
    }

    /**
     * Validate + burn a token. Returns the customer_id if the token
     * was valid (not expired, not used, purpose matches); null otherwise.
     */
    private static function consumeCustomerToken(string $plaintext, string $purpose): ?int {
        if ($plaintext === '' || strlen($plaintext) > 128) return null;
        self::ensureCustomerTokenSchema();

        $hash = hash('sha256', $plaintext);
        $row  = \Database::row(
            "SELECT * FROM customer_auth_tokens
              WHERE token_hash = ? AND purpose = ? AND tenant_id = ?
                AND used_at IS NULL
                AND expires_at > NOW()
              LIMIT 1",
            [$hash, $purpose, current_tenant_id()]
        );
        if (!$row) return null;

        \Database::update('customer_auth_tokens',
            ['used_at' => date('Y-m-d H:i:s')],
            'id = ?', [(int)$row['id']]
        );

        return (int)$row['customer_id'];
    }
}
