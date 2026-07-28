<?php
/**
 * Slate — shared helpers.
 *
 * CSRF, escaping, tenant resolution, secret encryption, version compare.
 * Everything here is small, stateless, and used by the shell and by
 * every plugin. Wrapped in function_exists guards so test bootstraps
 * can stub them without fataling.
 */

// ── HTML escape ───────────────────────────────────────────────
if (!function_exists('e')) {
    function e($s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// ── Current tenant resolution ─────────────────────────────────
if (!function_exists('current_tenant_id')) {
    function current_tenant_id(): int {
        // CLI / cron override
        if (!empty($GLOBALS['SLATE_TENANT_OVERRIDE'])) {
            return (int)$GLOBALS['SLATE_TENANT_OVERRIDE'];
        }
        // Super-admin acting as a tenant
        if (!empty($_SESSION['slate_override_tenant']) && class_exists('Auth') && Auth::check() && Auth::isSuperAdmin()) {
            return (int)$_SESSION['slate_override_tenant'];
        }
        // Fallback: tenant 1 (default single-tenant install)
        return defined('TENANT_ID') ? TENANT_ID : 1;
    }
}

if (!function_exists('with_tenant')) {
    /**
     * Run a callback as a specific tenant. Used by cron sweeps.
     */
    function with_tenant(int $tenantId, callable $fn) {
        $previous = $GLOBALS['SLATE_TENANT_OVERRIDE'] ?? null;
        $GLOBALS['SLATE_TENANT_OVERRIDE'] = $tenantId;
        try {
            return $fn();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
            } else {
                $GLOBALS['SLATE_TENANT_OVERRIDE'] = $previous;
            }
        }
    }
}

// ── CSRF ──────────────────────────────────────────────────────
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['slate_csrf'])) {
            $_SESSION['slate_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['slate_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token = null): bool {
        $token  = $token ?? ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $stored = $_SESSION['slate_csrf'] ?? '';
        if ($stored === '' || $token === '') return false;
        return hash_equals($stored, $token);
    }
}

// ── One-time submission token (anti double-submit) ────────────
// Stops a single user action from writing twice. Each rendered form
// embeds a fresh single-use token via submit_token_field(); the first
// POST consumes it with submit_token_consume(), so any replay (page
// refresh, back-button re-POST, or a fast double-click) finds the token
// already gone and is rejected. Independent of the CSRF token, which is
// per-session and reusable (and so cannot detect a replay on its own).
if (!function_exists('submit_token')) {
    function submit_token(): string {
        if (empty($_SESSION['slate_submit_tokens']) || !is_array($_SESSION['slate_submit_tokens'])) {
            $_SESSION['slate_submit_tokens'] = [];
        }
        $now = time();
        // Prune expired (2h TTL) and cap to the 50 most recent so a long
        // session with many rendered forms can't grow the session record
        // without bound.
        foreach ($_SESSION['slate_submit_tokens'] as $t => $exp) {
            if ((int)$exp < $now) unset($_SESSION['slate_submit_tokens'][$t]);
        }
        if (count($_SESSION['slate_submit_tokens']) > 50) {
            $_SESSION['slate_submit_tokens'] =
                array_slice($_SESSION['slate_submit_tokens'], -50, null, true);
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['slate_submit_tokens'][$token] = $now + 7200;
        return $token;
    }
}

if (!function_exists('submit_token_field')) {
    function submit_token_field(): string {
        return '<input type="hidden" name="_stoken" value="' . e(submit_token()) . '">';
    }
}

if (!function_exists('submit_token_consume')) {
    /**
     * Validate-and-consume the submission token on the current request.
     * Returns true exactly once per issued token; false for a missing,
     * unknown, expired, or already-used token — i.e. a duplicate submit.
     */
    function submit_token_consume(?string $token = null): bool {
        $token = $token ?? ($_POST['_stoken'] ?? '');
        if (!is_string($token) || $token === '') return false;
        $store = $_SESSION['slate_submit_tokens'] ?? [];
        if (!is_array($store) || !isset($store[$token])) return false;
        $exp = (int)$store[$token];
        unset($_SESSION['slate_submit_tokens'][$token]); // consume either way
        return $exp >= time();
    }
}

// ── Safe post-login redirect target ───────────────────────────
if (!function_exists('slate_safe_redirect_target')) {
    /**
     * Sanitise a user-supplied `next=` redirect. Only same-origin
     * destinations are allowed; anything else falls back. This blocks
     * open redirects, including protocol-relative URLs ("//evil.com",
     * "/\evil.com") that browsers treat as off-site.
     */
    function slate_safe_redirect_target($next, string $fallback): string {
        $next = (string)($next ?? '');
        if ($next === '' || preg_match('/[\x00-\x1F\x7F]/', $next)) {
            return $fallback;
        }
        // An absolute URL to our own origin is fine.
        if (defined('SLATE_URL') && ($next === SLATE_URL || str_starts_with($next, SLATE_URL . '/'))) {
            return $next;
        }
        // Otherwise accept only a same-origin absolute path: a single
        // leading slash that is NOT followed by another slash or a
        // backslash (which would make it protocol-relative / off-site).
        if (str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_starts_with($next, '/\\')) {
            return $next;
        }
        return $fallback;
    }
}

// ── Secret encryption (AES-256-GCM, envelope format 'enc:v1:') ──
if (!function_exists('slate_encrypt_secret')) {
    function slate_encrypt_secret(string $plaintext): string {
        if (!defined('APP_SECRET') || APP_SECRET === '') {
            throw new RuntimeException('APP_SECRET is not configured.');
        }
        $key = hash('sha256', APP_SECRET, true);
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'enc:v1:' . base64_encode($iv . $tag . $ct);
    }
}

if (!function_exists('slate_decrypt_secret')) {
    function slate_decrypt_secret(string $envelope): ?string {
        if (!str_starts_with($envelope, 'enc:v1:')) return $envelope;
        if (!defined('APP_SECRET') || APP_SECRET === '') return null;
        $raw = base64_decode(substr($envelope, 7), true);
        if ($raw === false || strlen($raw) < 28) return null;
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $key = hash('sha256', APP_SECRET, true);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? null : $pt;
    }
}

// ── Semver comparison ─────────────────────────────────────────
if (!function_exists('slate_semver_satisfies')) {
    /**
     * Check whether $version satisfies a simple semver range.
     * Supports: ">=1.2.3", ">1.0", "=1.0", "<2.0", "<=1.5", "1.0" (exact)
     * Plugin manifests use this to declare 'requires_core'.
     */
    function slate_semver_satisfies(string $version, string $range): bool {
        $range = trim($range);
        if ($range === '') return true;

        // Parse leading operator
        $op = '=';
        if (preg_match('/^(>=|<=|>|<|=)?\s*(.+)$/', $range, $m)) {
            if ($m[1] !== '') $op = $m[1];
            $bound = trim($m[2]);
        } else {
            return false;
        }

        $cmp = version_compare($version, $bound);
        return match ($op) {
            '>=' => $cmp >= 0,
            '<=' => $cmp <= 0,
            '>'  => $cmp >  0,
            '<'  => $cmp <  0,
            '='  => $cmp === 0,
            default => false,
        };
    }
}

// ── JSON helpers ──────────────────────────────────────────────
if (!function_exists('json_response')) {
    function json_response($payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('json_error')) {
    function json_error(string $message, int $status = 400): void {
        json_response(['ok' => false, 'error' => $message], $status);
    }
}

// ── i18n shim ─────────────────────────────────────────────────
// Bare __() so plugin code can be written before I18n is loaded.
// Real implementation lives in includes/I18n.php and is loaded by
// config.php. The shim falls back to the inline English.
if (!function_exists('__')) {
    function __(string $key, string $fallback = ''): string {
        if (class_exists('I18n')) {
            return I18n::translate($key, $fallback);
        }
        return $fallback !== '' ? $fallback : $key;
    }
}

if (!function_exists('__js')) {
    function __js(array $keys): array {
        $out = [];
        foreach ($keys as $key => $fallback) {
            $out[is_int($key) ? $fallback : $key] = __(is_int($key) ? $fallback : $key, $fallback);
        }
        return $out;
    }
}

// ── Logging ───────────────────────────────────────────────────
if (!function_exists('slate_log')) {
    /**
     * Append a line to the Slate log file.
     * Used by PluginLoader for plugin errors, by audit failures,
     * by anything that needs a "this happened" trail visible to
     * the operator without polluting PHP error logs.
     */
    function slate_log(string $message, string $level = 'info'): void {
        $logFile = (defined('SLATE_ROOT') ? SLATE_ROOT : __DIR__ . '/..') . '/data/slate.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

// ── plugin_url ──────────────────────────────────────────────────
// Build a URL into a plugin's directory. Equivalent to $this->url()
// from inside a Plugin class, but callable from any context (admin
// pages, other plugins, partials) without needing a Plugin instance.
//
//   plugin_url('shop')                       → /plugins/shop
//   plugin_url('shop', 'admin/orders.php')   → /plugins/shop/admin/orders.php
if (!function_exists('plugin_url')) {
    function plugin_url(string $slug, string $relPath = ''): string {
        $base = SLATE_URL . '/plugins/' . $slug;
        return $relPath === '' ? $base : $base . '/' . ltrim($relPath, '/');
    }
}

// ── plugin_dir ──────────────────────────────────────────────────
// Filesystem counterpart to plugin_url(). Useful when one plugin
// needs to read another's static asset (rare; usually use the
// target plugin's API class instead).
if (!function_exists('plugin_dir')) {
    function plugin_dir(string $slug, string $relPath = ''): string {
        $base = SLATE_ROOT . '/plugins/' . $slug;
        return $relPath === '' ? $base : $base . '/' . ltrim($relPath, '/');
    }
}
