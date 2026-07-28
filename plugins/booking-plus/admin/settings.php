<?php
/**
 * Booking+ — global settings (glass UI).
 *
 * Cross-service defaults + the seeded reminder cadence. Per-service
 * extras live in service.php.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingPlusAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('bookingplus.manage_settings');
BookingPlusAPI::ensureSchema();

$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        Database::setSetting('bookingplus.whatsapp_url', trim((string)($_POST['whatsapp_url'] ?? '')));
        Database::setSetting('bookingplus.nudge_hours',  (string) max(1, (int)($_POST['nudge_hours'] ?? 8)));

        $leads = trim((string)($_POST['reminder_leads'] ?? ''));
        if ($leads !== '') {
            $parts = array_filter(array_map('intval', explode(',', $leads)), fn($n) => $n > 0);
            Database::setSetting('booking.reminder_leads', implode(',', $parts));
        }
        $flash = ['type' => 'success', 'msg' => 'Saved.'];
    }
}

$whatsapp = (string) (Database::setting('bookingplus.whatsapp_url') ?? '');
$nudgeH   = (int) (Database::setting('bookingplus.nudge_hours') ?? 8);
$leads    = (string) (Database::setting('booking.reminder_leads') ?? BookingPlusAPI::defaultReminderLeads());

$pageTitle  = 'Booking+ · Settings';
$currentNav = 'bookingplus-settings';

require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Booking+',  'href' => plugin_url('booking-plus', 'admin/index.php')],
    ['label' => 'Settings'],
]);
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : 'danger') ?>" style="margin-bottom:var(--space-4);">
        <?= e($flash['msg']) ?>
    </div>
<?php endif; ?>

<?php slate_editor_css(); ?>

<?php slate_edit_open(['title_fallback' => 'Booking+ Settings']); ?>

    <form method="post">
        <?= csrf_field() ?>

        <?php slate_edit_backlink([
            'back_href'  => plugin_url('booking-plus', 'admin/index.php'),
            'back_label' => 'Booking+ Messages',
        ]); ?>

        <?php slate_edit_hero([
            'icon'        => 'shield',
            'title'       => 'Booking+ Settings',
            'status_text' => 'Global defaults',
            'status_tone' => 'on',
        ]); ?>

        <?php slate_edit_card_open(['icon' => 'link', 'eyebrow' => 'Defaults', 'title' => 'Global defaults']); ?>
            <?php slate_edit_card_note('Per-service extras live under <a href="' . e(plugin_url('booking-plus', 'admin/services.php')) . '">Booking+ Services</a>.'); ?>

            <div class="field">
                <label class="field-label" for="whatsapp_url">Default WhatsApp deeplink</label>
                <input type="url" id="whatsapp_url" name="whatsapp_url" maxlength="500"
                       value="<?= e($whatsapp) ?>"
                       placeholder="https://wa.me/33XXXXXXXXX">
                <div class="field-hint">Used in the auto-response when a service does not set its own.</div>
            </div>

            <div class="field" style="margin-bottom:0;">
                <label class="field-label" for="nudge_hours">Internal-nudge window (hours)</label>
                <input type="number" id="nudge_hours" name="nudge_hours" min="1" max="72"
                       value="<?= (int)$nudgeH ?>">
                <div class="field-hint">If a client message is not marked replied within this window, you get an email nudge.</div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'bell', 'eyebrow' => 'Cadence', 'title' => 'Reminder cadence (shared with Booking)']); ?>
            <?php slate_edit_card_note('Comma-separated minutes before the session — one reminder per lead time. Booking+ seeds <code>11520,1440,10</code> on activation (8 days, 1 day, 10 minutes).'); ?>

            <div class="field" style="margin-bottom:0;">
                <label class="field-label" for="reminder_leads">Lead times (minutes, comma-separated)</label>
                <input type="text" id="reminder_leads" name="reminder_leads" maxlength="200"
                       value="<?= e($leads) ?>"
                       placeholder="11520,1440,10">
                <div class="field-hint">Common values: 8 days = 11520, 1 day = 1440, 1 hour = 60, 10 min = 10.</div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_actionbar([
            'buttons_html' =>
                '<a href="' . e(plugin_url('booking-plus', 'admin/index.php')) . '" class="btn btn-ghost">Cancel</a>'
              . '<button type="submit" class="btn btn-primary">Save</button>',
        ]); ?>

    </form>

<?php slate_edit_close(); ?>
<?php slate_editor_js(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
