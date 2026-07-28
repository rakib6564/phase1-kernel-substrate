<?php
/**
 * Slate — customer dashboard.
 *
 * First adopter of the portal UI kit (includes/portal_ui.php +
 * assets/css/portal.css). Renders its own shell via slate_portal_head()
 * rather than partials/header.php: that partial's 'dashboard' variant
 * carries its own topbar and brand block, which would collide with the
 * kit's and render the logo twice. Plugins still inject cards via the
 * `customer_dashboard_widgets` filter.
 */
require_once dirname(__DIR__) . '/config.php';
require_once SLATE_ROOT . '/includes/portal_ui.php';

Auth::requireCustomer();

$cust = Auth::customer();
$row  = Database::row(
    "SELECT * FROM customers WHERE id = ? AND tenant_id = ?",
    [(int)$cust['id'], current_tenant_id()]
);

$flash = null;

// ── Profile-update POST ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update_profile') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $name  = trim((string)($_POST['name']  ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        Database::update('customers', [
            'name'  => $name  !== '' ? $name  : null,
            'phone' => $phone !== '' ? $phone : null,
        ], 'id = ?', [(int)$cust['id']]);

        // Refresh session payload
        $_SESSION['slate_customer']['name'] = $name;

        AuditLog::record('customer.profile_updated', (string)$cust['id']);
        $flash = ['type' => 'success', 'msg' => 'Profile updated.'];
        $row['name']  = $name;
        $row['phone'] = $phone;
    }
}

// Plugin widgets
$widgets = Hook::applyFilters('customer_dashboard_widgets', []);
if (!is_array($widgets)) $widgets = [];

// Plugin KPIs — the headline metric row. Plugins own the numbers that
// matter (appointments due, days of membership left, goals hit today);
// core only knows account facts, which make weak KPIs.
$kpis = Hook::applyFilters('customer_dashboard_kpis', []);
if (!is_array($kpis)) $kpis = [];

// ── View data ───────────────────────────────────────────────
$verified  = !empty($row['email_verified']);
$firstName = trim((string)($row['name'] ?? ''));
if ($firstName !== '') $firstName = explode(' ', $firstName)[0];
$joined    = date('M Y', strtotime($row['created_at'] ?? 'now'));
$lastLogin = !empty($row['last_login_at']) ? date('j M, H:i', strtotime($row['last_login_at'])) : '—';

$hour     = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

slate_portal_head('Your account');
slate_portal_topbar();
?>

<main class="wrap" id="portal-main">

    <?php slate_portal_hero(
        'Your account',
        $greeting . ($firstName !== '' ? ',' : ''),
        $firstName,
        "Manage your profile and see what's going on with your account."
    ); ?>

    <?php if ($flash): ?>
        <div style="margin-top:var(--sp-5)">
            <?php slate_portal_alert($flash['msg'], $flash['type']); ?>
        </div>
    <?php endif; ?>

    <?php if (!$verified): ?>
        <div style="margin-top:var(--sp-5)">
            <?php slate_portal_alert(
                "Your email isn't verified yet. Check your inbox for the link we sent when you registered.",
                ''
            ); ?>
        </div>
    <?php endif; ?>

    <!-- ── KPI row ─────────────────────────────────────────────────
         Plugin-supplied metrics lead. If no plugin contributes any, fall
         back to account facts so the row is never empty — but those are
         deliberately last, because "Member since" is not a KPI.        -->
    <?php
    if (!$kpis) {
        $kpis[] = [
            'label' => 'Account status',
            'value' => $verified ? 'Verified' : 'Unverified',
            'icon'  => $verified ? 'shield' : 'bell',
            'tone'  => $verified ? 'green' : 'amber',
            'meta'  => $verified ? 'Your email is confirmed' : 'Check your inbox to confirm',
        ];
        $kpis[] = ['label' => 'Member since', 'value' => $joined,    'icon' => 'calendar'];
        $kpis[] = ['label' => 'Last sign in', 'value' => $lastLogin, 'icon' => 'clock'];
    }
    // Four across divides the 12-col grid evenly; anything else reads as
    // a ragged row, so cap the headline at four and let the rest fall into
    // the widgets below.
    $kpis = array_slice($kpis, 0, 4);
    $span = count($kpis) >= 4 ? 3 : (count($kpis) === 2 ? 6 : 4);
    ?>
    <div class="grid" style="margin-top:var(--sp-5)">
        <?php foreach ($kpis as $kpi): ?>
            <div class="col-<?= (int)$span ?>">
                <?php slate_portal_kpi(is_array($kpi) ? $kpi : []); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Profile + account details ───────────────────────────── -->
    <div class="grid" style="margin-top:var(--sp-5)">

        <div class="col-7">
            <?php slate_portal_card_open('Profile', 'Your details', null, null, 'card-static'); ?>
                <form method="post" autocomplete="on">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="update_profile">

                    <div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--sp-4)">
                        <label class="field">
                            <span class="field-label">Name</span>
                            <input type="text" name="name" maxlength="120"
                                   value="<?= e($row['name'] ?? '') ?>">
                        </label>
                        <label class="field">
                            <span class="field-label">Phone</span>
                            <input type="tel" name="phone" maxlength="40"
                                   value="<?= e($row['phone'] ?? '') ?>">
                        </label>
                    </div>

                    <label class="field">
                        <span class="field-label">Email</span>
                        <input type="email" value="<?= e($row['email']) ?>" disabled>
                        <span class="field-hint">Email changes aren't supported yet — contact support.</span>
                    </label>

                    <button type="submit" class="btn btn-primary">
                        <?= slate_icon('check', 'icon icon-sm') ?> Save profile
                    </button>
                </form>
            <?php slate_portal_card_close(); ?>
        </div>

        <div class="col-5">
            <div class="stack">
                <?php slate_portal_card_open('Account', 'Details', null, null, 'card-static'); ?>
                    <div class="list">
                        <div class="list-row">
                            <span class="icon-box"><?= slate_icon('user') ?></span>
                            <div class="list-main">
                                <div class="list-title"><?= e($row['email']) ?></div>
                                <div class="list-sub">Email address</div>
                            </div>
                            <span class="badge <?= $verified ? 'badge-green' : 'badge-amber' ?>">
                                <?= $verified ? 'Verified' : 'Unverified' ?>
                            </span>
                        </div>
                        <div class="list-row">
                            <span class="icon-box"><?= slate_icon('calendar') ?></span>
                            <div class="list-main">
                                <div class="list-title"><?= e(date('j M Y', strtotime($row['created_at'] ?? 'now'))) ?></div>
                                <div class="list-sub">Joined</div>
                            </div>
                        </div>
                        <?php if (!empty($row['last_login_at'])): ?>
                            <div class="list-row">
                                <span class="icon-box"><?= slate_icon('clock') ?></span>
                                <div class="list-main">
                                    <div class="list-title"><?= e(date('j M Y, H:i', strtotime($row['last_login_at']))) ?></div>
                                    <div class="list-sub">Last sign in</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php slate_portal_card_close(); ?>

                <?php slate_portal_card_open('Security', 'Password', null, null, 'card-static'); ?>
                    <p class="muted" style="margin:0 0 var(--sp-4);font-size:14px;">
                        Forgot your password or want to change it? Sign out and use the reset flow.
                    </p>
                    <a href="<?= e(SLATE_URL) ?>/customer/logout.php?csrf=<?= e(csrf_token()) ?>" class="btn">
                        <?= slate_icon('logout', 'icon icon-sm') ?> Sign out
                    </a>
                <?php slate_portal_card_close(); ?>
            </div>
        </div>
    </div>

    <!-- ── Plugin widgets ──────────────────────────────────────── -->
    <div class="grid" style="margin-top:var(--sp-5)">
        <div class="col-12">
            <?php if (!empty($widgets)): ?>
                <span class="eyebrow">Your activity</span>
                <div class="stack" style="margin-top:var(--sp-3)">
                    <?php foreach ($widgets as $widget): ?>
                        <?= $widget ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php slate_portal_card_open('', '', null, null, 'card-static'); ?>
                    <?php slate_portal_empty(
                        'No activity yet',
                        "When you book a service, place an order, or submit a form, it'll show up here.",
                        'activity'
                    ); ?>
                <?php slate_portal_card_close(); ?>
            <?php endif; ?>
        </div>
    </div>

</main>

<?php slate_portal_foot(); ?>
