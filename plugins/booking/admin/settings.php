<?php
/**
 * Booking — settings (reminders, follow-ups, SMS/WhatsApp gateway).
 * Values are stored in the core settings table under the "booking." prefix,
 * matching Plugin::setting()/BookingAPI reads.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.manage_settings');
BookingAPI::ensureSchema();

$pageTitle  = 'Booking settings';
$currentNav = 'booking-settings';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        // Normalise reminder leads to a clean CSV of positive minutes.
        $leads = [];
        foreach (explode(',', (string)($_POST['reminder_leads'] ?? '')) as $p) {
            $m = (int) trim($p);
            if ($m > 0) $leads[$m] = true;
        }
        $leadsCsv = implode(',', array_keys($leads)) ?: '1440,60';

        Database::setSetting('booking.reminder_leads',       $leadsCsv);

        // Booking-widget feature toggles (default on). Each controls
        // whether the matching section renders on the public confirm step.
        Database::setSetting('booking.show_coupon',     !empty($_POST['show_coupon'])    ? '1' : '0');
        Database::setSetting('booking.show_gift_card',  !empty($_POST['show_gift_card']) ? '1' : '0');
        Database::setSetting('booking.show_repeat',     !empty($_POST['show_repeat'])    ? '1' : '0');

        Database::setSetting('booking.followup_enabled',     !empty($_POST['followup_enabled']) ? '1' : '0');
        Database::setSetting('booking.followup_delay_hours', (string) max(1, (int)($_POST['followup_delay_hours'] ?? 24)));
        Database::setSetting('booking.loyalty_points_per_booking', (string) max(0, (int)($_POST['loyalty_points_per_booking'] ?? 0)));
        Database::setSetting('booking.sms_enabled',          !empty($_POST['sms_enabled']) ? '1' : '0');
        Database::setSetting('booking.whatsapp_enabled',     !empty($_POST['whatsapp_enabled']) ? '1' : '0');
        Database::setSetting('booking.twilio_sid',           trim((string)($_POST['twilio_sid'] ?? '')));
        Database::setSetting('booking.twilio_sms_from',      trim((string)($_POST['twilio_sms_from'] ?? '')));
        Database::setSetting('booking.twilio_whatsapp_from', trim((string)($_POST['twilio_whatsapp_from'] ?? '')));
        // Token is write-only: only overwrite when a new value is supplied.
        $token = trim((string)($_POST['twilio_token'] ?? ''));
        if ($token !== '') Database::setSetting('booking.twilio_token', $token);

        AuditLog::record('booking.settings_updated', 'booking');
        $flash = ['type' => 'success', 'msg' => 'Settings saved.'];
    }
}

$g = fn(string $k, $d = '') => (string)(Database::setting('booking.' . $k) ?? $d);
$hasToken = $g('twilio_token') !== '';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => 'Settings'],
]); ?>

<div class="page-header"><div><h1>Booking settings</h1></div></div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

<form method="post">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header"><h2>Booking widget</h2></div>
        <p class="text-sm text-muted">Show or hide optional sections on the public booking form's confirm step.</p>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="show_coupon" value="1" <?= $g('show_coupon', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span>Show <strong>coupon code</strong> field</span>
            </label>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="show_gift_card" value="1" <?= $g('show_gift_card', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span>Show <strong>gift card</strong> field</span>
            </label>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="show_repeat" value="1" <?= $g('show_repeat', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span>Show <strong>Repeat</strong> (weekly recurring) options</span>
            </label>
            <div class="field-hint">When off, every booking is one-time.</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Reminders &amp; follow-ups</h2></div>
        <div class="field">
            <label class="field-label" for="reminder_leads">Reminder lead times (minutes, comma-separated)</label>
            <input type="text" id="reminder_leads" name="reminder_leads" value="<?= e($g('reminder_leads', '1440,60')) ?>" placeholder="1440,60">
            <div class="field-hint">e.g. <code>1440,60</code> = 24 hours and 1 hour before. Sent by email (and SMS/WhatsApp if enabled).</div>
        </div>
        <div class="field-row field-row-2">
            <div class="field" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="followup_enabled" value="1" <?= $g('followup_enabled') === '1' ? 'checked' : '' ?>>
                    <span>Send a follow-up / feedback email after the appointment</span>
                </label>
            </div>
            <div class="field">
                <label class="field-label" for="followup_delay_hours">Follow-up delay (hours after end)</label>
                <input type="number" id="followup_delay_hours" name="followup_delay_hours" min="1" step="1" value="<?= e($g('followup_delay_hours', '24')) ?>">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="loyalty_points_per_booking">Loyalty points per completed appointment</label>
            <input type="number" id="loyalty_points_per_booking" name="loyalty_points_per_booking" min="0" step="1" value="<?= e($g('loyalty_points_per_booking', '0')) ?>">
            <div class="field-hint">Awarded automatically when an appointment is marked completed. 0 disables loyalty.</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>SMS &amp; WhatsApp (Twilio)</h2></div>
        <p class="text-sm text-muted">Customers with a phone number receive text/WhatsApp confirmations and reminders when enabled.</p>
        <div class="field-row field-row-2">
            <div class="field" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="sms_enabled" value="1" <?= $g('sms_enabled') === '1' ? 'checked' : '' ?>>
                    <span>Enable SMS reminders</span>
                </label>
            </div>
            <div class="field" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="whatsapp_enabled" value="1" <?= $g('whatsapp_enabled') === '1' ? 'checked' : '' ?>>
                    <span>Enable WhatsApp messages</span>
                </label>
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="twilio_sid">Twilio Account SID</label>
                <input type="text" id="twilio_sid" name="twilio_sid" value="<?= e($g('twilio_sid')) ?>" autocomplete="off">
            </div>
            <div class="field">
                <label class="field-label" for="twilio_token">Auth Token <?php if ($hasToken): ?><span class="text-muted text-xs">(set — leave blank to keep)</span><?php endif; ?></label>
                <input type="password" id="twilio_token" name="twilio_token" value="" autocomplete="new-password" placeholder="<?= $hasToken ? '••••••••' : '' ?>">
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="twilio_sms_from">SMS sender (E.164)</label>
                <input type="text" id="twilio_sms_from" name="twilio_sms_from" value="<?= e($g('twilio_sms_from')) ?>" placeholder="+15555550123">
            </div>
            <div class="field">
                <label class="field-label" for="twilio_whatsapp_from">WhatsApp sender</label>
                <input type="text" id="twilio_whatsapp_from" name="twilio_whatsapp_from" value="<?= e($g('twilio_whatsapp_from')) ?>" placeholder="+15555550123">
            </div>
        </div>
    </div>

    <div class="flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary">Save settings</button>
    </div>
</form>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
