<?php
/**
 * Booking — appointments list (filter by provider / status / date range).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.view');
BookingAPI::ensureSchema();

$pageTitle  = 'Appointments';
$currentNav = 'booking-appointments';

$tid = current_tenant_id();

$providerId = (int)($_GET['provider_id'] ?? 0);
$status     = (string)($_GET['status'] ?? '');
$from       = (string)($_GET['from'] ?? '');
$to         = (string)($_GET['to']   ?? '');

$where  = ['a.tenant_id = ?'];
$params = [$tid];
if ($providerId > 0)             { $where[] = 'a.provider_id = ?'; $params[] = $providerId; }
if (in_array($status, ['pending','confirmed','cancelled','no_show','completed'], true)) {
    $where[] = 'a.status = ?'; $params[] = $status;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'a.starts_at >= ?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'a.starts_at <= ?'; $params[] = $to   . ' 23:59:59'; }

// Pagination
$perPage    = 30;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalRows  = (int) Database::value(
    "SELECT COUNT(*) FROM booking_appointments a WHERE " . implode(' AND ', $where), $params);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = Database::rows(
    "SELECT a.*, s.name AS service_name, p.name AS provider_name
       FROM booking_appointments a
       JOIN booking_services  s ON s.id = a.service_id
       JOIN booking_providers p ON p.id = a.provider_id
      WHERE " . implode(' AND ', $where) . "
   ORDER BY a.starts_at DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$providers = BookingAPI::getAllProviders();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => 'Appointments'],
]); ?>

<div class="page-header">
    <div><h1>Appointments</h1><p class="page-header-sub"><?= number_format($totalRows) ?> total.</p></div>
    <?php if (Auth::can('booking.manage_appointments')): ?>
        <a href="<?= e(plugin_url('booking', 'admin/new.php')) ?>" class="btn btn-primary">New / walk-in</a>
    <?php endif; ?>
</div>

<div class="card tight">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="provider_id">Provider</label>
            <select id="provider_id" name="provider_id">
                <option value="0">All providers</option>
                <?php foreach ($providers as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $providerId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <option value="pending"    <?= $status==='pending'    ? 'selected':'' ?>>Pending</option>
                <option value="confirmed"  <?= $status==='confirmed'  ? 'selected':'' ?>>Confirmed</option>
                <option value="cancelled"  <?= $status==='cancelled'  ? 'selected':'' ?>>Cancelled</option>
                <option value="no_show"    <?= $status==='no_show'    ? 'selected':'' ?>>No show</option>
                <option value="completed"  <?= $status==='completed'  ? 'selected':'' ?>>Completed</option>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="from">From</label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
            <label class="field-label" for="to">To</label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="filter-actions">
            <button class="btn">Filter</button>
            <a href="?" class="btn btn-ghost">Reset</a>
        </div>
    </form>
</div>

<?php if (!$rows): ?>
    <div class="card"><div class="empty"><div class="empty-title">No appointments match</div></div></div>
<?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($rows as $a):
            $statusBadge = [
                'pending'   => ['Pending', 'warning'],
                'confirmed' => ['Confirmed', 'active'],
                'cancelled' => ['Cancelled', 'inactive'],
                'no_show'   => ['No show', 'warning'],
                'completed' => ['Completed', 'accent'],
            ][$a['status']] ?? ['?', ''];

            $detail = [
                'Ref'           => ['label' => 'Ref', 'html' => '<code>' . e($a['ref']) . '</code>'],
                'When'          => date('l, j F Y, H:i', strtotime($a['starts_at'])) . ' – ' . date('H:i', strtotime($a['ends_at'])),
                'Service'       => $a['service_name'],
                'Provider'      => $a['provider_name'],
                'Customer'      => $a['customer_name'] . ' · ' . $a['customer_email']
                                   . ($a['customer_phone'] ? ' · ' . $a['customer_phone'] : ''),
            ];
            if (!empty($a['notes'])) $detail['Notes'] = ['label'=>'Notes','value'=>$a['notes'],'muted'=>true];

            $actions = '<a href="' . e(plugin_url('booking', 'admin/appointment.php')) . '?id=' . (int)$a['id'] . '" class="btn btn-sm btn-primary">Open</a>';

            slate_data_row([
                'avatar'       => mb_substr($a['customer_name'], 0, 1),
                'avatar_color' => $a['status'] === 'confirmed' ? 'info' : 'muted',
                'title'        => $a['customer_name'] . ' · ' . $a['service_name'],
                'meta'         => date('D j M, H:i', strtotime($a['starts_at'])) . ' · ' . $a['provider_name'],
                'badge'        => $statusBadge,
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php slate_pagination($page, $totalPages, $_GET, [
        'total' => $totalRows, 'per_page' => $perPage, 'label' => 'appointments',
    ]); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
