<?php
/**
 * Coaching plugin — bootstrap.
 *
 * Wave 1 surface: admin nav, customer nav, "Today" dashboard card,
 * customer_registered → provision empty profile.
 *
 * Access model:
 *   - Practitioner side: gated on the coaching.* permissions.
 *   - Client side: gated on MembershipAPI::isActive() when the membership
 *     plugin is present. Without membership, the plugin degrades to a
 *     preview / testing surface — practitioner still sees everything;
 *     the client just doesn't see nav entries.
 */

require_once __DIR__ . '/CoachingAPI.php';

class Coaching extends Plugin {

    public function boot(): void {
        // Schema self-heal, stamped per-version.
        if ((string) $this->setting('schema_verified', '') !== $this->version) {
            CoachingAPI::ensureSchema();
            $this->setSetting('schema_verified', $this->version);
        }

        // Admin surface.
        Hook::addFilter('admin_nav_items',            [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets',    [$this, 'addAdminDashboardWidget']);

        // Customer surface.
        Hook::addFilter('customer_nav_items',         [$this, 'addCustomerNav']);
        Hook::addFilter('customer_dashboard_widgets', [$this, 'addCustomerDashboardWidget']);
        Hook::addFilter('customer_dashboard_kpis',    [$this, 'addCustomerDashboardKpi']);

        // Public route for the client-facing app pages.
        Hook::addFilter('public_routes',              [$this, 'addPublicRoutes']);

        // Provision an empty profile row + chat thread on new customer.
        Hook::addAction('customer_registered',        [$this, 'onCustomerRegistered']);

        // Deliver scheduled chat messages on cron ticks.
        Hook::addAction('frequent_cron',              [$this, 'runCron']);

        // Design-system CSS:
        //   /admin/*     → admin.css (coaching admin pages)
        //   /coaching*   → customer.css (bento dashboard) + customer-shell.css (premium overlay)
        //   /customer/*  → customer-shell.css (premium overlay for core customer area)
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (defined('SLATE_URL')) {
            if (str_contains($uri, '/admin/')) {
                $this->enqueueStyle('admin.css');
            }
            $isCustomerArea = str_contains($uri, '/coaching') || str_contains($uri, '/customer');
            if ($isCustomerArea) {
                $this->enqueueStyle('customer-shell.css');
            }
            if (str_contains($uri, '/coaching')) {
                $this->enqueueStyle('customer.css');
            }
        }
    }

    public function runCron(): void {
        try {
            CoachingAPI::deliverScheduled();
            CoachingAPI::generateSummariesForExpiringMemberships();
        } catch (\Throwable $e) {
            slate_log('Coaching cron failed: ' . $e->getMessage(), 'warning');
        }
    }

    // ── Admin nav ────────────────────────────────────────────────────────

    public function addAdminNav(array $items): array {
        if (!Auth::can('coaching.view_clients')
            && !Auth::can('coaching.manage_clients')
            && !Auth::isSuperAdmin()) {
            return $items;
        }
        $items[] = ['slug' => 'coaching',           'label' => 'Coaching',
                    'href' => $this->url('admin/index.php'),
                    'icon' => 'user', 'perm' => 'coaching.view_clients',
                    'order' => 620, 'group' => 'content'];
        $items[] = ['slug' => 'coaching-clients',   'label' => 'Program clients',
                    'href' => $this->url('admin/clients.php'),
                    'icon' => 'users', 'perm' => 'coaching.view_clients',
                    'order' => 621, 'group' => 'content'];
        $items[] = ['slug' => 'coaching-feed',      'label' => 'Client feed',
                    'href' => $this->url('admin/feed.php'),
                    'icon' => 'bell', 'perm' => 'coaching.view_clients',
                    'order' => 622, 'group' => 'content'];
        try { $unread = CoachingAPI::totalUnreadForPractitioner(); } catch (\Throwable $e) { $unread = 0; }
        $items[] = ['slug' => 'coaching-chat',      'label' => 'Client chat' . ($unread > 0 ? ' (' . $unread . ')' : ''),
                    'href' => $this->url('admin/chat.php'),
                    'icon' => 'mail', 'perm' => 'coaching.reply_chat',
                    'order' => 623, 'group' => 'content'];
        $items[] = ['slug' => 'coaching-library',   'label' => 'Library',
                    'href' => $this->url('admin/library.php'),
                    'icon' => 'folder', 'perm' => 'coaching.manage_library',
                    'order' => 624, 'group' => 'content'];
        $items[] = ['slug' => 'coaching-settings',  'label' => 'Coaching settings',
                    'href' => $this->url('admin/settings.php'),
                    'icon' => 'settings', 'perm' => 'coaching.manage_clients',
                    'order' => 625, 'group' => 'content'];
        return $items;
    }

    public function addAdminDashboardWidget(array $widgets): array {
        try {
            $tid = current_tenant_id();
            $enrolled = count(CoachingAPI::listEnrolledClients());
            $entriesToday = (int) Database::value(
                "SELECT COUNT(*) FROM coaching_diary_entry WHERE tenant_id = ? AND day = CURDATE()", [$tid]);
        } catch (\Throwable $e) { $enrolled = 0; $entriesToday = 0; }

        $clientsUrl = $this->url('admin/clients.php');
        $feedUrl    = $this->url('admin/feed.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2>Coaching</h2>
                <a href="<?= e($clientsUrl) ?>" class="dwidget-all">View all →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k">Enrolled</div>
                    <div class="dwidget-kpi-v"><?= (int)$enrolled ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k">Diary entries today</div>
                    <div class="dwidget-kpi-v"><a href="<?= e($feedUrl) ?>"><?= (int)$entriesToday ?></a></div>
                </div>
            </div>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ── Customer nav + dashboard ─────────────────────────────────────────

    public function addCustomerNav(array $items): array {
        $c = Auth::customer();
        if (!$c) return $items;
        if (!CoachingAPI::isEnrolled((int)$c['id'])) return $items;

        $items[] = ['slug' => 'coaching',        'label' => 'Program',
                    'href' => SLATE_URL . '/coaching',
                    'icon' => 'clipboard', 'order' => 300];
        $items[] = ['slug' => 'coaching-profile','label' => 'My profile',
                    'href' => SLATE_URL . '/coaching?view=profile',
                    'icon' => 'user', 'order' => 301];
        $items[] = ['slug' => 'coaching-goals',  'label' => 'My goals',
                    'href' => SLATE_URL . '/coaching?view=goals',
                    'icon' => 'check', 'order' => 302];
        $items[] = ['slug' => 'coaching-diary',  'label' => 'Food diary',
                    'href' => SLATE_URL . '/coaching?view=diary',
                    'icon' => 'clipboard', 'order' => 303];
        $items[] = ['slug' => 'coaching-charts', 'label' => 'My charts',
                    'href' => SLATE_URL . '/coaching?view=charts',
                    'icon' => 'percent', 'order' => 304];
        $items[] = ['slug' => 'coaching-chat',   'label' => 'Chat',
                    'href' => SLATE_URL . '/coaching?view=chat',
                    'icon' => 'mail', 'order' => 305];
        // Optional modules — only shown when the practitioner has enabled them.
        $cid = (int)$c['id'];
        if (CoachingAPI::isModuleEnabled($cid, 'meal_structure')) {
            $items[] = ['slug' => 'coaching-structure', 'label' => 'Meal structure',
                        'href' => SLATE_URL . '/coaching?view=structure',
                        'icon' => 'coffee', 'order' => 306];
        }
        if (CoachingAPI::isModuleEnabled($cid, 'shopping')) {
            $items[] = ['slug' => 'coaching-shopping', 'label' => 'Shopping list',
                        'href' => SLATE_URL . '/coaching?view=shopping',
                        'icon' => 'clipboard', 'order' => 307];
        }
        if (CoachingAPI::isModuleEnabled($cid, 'recipes')) {
            $items[] = ['slug' => 'coaching-recipes', 'label' => 'Recipes',
                        'href' => SLATE_URL . '/coaching?view=recipes',
                        'icon' => 'gift', 'order' => 308];
        }
        // Motivation nav — only show when the client has at least one active challenge.
        try {
            $activeChallenges = count(CoachingAPI::listChallenges($cid, true));
        } catch (\Throwable $e) { $activeChallenges = 0; }
        if ($activeChallenges > 0) {
            $items[] = ['slug' => 'coaching-motivation', 'label' => 'Motivation (' . $activeChallenges . ')',
                        'href' => SLATE_URL . '/coaching?view=motivation',
                        'icon' => 'shield', 'order' => 309];
        }
        // Summary nav — surfaces once the practitioner has generated one for this client.
        try {
            $hasSummary = (bool) CoachingAPI::getSummary($cid);
        } catch (\Throwable $e) { $hasSummary = false; }
        if ($hasSummary) {
            $items[] = ['slug' => 'coaching-summary', 'label' => 'My program summary',
                        'href' => SLATE_URL . '/coaching?view=summary',
                        'icon' => 'gift', 'order' => 310];
        }
        return $items;
    }

    /**
     * Headline KPI: daily goals checked in today. Only for enrolled
     * clients — for everyone else the program isn't a metric that means
     * anything, so no card at all beats a card reading 0.
     */
    public function addCustomerDashboardKpi(array $kpis): array {
        $c = Auth::customer();
        if (!$c) return $kpis;
        $cid = (int)$c['id'];

        try {
            if (!CoachingAPI::isEnrolled($cid)) return $kpis;
            $goals = CoachingAPI::listGoals($cid, 'daily', true);
            $total = is_array($goals) ? count($goals) : 0;

            // Table is singular, and the enum is not_achieved|partial|
            // achieved|exceeded — see plugins/coaching/install.sql:96.
            $done = (int) Database::value(
                "SELECT COUNT(*) FROM coaching_goal_checkin
                  WHERE tenant_id = ? AND customer_id = ? AND day = ?
                    AND status IN ('achieved','exceeded')",
                [current_tenant_id(), $cid, date('Y-m-d')]
            );
        } catch (\Throwable $e) {
            return $kpis;
        }

        $kpis[] = [
            'label' => 'Goals today',
            'value' => $total > 0 ? $done . '/' . $total : '—',
            'icon'  => 'target',
            'tone'  => $total > 0 && $done >= $total ? 'green' : 'blue',
            'meta'  => $total > 0
                ? 'Body &amp; Soul Program'
                : 'No daily goals set yet',
            'href'  => SLATE_URL . '/coaching?view=goals',
        ];
        return $kpis;
    }

    public function addCustomerDashboardWidget(array $widgets): array {
        $c = Auth::customer();
        if (!$c || !CoachingAPI::isEnrolled((int)$c['id'])) return $widgets;
        $cid = (int)$c['id'];

        $profile = CoachingAPI::getProfile($cid);
        $goals   = CoachingAPI::listGoals($cid, 'daily', true);
        $profileComplete = $profile && !empty($profile['dob']) && !empty($profile['height_cm']) && !empty($profile['weight_kg']);

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2>Body &amp; Soul Program</h2>
                <a href="<?= e(SLATE_URL . '/coaching') ?>" class="dwidget-all">Open →</a>
            </div>
            <?php if (!$profileComplete): ?>
                <p style="padding:0 var(--space-4) var(--space-4);font-size:14px;color:#64748b;margin:0;">
                    Your profile isn't complete yet —
                    <a href="<?= e(SLATE_URL . '/coaching?view=profile') ?>">fill it in</a>
                    so I can build your daily plan.
                </p>
            <?php else: ?>
                <p style="padding:0 var(--space-4);font-size:14px;color:#334155;margin:0 0 var(--space-3);">
                    Welcome back — today's goals at a glance:
                </p>
                <?php if (!$goals): ?>
                    <p style="padding:0 var(--space-4) var(--space-4);font-size:13px;color:#94a3b8;margin:0;">
                        No daily goals set yet.
                    </p>
                <?php else: ?>
                    <ul style="margin:0;padding:0 var(--space-4) var(--space-4) 40px;font-size:14px;color:#334155;">
                        <?php foreach (array_slice($goals, 0, 4) as $g): ?>
                            <li><?= e($g['title']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div style="padding:0 var(--space-4) var(--space-4);">
                    <a href="<?= e(SLATE_URL . '/coaching') ?>" class="btn btn-sm btn-primary">Open my program →</a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ── Public route registration ────────────────────────────────────────

    public function addPublicRoutes(array $routes): array {
        $routes['coaching'] = [
            'handler' => $this->dir('customer/router.php'),
            'methods' => ['GET', 'POST'],
        ];
        return $routes;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    public function onCustomerRegistered($customerOrId): void {
        try {
            $cid = is_array($customerOrId) ? (int)($customerOrId['id'] ?? 0) : (int)$customerOrId;
            if ($cid > 0) {
                CoachingAPI::provisionProfile($cid);
                CoachingAPI::ensureThread($cid);
            }
        } catch (\Throwable $e) {
            slate_log('Coaching provisionProfile failed: ' . $e->getMessage(), 'warning');
        }
    }
}
