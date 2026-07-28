<?php
/**
 * Booking — admin overview.
 * Today + this-week appointments at a glance.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.view');
BookingAPI::ensureSchema();

$pageTitle  = __('booking', 'Booking');
$currentNav = 'booking';

$tid = current_tenant_id();

$todayList = Database::rows(
    "SELECT a.*, s.name AS service_name, p.name AS provider_name
       FROM booking_appointments a
       JOIN booking_services  s ON s.id = a.service_id
       JOIN booking_providers p ON p.id = a.provider_id
      WHERE a.tenant_id = ? AND a.status = 'confirmed'
        AND DATE(a.starts_at) = CURDATE()
   ORDER BY a.starts_at",
    [$tid]
);

$weekList = Database::rows(
    "SELECT a.*, s.name AS service_name, p.name AS provider_name
       FROM booking_appointments a
       JOIN booking_services  s ON s.id = a.service_id
       JOIN booking_providers p ON p.id = a.provider_id
      WHERE a.tenant_id = ? AND a.status = 'confirmed'
        AND a.starts_at >  NOW()
        AND a.starts_at <= NOW() + INTERVAL 7 DAY
   ORDER BY a.starts_at LIMIT 50",
    [$tid]
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking')],
]); ?>

<div class="page-header">
    <div>
        <h1>Booking</h1>
        <p class="page-header-sub">Today and the week ahead.</p>
    </div>
    <div class="toolbar">
        <a href="<?= e(plugin_url('booking', 'admin/services.php'))  ?>" class="btn">Services</a>
        <a href="<?= e(plugin_url('booking', 'admin/providers.php')) ?>" class="btn">Providers</a>
        <a href="<?= e(SLATE_URL) ?>/book" target="_blank" class="btn btn-primary">Open public widget ↗</a>
    </div>
</div>

<?php slate_page_layout('with-aside'); ?>
    <div class="page-main">
        <div class="card">
            <div class="card-header">
                <h2>Today (<?= count($todayList) ?>)</h2>
                <a href="<?= e(plugin_url('booking', 'admin/appointments.php')) ?>" class="btn btn-sm">All</a>
            </div>
            <?php if (!$todayList): ?>
                <div class="empty"><div class="empty-title">Nothing today</div></div>
            <?php else: ?>
                <ul class="kv-list">
                    <?php foreach ($todayList as $a): ?>
                        <li class="kv-row">
                            <span class="kv-label">
                                <strong style="color:var(--text);"><?= e(date('H:i', strtotime($a['starts_at']))) ?></strong>
                                · <?= e($a['service_name']) ?>
                            </span>
                            <span class="kv-value">
                                <?= e($a['customer_name']) ?>
                                <span class="text-xs text-muted">with <?= e($a['provider_name']) ?></span>
                                <a href="<?= e(plugin_url('booking', 'admin/appointment.php')) ?>?id=<?= (int)$a['id'] ?>" class="text-xs">Open</a>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><h2>Next 7 days (<?= count($weekList) ?>)</h2></div>
            <?php if (!$weekList): ?>
                <div class="empty"><div class="empty-title">Nothing booked yet</div></div>
            <?php else: ?>
                <ul class="kv-list">
                    <?php foreach ($weekList as $a): ?>
                        <li class="kv-row">
                            <span class="kv-label">
                                <?= e(date('D j M, H:i', strtotime($a['starts_at']))) ?>
                                · <?= e($a['service_name']) ?>
                            </span>
                            <span class="kv-value">
                                <?= e($a['customer_name']) ?>
                                <a href="<?= e(plugin_url('booking', 'admin/appointment.php')) ?>?id=<?= (int)$a['id'] ?>" class="text-xs">Open</a>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title">Public booking</div>
            <p class="text-sm text-muted" style="margin:0 0 var(--space-3);">Customers book at <code>/book</code>. Embed it on any site with an iframe.</p>
            <pre class="snippet">&lt;iframe src="<?= e(SLATE_URL) ?>/book?embed=1"
  style="width:100%;border:0;min-height:640px"&gt;
&lt;/iframe&gt;</pre>
        </div>
        <div class="aside-card">
            <div class="aside-card-title">Setup checklist</div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label">1. Add services</span><span class="kv-value"><a href="<?= e(plugin_url('booking', 'admin/services.php')) ?>">→</a></span></li>
                <li class="kv-row"><span class="kv-label">2. Add providers</span><span class="kv-value"><a href="<?= e(plugin_url('booking', 'admin/providers.php')) ?>">→</a></span></li>
                <li class="kv-row"><span class="kv-label">3. Set weekly hours</span><span class="kv-value">on provider edit</span></li>
                <li class="kv-row"><span class="kv-label">4. Link services to providers</span><span class="kv-value">on provider edit</span></li>
            </ul>
        </div>
    </aside>
<?php slate_page_layout_end(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
