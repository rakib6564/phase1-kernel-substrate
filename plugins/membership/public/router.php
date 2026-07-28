<?php
/**
 * Membership — member-facing area.  URL: /member
 *
 *   ?view=home    (default)  — membership status + quick links
 *   ?view=onboarding         — 4-step profile-completion wizard (gate)
 *   ?view=profile            — view / edit member profile (+ avatar)
 *   ?view=plans              — browse + buy plans (POST _action=buy)
 *   ?view=schedule           — upcoming bookings (read from the Booking plugin)
 *   ?view=card               — digital member card + QR (downloadable)
 *   ?view=return             — Stripe checkout return (verifies + activates)
 *   ?view=wallet             — balance + transaction ledger
 *   POST _action=cancel_sub  — member self-cancels their membership
 *
 * Identity is core customers — the whole area requires a signed-in customer.
 * Access to everything except the wizard is gated on a completed profile
 * (4-step onboarding), per the product spec.
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
require_once dirname(__DIR__) . '/MembershipAPI.php';
MembershipAPI::ensureSchema();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

Auth::requireCustomer();
$cid  = (int) Auth::customerId();
$tid  = current_tenant_id();
$view = (string)($_GET['view'] ?? 'home');
$flash = null;

MembershipAPI::ensureProfile($cid);

// ── POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('membership_csrf', 'Security check failed.')];
    } else {
        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'buy') {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $res = MembershipAPI::purchase($cid, $planId, !empty($_POST['add_insurance']));
            if (!empty($res['ok']) && !empty($res['url'])) { header('Location: ' . $res['url']); exit; }
            if (!empty($res['ok']) && !empty($res['free'])) { header('Location: ' . SLATE_URL . '/member?view=home&activated=1'); exit; }
            $flash = ['type' => 'error', 'msg' => $res['error'] ?? __('membership_buy_failed', 'Could not start the purchase.')];
            $view = 'plans';
        }

        elseif ($action === 'cancel_sub') {
            $subId = (int)($_POST['sub_id'] ?? 0);
            $sub   = MembershipAPI::subscription($subId);
            if ($sub && (int)$sub['customer_id'] === $cid) {
                MembershipAPI::cancelSubscription($subId, true);
                $flash = ['type' => 'success', 'msg' => __('membership_cancelled_ok', 'Your membership was cancelled.')];
            } else {
                $flash = ['type' => 'error', 'msg' => __('membership_not_found', 'Subscription not found.')];
            }
            $view = 'home';
        }

        elseif ($action === 'save_onboarding') {
            $step = max(1, min(4, (int)($_POST['step'] ?? 1)));
            $prof = MembershipAPI::profile($cid) ?: [];
            $fields = [];

            if ($step === 1) {
                $g = (string)($_POST['gender'] ?? 'undisclosed');
                $fields['gender'] = array_key_exists($g, MembershipAPI::genders()) ? $g : 'undisclosed';
                $fields['dob']    = trim((string)($_POST['dob'] ?? '')) !== '' ? (string)$_POST['dob'] : null;
                // Phone lives on the core customer record.
                $phone = trim((string)($_POST['phone'] ?? ''));
                Database::update('customers', ['phone' => $phone !== '' ? mb_substr($phone, 0, 40) : null], 'id = ? AND tenant_id = ?', [$cid, $tid]);
            } elseif ($step === 2) {
                $sk = (string)($_POST['skill_level'] ?? 'none');
                $fields['skill_level']   = array_key_exists($sk, MembershipAPI::skillLevels()) ? $sk : 'none';
                $fields['medical_notes'] = trim((string)($_POST['medical_notes'] ?? '')) ?: null;
                $fields['allergies']     = trim((string)($_POST['allergies'] ?? '')) ?: null;
            } elseif ($step === 3) {
                $fields['emergency_name']     = trim((string)($_POST['emergency_name'] ?? '')) ?: null;
                $fields['emergency_phone']    = trim((string)($_POST['emergency_phone'] ?? '')) ?: null;
                $fields['emergency_relation'] = trim((string)($_POST['emergency_relation'] ?? '')) ?: null;
            } elseif ($step === 4) {
                if (empty($_POST['consent_terms'])) {
                    $flash = ['type' => 'error', 'msg' => __('membership_consent_required', 'You must accept the terms to continue.')];
                    $view = 'onboarding';
                } else {
                    $fields['consent_terms'] = 1;
                    $fields['consent_media'] = !empty($_POST['consent_media']) ? 1 : 0;
                    $fields['consent_at']    = date('Y-m-d H:i:s');
                    $fields['onboarding_complete'] = 1;
                }
            }

            if (!($step === 4 && !empty($flash))) {
                $fields['onboarding_step'] = max((int)($prof['onboarding_step'] ?? 0), $step);
                Database::update('membership_profiles', $fields, 'customer_id = ? AND tenant_id = ?', [$cid, $tid]);
                AuditLog::record('membership.onboarding_step', (string)$cid, ['step' => $step]);
                if ($step >= 4) { header('Location: ' . SLATE_URL . '/member?view=home&welcome=1'); exit; }
                header('Location: ' . SLATE_URL . '/member?view=onboarding&step=' . ($step + 1)); exit;
            }
        }

        elseif ($action === 'save_profile') {
            $g  = (string)($_POST['gender'] ?? 'undisclosed');
            $sk = (string)($_POST['skill_level'] ?? 'none');
            $fields = [
                'gender'             => array_key_exists($g, MembershipAPI::genders()) ? $g : 'undisclosed',
                'dob'                => trim((string)($_POST['dob'] ?? '')) !== '' ? (string)$_POST['dob'] : null,
                'skill_level'        => array_key_exists($sk, MembershipAPI::skillLevels()) ? $sk : 'none',
                'medical_notes'      => trim((string)($_POST['medical_notes'] ?? '')) ?: null,
                'allergies'          => trim((string)($_POST['allergies'] ?? '')) ?: null,
                'emergency_name'     => trim((string)($_POST['emergency_name'] ?? '')) ?: null,
                'emergency_phone'    => trim((string)($_POST['emergency_phone'] ?? '')) ?: null,
                'emergency_relation' => trim((string)($_POST['emergency_relation'] ?? '')) ?: null,
            ];
            // Avatar (optional).
            if (!empty($_FILES['avatar']['name'])) {
                $up = Uploads::handle('avatar', 'membership/avatars', [
                    'max_bytes'     => 4 * 1024 * 1024,
                    'allowed_exts'  => ['jpg', 'jpeg', 'png', 'webp'],
                    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                ]);
                if (!empty($up['ok'])) {
                    $old = MembershipAPI::profile($cid)['avatar_path'] ?? '';
                    $fields['avatar_path'] = $up['path'];
                    if ($old) { try { Uploads::remove($old); } catch (\Throwable $e) {} }
                } else {
                    $flash = ['type' => 'error', 'msg' => $up['error'] ?? 'Avatar upload failed.'];
                }
            }
            $phone = trim((string)($_POST['phone'] ?? ''));
            Database::update('customers', ['phone' => $phone !== '' ? mb_substr($phone, 0, 40) : null], 'id = ? AND tenant_id = ?', [$cid, $tid]);
            Database::update('membership_profiles', $fields, 'customer_id = ? AND tenant_id = ?', [$cid, $tid]);
            AuditLog::record('membership.profile_updated', (string)$cid);
            if (!$flash) $flash = ['type' => 'success', 'msg' => __('membership_profile_saved', 'Profile saved.')];
            $view = 'profile';
        }
    }
}

// ── Stripe return: verify the session, activate if the webhook hasn't yet ──
if ($view === 'return') {
    $subId   = (int)($_GET['sub'] ?? 0);
    $session = (string)($_GET['session_id'] ?? '');
    $sub     = MembershipAPI::subscription($subId);
    if ($sub && (int)$sub['customer_id'] === $cid) {
        if (($sub['status'] ?? '') !== 'active' && $session !== '' && class_exists('StripePaymentAPI')) {
            $s = StripePaymentAPI::getSession($session);
            if ($s && ($s['payment_status'] ?? '') === 'paid') {
                MembershipAPI::activateSubscription($subId, ['amount_cents' => (int)($s['amount_total'] ?? $sub['amount_cents'])]);
            }
        }
        $sub   = MembershipAPI::subscription($subId);
        $flash = ($sub && $sub['status'] === 'active')
            ? ['type' => 'success', 'msg' => __('membership_activated_ok', 'Payment received — your membership is active!')]
            : ['type' => 'info', 'msg' => __('membership_processing', 'Payment received — your membership will be active shortly.')];
    }
    $view = 'home';
}

$profile = MembershipAPI::profile($cid) ?: [];
$status  = MembershipAPI::status($cid);
$onboarded = !empty($profile['onboarding_complete']);

// Profile-completion gate: until the wizard is done, the only view is itself.
if (!$onboarded && $view !== 'onboarding') {
    $view = 'onboarding';
}

// ── App shell (self-contained, mobile-app style) ────────────────────────
$selfUrl  = SLATE_URL . '/member';
$cust     = Auth::customer();
$siteName = Database::setting('site_name') ?: 'Slate';
$locale   = I18n::currentLocale();

// Brand accent + logo come from the SAME core settings the rest of the
// portal reads (brand_accent_color / brand_logo_path), so a tenant changing
// their brand colour in Admin shifts membership with everything else. This
// used to read ContentBuilder's accent_color, which drifted to a different
// cyan than core and broke the "one brand, one accent" contract.
$accent = trim((string) Database::setting('brand_accent_color'));
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) $accent = '#2563EB';

$logoRel = trim((string) Database::setting('brand_logo_path'));
$logoUrl = $logoRel !== '' ? SLATE_URL . '/' . ltrim($logoRel, '/') : '';
// Fall back to ContentBuilder's logo only if core has none set.
if ($logoUrl === '' && class_exists('ContentBuilderAPI')) {
    $logoUrl = (string) ContentBuilderAPI::mediaUrl((string) ContentBuilderAPI::getSiteSetting('logo_url', ''));
}

$memberName = (string)($cust['name'] ?? $cust['email'] ?? 'Member');
$initial    = mb_strtoupper(mb_substr($memberName, 0, 1));
$avatarPath = (string)($profile['avatar_path'] ?? '');

$tabs = [
    ['v'=>'home',     'label'=>__('membership_home', 'Home'),       'icon'=>'home'],
    ['v'=>'plans',    'label'=>__('membership_plans', 'Plans'),     'icon'=>'tag'],
    ['v'=>'card',     'label'=>__('membership_card', 'Card'),       'icon'=>'qr'],
    ['v'=>'schedule', 'label'=>__('membership_schedule', 'Schedule'),'icon'=>'cal'],
    ['v'=>'profile',  'label'=>__('membership_profile', 'Profile'), 'icon'=>'user'],
];
$tabIcon = function (string $n) {
    $p = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
        'tag'  => '<path d="M3 11V5a2 2 0 0 1 2-2h6l9 9-8 8-9-9z"/><circle cx="8" cy="8" r="1.4"/>',
        'qr'   => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v7M17 21h4"/>',
        'cal'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'wallet'=> '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M17 14h2"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? $p['home']) . '</svg>';
};
$showChrome = $onboarded;   // hide tab bar + top nav during onboarding
?><!DOCTYPE html>
<html lang="<?= e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e($accent) ?>">
    <title><?= e(__('membership_your', 'Your membership')) ?> — <?= e($siteName) ?></title>
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <?php slate_ui_emit_css(); $cssVer = @filemtime(dirname(__DIR__) . '/assets/css/app.css') ?: time(); ?>
    <link rel="stylesheet" href="<?= e(plugin_url('membership', 'assets/css/app.css')) ?>?v=<?= e((string)$cssVer) ?>">
    <style>
    :root{
        --accent:<?= $accent ?>;
        --accent-deep:color-mix(in srgb,<?= $accent ?> 84%, #000);
        --accent-soft:color-mix(in srgb,<?= $accent ?> 9%, #fff);
        --accent-ring:color-mix(in srgb,<?= $accent ?> 22%, transparent);
    }
    </style>
</head>
<body class="mapp">

<header class="mapp-bar">
    <div class="mapp-bar-in">
        <a href="<?= e($selfUrl) ?>" class="mapp-brand">
            <?php if ($logoUrl !== ''): ?><img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>"><?php else: ?><span class="mapp-brand-mark"><?= e(mb_strtoupper(mb_substr($siteName,0,1))) ?></span><?php endif; ?>
        </a>
        <?php if ($showChrome): ?>
        <nav class="mapp-toptabs">
            <?php foreach ($tabs as $t): ?>
                <a href="<?= e($selfUrl) ?>?view=<?= e($t['v']) ?>" class="<?= $view===$t['v']?'on':'' ?>"><?= $tabIcon($t['icon']) ?><span><?= e($t['label']) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <div class="mapp-bar-end">
            <span class="mapp-lang">
                <a href="?view=<?= e($view) ?>&lang=fr" class="<?= $locale==='fr'?'on':'' ?>">FR</a>
                <a href="?view=<?= e($view) ?>&lang=en" class="<?= $locale==='en'?'on':'' ?>">EN</a>
            </span>
            <span class="mapp-avatar" title="<?= e($memberName) ?>">
                <?php if ($avatarPath !== ''): ?><img src="<?= e(SLATE_URL . $avatarPath) ?>" alt=""><?php else: ?><?= e($initial) ?><?php endif; ?>
            </span>
            <a href="<?= e(SLATE_URL) ?>/customer/logout.php?csrf=<?= e(csrf_token()) ?>" class="mapp-signout" title="<?= e(__('sign_out','Sign out')) ?>" aria-label="<?= e(__('sign_out','Sign out')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            </a>
        </div>
    </div>
</header>

<main class="mapp-main<?= $showChrome?'':' mapp-main--plain' ?>">
    <?php if ($flash): ?><div class="mapp-flash mapp-flash--<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
    <?php
    $views = dirname(__DIR__) . '/public/views';
    $known = ['onboarding', 'profile', 'schedule', 'card', 'plans', 'wallet', 'home'];
    $v = in_array($view, $known, true) ? $view : 'home';
    require $views . '/' . $v . '.php';
    ?>
</main>

<?php if ($showChrome): ?>
<nav class="mapp-tabbar" aria-label="<?= e(__('membership','Membership')) ?>">
    <?php foreach ($tabs as $t): ?>
        <a href="<?= e($selfUrl) ?>?view=<?= e($t['v']) ?>" class="<?= $view===$t['v']?'on':'' ?>">
            <?= $tabIcon($t['icon']) ?><span><?= e($t['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

</body>
</html>
