<?php
/**
 * Booking — month calendar.
 * A 7-column month grid of appointments, coloured by provider, with prev/
 * next navigation and an optional provider filter. Each appointment links to
 * its detail page; a day's "+N more" link opens the filtered list view.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.view');
BookingAPI::ensureSchema();

$pageTitle  = 'Calendar';
$currentNav = 'booking-calendar';

$tid        = current_tenant_id();
$providerId = (int)($_GET['provider_id'] ?? 0);

// Resolve the month being viewed (YYYY-MM), defaulting to the current one.
$monthParam = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$first      = DateTime::createFromFormat('Y-m-d', $monthParam . '-01');
$first->setTime(0, 0, 0);
$monthLabel = $first->format('F Y');
$prevMonth  = (clone $first)->modify('-1 month')->format('Y-m');
$nextMonth  = (clone $first)->modify('+1 month')->format('Y-m');

// Grid spans whole weeks (Monday-start): back up to the Monday on/ before
// the 1st, forward to the Sunday on/after the last day of the month.
$last      = (clone $first)->modify('last day of this month');
$gridStart = (clone $first)->modify('-' . ((int)$first->format('N') - 1) . ' days');
$gridEnd   = (clone $last)->modify('+' . (7 - (int)$last->format('N')) . ' days');

// Fetch every appointment that starts within the visible grid.
$where  = ['a.tenant_id = ?', 'a.starts_at >= ?', 'a.starts_at <= ?'];
$params = [$tid, $gridStart->format('Y-m-d') . ' 00:00:00', $gridEnd->format('Y-m-d') . ' 23:59:59'];
if ($providerId > 0) { $where[] = 'a.provider_id = ?'; $params[] = $providerId; }

$rows = Database::rows(
    "SELECT a.id, a.starts_at, a.ends_at, a.status, a.customer_name,
            s.name AS service_name, p.name AS provider_name, p.color AS provider_color
       FROM booking_appointments a
       JOIN booking_services  s ON s.id = a.service_id
       JOIN booking_providers p ON p.id = a.provider_id
      WHERE " . implode(' AND ', $where) . "
   ORDER BY a.starts_at",
    $params
);

// Bucket appointments by their start date (Y-m-d).
$byDay = [];
foreach ($rows as $r) {
    $byDay[date('Y-m-d', strtotime($r['starts_at']))][] = $r;
}

$providers   = BookingAPI::getAllProviders();
$todayKey    = date('Y-m-d');
$thisMonth   = $first->format('Y-m');
$maxChips    = 4;

// Preserve the provider filter across month nav links.
$navQs = fn(string $month) => '?month=' . $month . ($providerId > 0 ? '&provider_id=' . $providerId : '');

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => 'Calendar'],
]); ?>

<style>
.bk-cal-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:16px;}
.bk-cal-nav{display:flex;align-items:center;gap:8px;}
.bk-cal-month{font-family:var(--font-display,inherit);font-size:1.25rem;font-weight:600;min-width:180px;}
.bk-cal-toolbar form{margin-left:auto;display:flex;align-items:center;gap:8px;}
.bk-cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:1px;background:var(--border);
    border:1px solid var(--border);border-radius:var(--radius,12px);overflow:hidden;}
.bk-cal-dow{background:var(--surface-sunken,var(--card));padding:8px 10px;font-size:.72rem;font-weight:600;
    letter-spacing:.04em;text-transform:uppercase;color:var(--muted);text-align:left;}
.bk-cal-cell{background:var(--card);min-height:118px;padding:6px 6px 8px;display:flex;flex-direction:column;gap:4px;}
.bk-cal-cell.is-out{background:var(--bg);}
.bk-cal-cell.is-today{box-shadow:inset 0 0 0 2px var(--accent);}
.bk-cal-daynum{font-size:.8rem;color:var(--muted);font-weight:600;text-decoration:none;display:inline-block;}
.bk-cal-cell.is-today .bk-cal-daynum{color:var(--accent);}
.bk-cal-daynum:hover{color:var(--text);}
.bk-cal-chip{display:block;font-size:.72rem;line-height:1.3;padding:2px 6px;border-radius:var(--radius-sm,6px);
    border-left:3px solid var(--accent);background:var(--accent-soft,var(--surface-sunken));color:var(--text);
    text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bk-cal-chip:hover{filter:brightness(.97);}
.bk-cal-chip.is-cancelled{opacity:.55;text-decoration:line-through;}
.bk-cal-chip-time{font-variant-numeric:tabular-nums;font-weight:600;}
.bk-cal-more{font-size:.7rem;color:var(--muted);text-decoration:none;padding:1px 6px;}
.bk-cal-more:hover{color:var(--accent);}
.bk-cal-legend{display:flex;flex-wrap:wrap;gap:14px;margin-top:12px;font-size:.74rem;color:var(--muted);}
.bk-cal-legend span{display:inline-flex;align-items:center;gap:5px;}
.bk-cal-dot{width:10px;height:10px;border-radius:3px;display:inline-block;}
@media (max-width:768px){
    .bk-cal-cell{min-height:84px;}
    .bk-cal-chip-cust{display:none;}
}
</style>

<div class="page-header">
    <div><h1>Calendar</h1><p class="page-header-sub"><?= e($monthLabel) ?></p></div>
    <?php if (Auth::can('booking.manage_appointments')): ?>
        <a href="<?= e(plugin_url('booking', 'admin/new.php')) ?>" class="btn btn-primary">New / walk-in</a>
    <?php endif; ?>
</div>

<div class="bk-cal-toolbar">
    <div class="bk-cal-nav">
        <a class="btn btn-sm" href="<?= e($navQs($prevMonth)) ?>" aria-label="Previous month">&larr;</a>
        <span class="bk-cal-month"><?= e($monthLabel) ?></span>
        <a class="btn btn-sm" href="<?= e($navQs($nextMonth)) ?>" aria-label="Next month">&rarr;</a>
        <a class="btn btn-sm btn-ghost" href="<?= e($navQs(date('Y-m'))) ?>">Today</a>
    </div>
    <form method="get">
        <input type="hidden" name="month" value="<?= e($monthParam) ?>">
        <label class="field-label" for="provider_id" style="margin:0;">Provider</label>
        <select id="provider_id" name="provider_id" onchange="this.form.submit()">
            <option value="0">All providers</option>
            <?php foreach ($providers as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $providerId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="bk-cal-grid">
    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dow): ?>
        <div class="bk-cal-dow"><?= e($dow) ?></div>
    <?php endforeach; ?>

    <?php
    $cursor = clone $gridStart;
    while ($cursor <= $gridEnd):
        $key      = $cursor->format('Y-m-d');
        $inMonth  = $cursor->format('Y-m') === $thisMonth;
        $isToday  = $key === $todayKey;
        $dayAppts = $byDay[$key] ?? [];
        $cls      = 'bk-cal-cell' . ($inMonth ? '' : ' is-out') . ($isToday ? ' is-today' : '');
        $dayList  = plugin_url('booking', 'admin/appointments.php') . '?from=' . $key . '&to=' . $key
                  . ($providerId > 0 ? '&provider_id=' . $providerId : '');
    ?>
        <div class="<?= $cls ?>">
            <a class="bk-cal-daynum" href="<?= e($dayList) ?>" title="View this day"><?= (int)$cursor->format('j') ?></a>
            <?php foreach (array_slice($dayAppts, 0, $maxChips) as $a):
                $color   = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$a['provider_color']) ? $a['provider_color'] : 'var(--accent)';
                $chipCls = 'bk-cal-chip' . ($a['status'] === 'cancelled' ? ' is-cancelled' : '');
                $first1  = trim((string)strtok((string)$a['customer_name'], ' '));
            ?>
                <a class="<?= $chipCls ?>" style="border-left-color:<?= e($color) ?>;"
                   href="<?= e(plugin_url('booking', 'admin/appointment.php')) ?>?id=<?= (int)$a['id'] ?>"
                   title="<?= e(date('H:i', strtotime($a['starts_at'])) . ' · ' . $a['customer_name'] . ' · ' . $a['service_name'] . ' · ' . $a['provider_name']) ?>">
                    <span class="bk-cal-chip-time"><?= e(date('H:i', strtotime($a['starts_at']))) ?></span>
                    <span class="bk-cal-chip-cust"> <?= e($first1) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (count($dayAppts) > $maxChips): ?>
                <a class="bk-cal-more" href="<?= e($dayList) ?>">+<?= count($dayAppts) - $maxChips ?> more</a>
            <?php endif; ?>
        </div>
    <?php
        $cursor->modify('+1 day');
    endwhile;
    ?>
</div>

<div class="bk-cal-legend">
    <span><span class="bk-cal-dot" style="background:var(--accent);"></span> Chip colour = provider</span>
    <span><span class="bk-cal-dot" style="background:var(--border);"></span> Faded / struck = cancelled</span>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
