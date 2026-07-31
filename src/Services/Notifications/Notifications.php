<?php
/**
 * Slate — lightweight admin notification store.
 *
 * A tenant-scoped feed surfaced by the topbar bell. Any code can push a
 * notification:
 *
 *   Notifications::add('New booking', [
 *       'body' => 'Rakib booked "waterxfight"',
 *       'url'  => SLATE_URL . '/admin/...',
 *       'icon' => 'calendar',
 *   ]);
 *
 * Schema is created lazily on first use so existing installs auto-migrate
 * without a deploy step.
 *
 * Phase 1 A3: migrated from includes/Notifications.php into
 * Slate\Services\Notifications. The old global name `Notifications` resolves via
 * a class_alias in src/compat/aliases.php. Behavior identical — only the
 * `Database::` static calls are qualified to the global alias (`\Database::`).
 */

declare(strict_types=1);

namespace Slate\Services\Notifications;

class Notifications
{
    private static bool $schemaChecked = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            \Database::get()->exec(
                "CREATE TABLE IF NOT EXISTS `slate_notifications` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
                    `title`      VARCHAR(200) NOT NULL,
                    `body`       VARCHAR(500) NULL,
                    `url`        VARCHAR(500) NULL,
                    `icon`       VARCHAR(40)  NULL,
                    `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
                    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_tenant_read` (`tenant_id`, `is_read`, `id`),
                    KEY `idx_tenant_created` (`tenant_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            slate_log('Notifications: ensure schema failed: ' . $e->getMessage(), 'error');
        }
    }

    /** Push a notification. $opts: body, url, icon. */
    public static function add(string $title, array $opts = []): void
    {
        $title = trim($title);
        if ($title === '') return;
        self::ensureSchema();
        try {
            \Database::insert('slate_notifications', [
                'tenant_id' => current_tenant_id(),
                'title'     => mb_substr($title, 0, 200),
                'body'      => isset($opts['body']) ? mb_substr((string)$opts['body'], 0, 500) : null,
                'url'       => isset($opts['url'])  ? mb_substr((string)$opts['url'], 0, 500)  : null,
                'icon'      => isset($opts['icon']) ? mb_substr((string)$opts['icon'], 0, 40)  : null,
            ]);
        } catch (\Throwable $e) {
            slate_log('Notifications: add failed: ' . $e->getMessage(), 'error');
        }
    }

    public static function unreadCount(): int
    {
        self::ensureSchema();
        try {
            return (int) \Database::value(
                "SELECT COUNT(*) FROM slate_notifications WHERE tenant_id = ? AND is_read = 0",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Most recent notifications (newest first). */
    public static function recent(int $limit = 12): array
    {
        self::ensureSchema();
        $limit = max(1, min(50, $limit));
        try {
            return \Database::rows(
                "SELECT * FROM slate_notifications WHERE tenant_id = ? ORDER BY id DESC LIMIT " . $limit,
                [current_tenant_id()]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Paginated feed (newest first) for the full notifications page. */
    public static function all(int $limit = 30, int $offset = 0): array
    {
        self::ensureSchema();
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        try {
            return \Database::rows(
                "SELECT * FROM slate_notifications WHERE tenant_id = ?
                 ORDER BY id DESC LIMIT " . $limit . " OFFSET " . $offset,
                [current_tenant_id()]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Total notifications for the current tenant (for pagination). */
    public static function total(): int
    {
        self::ensureSchema();
        try {
            return (int) \Database::value(
                "SELECT COUNT(*) FROM slate_notifications WHERE tenant_id = ?",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Fetch one notification (tenant-scoped) or null. */
    public static function get(int $id): ?array
    {
        self::ensureSchema();
        try {
            return \Database::row(
                "SELECT * FROM slate_notifications WHERE id = ? AND tenant_id = ?",
                [$id, current_tenant_id()]
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Mark a single notification read (or unread when $read is false). */
    public static function markRead(int $id, bool $read = true): void
    {
        self::ensureSchema();
        try {
            \Database::query(
                "UPDATE slate_notifications SET is_read = ? WHERE id = ? AND tenant_id = ?",
                [$read ? 1 : 0, $id, current_tenant_id()]
            );
        } catch (\Throwable $e) {
            slate_log('Notifications: markRead failed: ' . $e->getMessage(), 'error');
        }
    }

    /** Delete a single notification (tenant-scoped). */
    public static function delete(int $id): void
    {
        self::ensureSchema();
        try {
            \Database::delete('slate_notifications', 'id = ? AND tenant_id = ?',
                [$id, current_tenant_id()]);
        } catch (\Throwable $e) {
            slate_log('Notifications: delete failed: ' . $e->getMessage(), 'error');
        }
    }

    /** Delete every notification for the current tenant. */
    public static function deleteAll(): void
    {
        self::ensureSchema();
        try {
            \Database::query("DELETE FROM slate_notifications WHERE tenant_id = ?", [current_tenant_id()]);
        } catch (\Throwable $e) {
            slate_log('Notifications: deleteAll failed: ' . $e->getMessage(), 'error');
        }
    }

    public static function markAllRead(): void
    {
        self::ensureSchema();
        try {
            \Database::query(
                "UPDATE slate_notifications SET is_read = 1 WHERE tenant_id = ? AND is_read = 0",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) {
            slate_log('Notifications: markAllRead failed: ' . $e->getMessage(), 'error');
        }
    }

    /** Trim old read notifications so the table doesn't grow unbounded. */
    public static function prune(int $keepDays = 30): void
    {
        self::ensureSchema();
        try {
            \Database::query(
                "DELETE FROM slate_notifications
                  WHERE tenant_id = ? AND is_read = 1
                    AND created_at < NOW() - INTERVAL " . max(1, $keepDays) . " DAY",
                [current_tenant_id()]
            );
        } catch (\Throwable $e) { /* best effort */ }
    }
}
