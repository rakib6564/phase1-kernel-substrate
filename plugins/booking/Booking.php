<?php
/**
 * Booking plugin — bootstrap.
 *
 * Registers admin nav, dashboard widget, public route, customer
 * dashboard widget (upcoming + past appointments), and a cron
 * handler for 24h + 1h reminder emails.
 */

require_once __DIR__ . '/BookingAPI.php';

class Booking extends Plugin {

    public function boot(): void {
        // Versioned schema migrations (mirrors the Shop plugin). The base
        // tables come from install.sql / BookingAPI::ensureSchema(); this
        // applies additive changes to existing installs. We only stamp the
        // version once the expected columns exist, so a partial failure
        // retries next request instead of silently sticking.
        $applied  = (string) $this->setting('applied_version', '0.0.0');
        $verified = (string) $this->setting('schema_verified', '');
        $needsUpgrade = version_compare($applied, $this->version, '<');
        // Self-heal: if the version was already stamped (applied == version)
        // but the schema is incomplete — e.g. a prior build stamped the
        // version before a column existed, or schemaIsCurrent() used to be
        // too lenient — `schema_verified` won't match and we force a full
        // additive pass from 0.0.0. Every ensureColumn()/ensureIndex() is
        // idempotent, so replaying them is safe. Once verified, the fast
        // path below skips this entirely on subsequent requests.
        if ($needsUpgrade || $verified !== $this->version) {
            $from = $needsUpgrade ? $applied : '0.0.0';
            $this->runMigrations($from);
            if ($this->schemaIsCurrent()) {
                $this->setSetting('applied_version', $this->version);
                $this->setSetting('schema_verified', $this->version);
            }
        }

        Hook::addFilter('admin_nav_items',           [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets',   [$this, 'addAdminDashboardWidget']);
        Hook::addFilter('customer_dashboard_widgets',[$this, 'addCustomerDashboardWidget']);
        Hook::addFilter('customer_dashboard_kpis',   [$this, 'addCustomerDashboardKpi']);
        Hook::addFilter('public_routes',             [$this, 'addPublicRoutes']);
        Hook::addAction('frequent_cron',             [$this, 'sendReminders']);

        // React to Stripe payments routed through the stripe-payment plugin.
        Hook::addAction('stripe_webhook_event', [$this, 'onStripeEvent']);

        // Content Builder integration: a "Booking" block for embedding the
        // booking flow into any page. Boot order isn't guaranteed, so register
        // now if Content Builder already booted, else catch its hook.
        $this->registerContentBlock();
    }

    /** Register the "Booking" block with Content Builder (order-independent). */
    private function registerContentBlock(): void {
        $register = function ($registry) {
            $opts = BookingAPI::pickerOptions();
            $registry::register('booking', [
                'label'  => __('booking_block', 'Booking'),
                'group'  => 'Booking',
                'fields' => [
                    ['key'=>'service','type'=>'select','label'=>'Service','options'=>$opts],
                    ['key'=>'minHeight','type'=>'text','label'=>'Initial height (px)','placeholder'=>'640'],
                ],
                'render'   => ['BookingAPI', 'renderContentBlock'],
                'defaults' => ['service' => '', 'minHeight' => '640'],
            ]);
        };

        if (class_exists('BlockRegistry')) {
            $register('BlockRegistry');                       // CB already booted
        } else {
            Hook::addAction('content_register_blocks', $register); // CB boots later
        }
    }

    /** Confirm a pending appointment when its Stripe payment succeeds. */
    public function onStripeEvent(array $event): void {
        try {
            BookingAPI::handleStripeEvent($event);
        } catch (\Throwable $e) {
            slate_log('Booking: stripe event handling failed: ' . $e->getMessage(), 'error');
        }
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('booking.view') && !Auth::isSuperAdmin()) return $items;

        $items[] = ['slug' => 'booking',              'label' => __('booking', 'Booking'),
                    'href' => $this->url('admin/index.php'),
                    'icon' => 'calendar',  'order' => 600, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-calendar',     'label' => __('booking_calendar', 'Calendar'),
                    'href' => $this->url('admin/calendar.php'),
                    'icon' => 'calendar', 'perm' => 'booking.view', 'order' => 600, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-appointments', 'label' => __('booking_appointments', 'Appointments'),
                    'href' => $this->url('admin/appointments.php'),
                    'icon' => 'clipboard-list', 'perm' => 'booking.view', 'order' => 601, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-customers',    'label' => __('booking_customers', 'Customers'),
                    'href' => $this->url('admin/customers.php'),
                    'icon' => 'users', 'perm' => 'booking.view', 'order' => 601, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-services',     'label' => __('booking_services', 'Services'),
                    'href' => $this->url('admin/services.php'),
                    'icon' => 'tag', 'perm' => 'booking.manage_services', 'order' => 602, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-providers',    'label' => __('booking_providers', 'Providers'),
                    'href' => $this->url('admin/providers.php'),
                    'icon' => 'users', 'perm' => 'booking.manage_providers', 'order' => 603, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-categories',   'label' => __('booking_categories', 'Categories'),
                    'href' => $this->url('admin/categories.php'),
                    'icon' => 'folder', 'perm' => 'booking.manage_services', 'order' => 604, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-addons',       'label' => __('booking_addons', 'Add-ons'),
                    'href' => $this->url('admin/addons.php'),
                    'icon' => 'plus', 'perm' => 'booking.manage_services', 'order' => 605, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-fields',       'label' => __('booking_fields', 'Custom fields'),
                    'href' => $this->url('admin/fields.php'),
                    'icon' => 'list', 'perm' => 'booking.manage_services', 'order' => 606, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-locations',    'label' => __('booking_locations', 'Locations'),
                    'href' => $this->url('admin/locations.php'),
                    'icon' => 'map-pin', 'perm' => 'booking.manage_resources', 'order' => 607, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-resources',    'label' => __('booking_resources_nav', 'Resources'),
                    'href' => $this->url('admin/resources.php'),
                    'icon' => 'box', 'perm' => 'booking.manage_resources', 'order' => 608, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-coupons',      'label' => __('booking_coupons', 'Coupons'),
                    'href' => $this->url('admin/coupons.php'),
                    'icon' => 'tag', 'perm' => 'booking.manage_payments', 'order' => 610, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-giftcards',    'label' => __('booking_giftcards', 'Gift cards'),
                    'href' => $this->url('admin/giftcards.php'),
                    'icon' => 'credit-card', 'perm' => 'booking.manage_payments', 'order' => 611, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-settings',     'label' => __('booking_settings', 'Booking settings'),
                    'href' => $this->url('admin/settings.php'),
                    'icon' => 'settings', 'perm' => 'booking.manage_settings', 'order' => 612, 'group' => 'booking'];
        return $items;
    }

    public function addPublicRoutes(array $routes): array {
        $routes['book'] = [
            'handler' => $this->dir('public/router.php'),
            'methods' => ['GET', 'POST'],
        ];
        return $routes;
    }

    public function addAdminDashboardWidget(array $widgets): array {
        if (!Auth::can('booking.view')) return $widgets;
        $tid = current_tenant_id();
        try {
            $today = (int) Database::value(
                "SELECT COUNT(*) FROM booking_appointments
                  WHERE tenant_id = ? AND status='confirmed'
                    AND DATE(starts_at) = CURDATE()", [$tid]);
            $week  = (int) Database::value(
                "SELECT COUNT(*) FROM booking_appointments
                  WHERE tenant_id = ? AND status='confirmed'
                    AND starts_at >= NOW() AND starts_at <= NOW() + INTERVAL 7 DAY", [$tid]);
            $services = (int) Database::value(
                "SELECT COUNT(*) FROM booking_services
                  WHERE tenant_id = ? AND is_active=1", [$tid]);
            $latest = Database::rows(
                "SELECT a.customer_name, a.starts_at, a.status, s.name AS service_name
                   FROM booking_appointments a
                   LEFT JOIN booking_services s ON s.id = a.service_id
                  WHERE a.tenant_id = ? ORDER BY a.id DESC LIMIT 5", [$tid]);
        } catch (\Throwable $e) {
            return $widgets;
        }
        $apptUrl = $this->url('admin/appointments.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('booking', 'Booking') ?></h2>
                <a href="<?= e($apptUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('booking_today', 'Today') ?></div>
                    <div class="dwidget-kpi-v"><?= $today ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('booking_next_7', 'Next 7 days') ?></div>
                    <div class="dwidget-kpi-v"><?= $week ?></div>
                </div>
            </div>
            <?php if ($latest): ?>
                <div class="dlist">
                    <?php foreach ($latest as $a):
                        $name   = trim((string)($a['customer_name'] ?? '')) ?: __('booking_guest', 'Guest');
                        $colors = ['confirmed' => 'success', 'pending' => 'warning', 'completed' => 'info',
                                   'cancelled' => 'danger', 'no_show' => 'muted'];
                        slate_dlist_row([
                            'avatar'       => $name,
                            'avatar_color' => $colors[$a['status']] ?? 'muted',
                            'title'        => $name,
                            'sub'          => trim((string)($a['service_name'] ?? '')) . ' · ' . ucfirst(str_replace('_', ' ', (string)$a['status'])),
                            'amount'       => $a['starts_at'] ? date('g:ia', strtotime($a['starts_at'])) : '',
                            'time'         => $a['starts_at'] ? date('M j', strtotime($a['starts_at'])) : '',
                            'href'         => $apptUrl,
                        ]);
                    endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dlist-empty"><?= __('booking_no_appts', 'No appointments yet') ?></div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    /**
     * Headline KPI: confirmed appointments still ahead of the customer.
     * Silent on failure — a dashboard must never 500 because one plugin's
     * table is missing.
     */
    public function addCustomerDashboardKpi(array $kpis): array {
        $cid = Auth::customerId();
        if ($cid === null) return $kpis;

        try {
            $count = (int) Database::value(
                "SELECT COUNT(*) FROM booking_appointments
                  WHERE customer_id = ? AND status = 'confirmed' AND starts_at >= NOW()",
                [$cid]
            );
            $next = Database::value(
                "SELECT MIN(starts_at) FROM booking_appointments
                  WHERE customer_id = ? AND status = 'confirmed' AND starts_at >= NOW()",
                [$cid]
            );
        } catch (\Throwable $e) {
            return $kpis;
        }

        $kpis[] = [
            'label' => 'Upcoming',
            'value' => (string)$count,
            'unit'  => $count === 1 ? 'booking' : 'bookings',
            'icon'  => 'calendar',
            'tone'  => $count > 0 ? 'blue' : '',
            'meta'  => $next
                ? 'Next <strong>' . e(date('j M, H:i', strtotime((string)$next))) . '</strong>'
                : 'Nothing scheduled',
        ];
        return $kpis;
    }

    public function addCustomerDashboardWidget(array $widgets): array {
        $cid = Auth::customerId();
        if ($cid === null) return $widgets;

        try {
            $upcoming = Database::rows(
                "SELECT a.*, s.name AS service_name, p.name AS provider_name
                   FROM booking_appointments a
                   JOIN booking_services  s ON s.id = a.service_id
                   JOIN booking_providers p ON p.id = a.provider_id
                  WHERE a.customer_id = ? AND a.status = 'confirmed'
                    AND a.starts_at >= NOW()
               ORDER BY a.starts_at LIMIT 5",
                [$cid]
            );
        } catch (\Throwable $e) {
            return $widgets;
        }
        if (!$upcoming) return $widgets;

        ob_start(); ?>
        <div class="card">
            <div class="card-header"><h2>Upcoming appointments</h2></div>
            <ul class="kv-list">
                <?php foreach ($upcoming as $a): ?>
                    <li class="kv-row">
                        <span class="kv-label">
                            <strong style="color:var(--text);"><?= e($a['service_name']) ?></strong><br>
                            <span class="text-xs"><?= e($a['provider_name']) ?> · ref <code><?= e($a['ref']) ?></code></span>
                        </span>
                        <span class="kv-value">
                            <?= e(date('j M Y, H:i', strtotime($a['starts_at']))) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    /**
     * Send configurable-lead reminders + post-visit follow-ups. Idempotent:
     * each fired lead is recorded in `reminders_sent` (CSV of lead-minutes)
     * and follow-ups flip `followup_sent`. Run every ~5 minutes; cheap when
     * nothing is due.
     */
    public function sendReminders(): void {
        try {
            foreach (BookingAPI::reminderLeads() as $lead) {
                // Appointments now within `lead` minutes of starting that
                // haven't had this lead's reminder yet.
                $rows = Database::rows(
                    "SELECT a.*, s.name AS service_name, p.name AS provider_name
                       FROM booking_appointments a
                       JOIN booking_services  s ON s.id = a.service_id
                       JOIN booking_providers p ON p.id = a.provider_id
                      WHERE a.status = 'confirmed'
                        AND a.starts_at > NOW()
                        AND a.starts_at <= NOW() + INTERVAL ? MINUTE
                        AND NOT FIND_IN_SET(?, COALESCE(a.reminders_sent, ''))",
                    [$lead, $lead]
                );
                foreach ($rows as $a) {
                    BookingAPI::sendReminder($a, $this->humanLead($lead), (int)$lead);
                    $sent = array_filter(array_map('trim', explode(',', (string)($a['reminders_sent'] ?? ''))));
                    $sent[] = (string)$lead;
                    Database::update('booking_appointments',
                        ['reminders_sent' => implode(',', array_unique($sent))], 'id = ?', [$a['id']]);
                }
            }

            // Follow-ups: appointments that finished a while ago.
            if (Database::setting('booking.followup_enabled') === '1') {
                $delayH = max(1, (int)(Database::setting('booking.followup_delay_hours') ?: 24));
                $rows = Database::rows(
                    "SELECT a.*, s.name AS service_name, p.name AS provider_name
                       FROM booking_appointments a
                       JOIN booking_services  s ON s.id = a.service_id
                       JOIN booking_providers p ON p.id = a.provider_id
                      WHERE a.status IN ('confirmed','completed') AND a.followup_sent = 0
                        AND a.ends_at <= NOW() - INTERVAL ? HOUR
                        AND a.ends_at >= NOW() - INTERVAL 30 DAY",
                    [$delayH]
                );
                foreach ($rows as $a) {
                    BookingAPI::sendFollowup($a);
                    Database::update('booking_appointments', ['followup_sent' => 1], 'id = ?', [$a['id']]);
                }
            }
        } catch (\Throwable $e) {
            slate_log('Booking: reminder cron failed: ' . $e->getMessage(), 'error');
        }
    }

    /** Human label for a lead time in minutes (e.g. 1440 → "24 hours"). */
    private function humanLead(int $minutes): string {
        if ($minutes % 1440 === 0) { $d = $minutes / 1440; return $d . ' day' . ($d > 1 ? 's' : ''); }
        if ($minutes % 60 === 0)   { $h = $minutes / 60;   return $h . ' hour' . ($h > 1 ? 's' : ''); }
        return $minutes . ' minutes';
    }

    // ──────────────────────────────────────────────────────
    // Migrations
    // ──────────────────────────────────────────────────────

    private function runMigrations(string $from): void {
        // Step 1 — new tables via the .sql migration files.
        $dir = $this->dir('migrations');
        if (is_dir($dir)) {
            $files = glob($dir . '/*.sql');
            if ($files) {
                sort($files);
                foreach ($files as $file) {
                    $target = basename($file, '.sql');
                    if (version_compare($target, $from, '>')) {
                        $this->runSqlFile($file, $target);
                    }
                }
            }
        }

        // Step 2 — additive columns + indexes on existing tables. Idempotent;
        // split out of the .sql so they stay portable across MySQL/MariaDB.
        if (version_compare('0.2.0', $from, '>')) {
            // services: scheduling + pricing config
            $this->ensureColumn('booking_services', 'category_id',        'INT UNSIGNED NULL AFTER description');
            $this->ensureColumn('booking_services', 'location_id',        'INT UNSIGNED NULL AFTER category_id');
            $this->ensureColumn('booking_services', 'duration_max_min',   'INT UNSIGNED NULL AFTER duration_min');
            $this->ensureColumn('booking_services', 'slot_interval_min',  'INT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_max_min');
            $this->ensureColumn('booking_services', 'buffer_before_min',  'INT UNSIGNED NOT NULL DEFAULT 0 AFTER slot_interval_min');
            $this->ensureColumn('booking_services', 'capacity',           'INT UNSIGNED NOT NULL DEFAULT 1 AFTER buffer_min');
            $this->ensureColumn('booking_services', 'min_advance_min',    'INT UNSIGNED NOT NULL DEFAULT 0 AFTER capacity');
            $this->ensureColumn('booking_services', 'max_advance_days',   'INT UNSIGNED NOT NULL DEFAULT 365 AFTER min_advance_min');
            $this->ensureColumn('booking_services', 'is_online',          'TINYINT(1) NOT NULL DEFAULT 0 AFTER max_advance_days');
            $this->ensureColumn('booking_services', 'requires_resource',  'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_online');
            $this->ensureColumn('booking_services', 'payment_mode',       "ENUM('free','full','deposit','onsite') NOT NULL DEFAULT 'free' AFTER currency");
            $this->ensureColumn('booking_services', 'deposit_type',       "ENUM('fixed','percent') NOT NULL DEFAULT 'percent' AFTER payment_mode");
            $this->ensureColumn('booking_services', 'deposit_value',      'INT UNSIGNED NOT NULL DEFAULT 0 AFTER deposit_type');
            $this->ensureColumn('booking_services', 'tax_rate',           'DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER deposit_value');
            $this->ensureColumn('booking_services', 'sort_order',         'INT NOT NULL DEFAULT 0 AFTER color');
            $this->ensureIndex('booking_services', 'tenant_category', '(tenant_id, category_id)');
            $this->ensureIndex('booking_services', 'tenant_sort',     '(tenant_id, sort_order)');

            // providers
            $this->ensureColumn('booking_providers', 'user_id',    'INT UNSIGNED NULL AFTER tenant_id');
            $this->ensureColumn('booking_providers', 'phone',      'VARCHAR(40) NULL AFTER email');
            $this->ensureColumn('booking_providers', 'color',      "VARCHAR(16) NOT NULL DEFAULT '#2563EB' AFTER bio");
            $this->ensureColumn('booking_providers', 'sort_order', 'INT NOT NULL DEFAULT 0 AFTER color');

            // provider_services: per-staff price/duration overrides
            $this->ensureColumn('booking_provider_services', 'price_cents',  'INT UNSIGNED NULL');
            $this->ensureColumn('booking_provider_services', 'duration_min', 'INT UNSIGNED NULL');

            // appointments
            $this->ensureColumn('booking_appointments', 'location_id',      'INT UNSIGNED NULL AFTER provider_id');
            $this->ensureColumn('booking_appointments', 'resource_id',      'INT UNSIGNED NULL AFTER location_id');
            $this->ensureColumn('booking_appointments', 'party_size',       'INT UNSIGNED NOT NULL DEFAULT 1 AFTER customer_phone');
            $this->ensureColumn('booking_appointments', 'addons_json',      'TEXT NULL AFTER notes');
            $this->ensureColumn('booking_appointments', 'custom_json',      'TEXT NULL AFTER addons_json');
            $this->ensureColumn('booking_appointments', 'source',           "ENUM('online','walkin','admin') NOT NULL DEFAULT 'online' AFTER status");
            $this->ensureColumn('booking_appointments', 'recurrence_group', 'VARCHAR(32) NULL AFTER source');
            $this->ensureColumn('booking_appointments', 'price_cents',      'INT UNSIGNED NOT NULL DEFAULT 0 AFTER recurrence_group');
            $this->ensureColumn('booking_appointments', 'tax_cents',        'INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_cents');
            $this->ensureColumn('booking_appointments', 'deposit_cents',    'INT UNSIGNED NOT NULL DEFAULT 0 AFTER tax_cents');
            $this->ensureColumn('booking_appointments', 'paid_cents',       'INT UNSIGNED NOT NULL DEFAULT 0 AFTER deposit_cents');
            $this->ensureColumn('booking_appointments', 'payment_status',   "ENUM('none','pending','deposit_paid','paid','refunded','partially_refunded') NOT NULL DEFAULT 'none' AFTER paid_cents");
            $this->ensureColumn('booking_appointments', 'payment_ref',      'VARCHAR(120) NULL AFTER payment_status');
            $this->ensureColumn('booking_appointments', 'reschedule_count', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_ref');
            $this->ensureColumn('booking_appointments', 'cancel_reason',    'VARCHAR(255) NULL AFTER reschedule_count');
            $this->ensureColumn('booking_appointments', 'cancelled_at',     'DATETIME NULL AFTER cancel_reason');
            $this->ensureIndex('booking_appointments', 'resource_slot', '(resource_id, starts_at, ends_at)');
            $this->ensureIndex('booking_appointments', 'recur_group',   '(recurrence_group)');

            // status ENUM gains 'pending' (awaiting payment).
            $this->modifyColumn('booking_appointments', 'status',
                "ENUM('pending','confirmed','cancelled','no_show','completed') NOT NULL DEFAULT 'confirmed'");
        }

        // v0.3.0 — notifications: configurable reminder tracking, follow-ups,
        // and per-service email template overrides.
        if (version_compare('0.3.0', $from, '>')) {
            $this->ensureColumn('booking_appointments', 'reminders_sent', 'VARCHAR(160) NULL AFTER reminder_1h_sent');
            $this->ensureColumn('booking_appointments', 'followup_sent',  'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminders_sent');
            $this->ensureColumn('booking_services', 'confirm_subject', 'VARCHAR(200) NULL AFTER description');
            $this->ensureColumn('booking_services', 'confirm_body',    'TEXT NULL AFTER confirm_subject');
        }

        // v0.4.0 — customer management: a per-appointment self-service token
        // for guest cancel/reschedule links. The booking_customers table is
        // created by migrations/0.4.0.sql.
        if (version_compare('0.4.0', $from, '>')) {
            $this->ensureColumn('booking_appointments', 'manage_token', 'CHAR(32) NULL AFTER ref');
            $this->ensureIndex('booking_appointments', 'manage_token_idx', '(manage_token)');
        }

        // v0.5.0 — payments & billing: discounts, gift cards, group price
        // tiers, and the Stripe charge link. Tables come from 0.5.0.sql.
        if (version_compare('0.5.0', $from, '>')) {
            $this->ensureColumn('booking_appointments', 'coupon_code',    'VARCHAR(60) NULL AFTER payment_ref');
            $this->ensureColumn('booking_appointments', 'gift_card_code', 'VARCHAR(60) NULL AFTER coupon_code');
            $this->ensureColumn('booking_appointments', 'discount_cents', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER gift_card_code');
            $this->ensureColumn('booking_appointments', 'charge_id',      'INT UNSIGNED NULL AFTER discount_cents');
            $this->ensureColumn('booking_services',     'price_tiers_json', 'TEXT NULL AFTER deposit_value');
        }

        // v0.5.1 — gift-card amount applied, Stripe session correlation,
        // and an invoice number.
        if (version_compare('0.5.1', $from, '>')) {
            $this->ensureColumn('booking_appointments', 'gift_applied_cents', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER gift_card_code');
            $this->ensureColumn('booking_appointments', 'stripe_session_id',  'VARCHAR(255) NULL AFTER charge_id');
            $this->ensureColumn('booking_appointments', 'invoice_no',         'VARCHAR(40) NULL AFTER stripe_session_id');
            $this->ensureIndex('booking_appointments', 'stripe_session_idx', '(stripe_session_id)');
        }
    }

    private function schemaIsCurrent(): bool {
        try {
            // Probe one representative column from each schema generation,
            // including the newest (0.5.1) payment columns. If any are
            // missing the schema is not fully migrated and the version must
            // not be stamped.
            $cols = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND ((TABLE_NAME = 'booking_services'     AND COLUMN_NAME = 'capacity')
                      OR (TABLE_NAME = 'booking_appointments' AND COLUMN_NAME = 'party_size')
                      OR (TABLE_NAME = 'booking_appointments' AND COLUMN_NAME = 'gift_applied_cents')
                      OR (TABLE_NAME = 'booking_appointments' AND COLUMN_NAME = 'stripe_session_id'))");
            $tbl = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_categories'");
            return $cols >= 4 && $tbl >= 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function runSqlFile(string $file, string $tag): void {
        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '') continue;
            try {
                Database::query($stmt);
            } catch (\Throwable $e) {
                slate_log("Booking migration {$tag} statement error: " . $e->getMessage(), 'error');
            }
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("Booking ensureColumn {$table}.{$column} failed: " . $e->getMessage(), 'error');
        }
    }

    private function modifyColumn(string $table, string $column, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            if ((int)$exists > 0) {
                Database::query("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("Booking modifyColumn {$table}.{$column} failed: " . $e->getMessage(), 'error');
        }
    }

    private function ensureIndex(string $table, string $indexName, string $columnSpec): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                [$table, $indexName]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` {$columnSpec}");
            }
        } catch (\Throwable $e) {
            slate_log("Booking ensureIndex {$table}.{$indexName} failed: " . $e->getMessage(), 'error');
        }
    }
}
