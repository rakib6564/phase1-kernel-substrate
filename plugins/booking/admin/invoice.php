<?php
/**
 * Booking — printable invoice. Accessible to staff (?id=) or to the customer
 * via their self-service token (?token=). Renders a clean standalone page.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
BookingAPI::ensureSchema();

$tid = current_tenant_id();
$id    = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['token'] ?? '');

if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) {
    $appt = Database::row(
        "SELECT a.*, s.name AS service_name, s.currency, p.name AS provider_name
           FROM booking_appointments a
           JOIN booking_services  s ON s.id = a.service_id
           JOIN booking_providers p ON p.id = a.provider_id
          WHERE a.manage_token = ? AND a.tenant_id = ?",
        [$token, $tid]
    );
} else {
    Auth::require();
    Auth::requirePerm('booking.view');
    $appt = Database::row(
        "SELECT a.*, s.name AS service_name, s.currency, p.name AS provider_name
           FROM booking_appointments a
           JOIN booking_services  s ON s.id = a.service_id
           JOIN booking_providers p ON p.id = a.provider_id
          WHERE a.id = ? AND a.tenant_id = ?",
        [$id, $tid]
    );
}

if (!$appt) { http_response_code(404); echo 'Invoice not found.'; exit; }

// Assign an invoice number on first view.
$invoiceNo = (string)($appt['invoice_no'] ?? '');
if ($invoiceNo === '') {
    $invoiceNo = 'INV-' . date('Y', strtotime($appt['created_at'])) . '-' . str_pad((string)$appt['id'], 5, '0', STR_PAD_LEFT);
    try { Database::update('booking_appointments', ['invoice_no' => $invoiceNo], 'id = ?', [(int)$appt['id']]); }
    catch (\Throwable $e) { /* column guaranteed by migration */ }
}

$cur      = $appt['currency'] ?: 'USD';
$money    = fn($cents) => $cur . ' ' . number_format(((int)$cents) / 100, 2);
$addons   = json_decode((string)($appt['addons_json'] ?? '[]'), true) ?: [];
$party    = max(1, (int)($appt['party_size'] ?? 1));
$bizName  = Database::setting('business_name') ?: (Database::setting('site_name') ?: 'Slate');
$bizEmail = Database::setting('business_email') ?: '';
$bizAddr  = Database::setting('business_address') ?: '';

$subtotal = (int)$appt['price_cents'] + (int)$appt['discount_cents']; // taxable + discount = subtotal
$discount = (int)$appt['discount_cents'];
$tax      = (int)$appt['tax_cents'];
$total    = (int)$appt['price_cents'] + $tax;
$paid     = (int)$appt['paid_cents'] + (int)($appt['gift_applied_cents'] ?? 0);
$balance  = max(0, $total - $paid);
?>
<!DOCTYPE html><html lang="<?= e(I18n::currentLocale()) ?>"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice <?= e($invoiceNo) ?></title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color:#111217; background:#fff; margin:0; padding:32px; }
  .inv { max-width:720px; margin:0 auto; }
  .inv-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:28px; }
  .inv-head h1 { font-size:24px; margin:0 0 4px; }
  .muted { color:#6B7280; font-size:13px; }
  table { width:100%; border-collapse:collapse; margin:18px 0; font-size:14px; }
  th, td { text-align:left; padding:8px 10px; border-bottom:1px solid #eee; }
  td.num, th.num { text-align:right; }
  .totals { margin-left:auto; width:280px; }
  .totals td { border:none; padding:4px 10px; }
  .totals .grand { font-weight:700; font-size:16px; border-top:2px solid #111217; }
  .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; background:#EEF2FF; color:#3730A3; }
  @media print { .no-print { display:none; } body { padding:0; } }
  @media (max-width:480px){ .totals { width:100%; } table { font-size:13px; } th, td { padding:7px 6px; } }
  .btn { display:inline-block; padding:8px 14px; background:#2563EB; color:#fff; border-radius:8px; text-decoration:none; font-size:14px; border:0; cursor:pointer; }
</style>
</head><body>
<div class="inv">
  <div class="no-print" style="text-align:right;margin-bottom:16px;">
    <button class="btn" onclick="window.print()">Print / Save PDF</button>
  </div>
  <div class="inv-head">
    <div>
      <h1>Invoice</h1>
      <div class="muted"><?= e($invoiceNo) ?></div>
      <div class="muted"><?= e(date('j F Y', strtotime($appt['created_at']))) ?></div>
    </div>
    <div style="text-align:right;">
      <div style="font-weight:600;"><?= e($bizName) ?></div>
      <?php if ($bizEmail): ?><div class="muted"><?= e($bizEmail) ?></div><?php endif; ?>
      <?php if ($bizAddr): ?><div class="muted"><?= nl2br(e($bizAddr)) ?></div><?php endif; ?>
    </div>
  </div>

  <div style="display:flex;justify-content:space-between;gap:24px;margin-bottom:8px;">
    <div>
      <div class="muted">Billed to</div>
      <div style="font-weight:600;"><?= e($appt['customer_name']) ?></div>
      <div class="muted"><?= e($appt['customer_email']) ?></div>
    </div>
    <div style="text-align:right;">
      <div class="muted">Appointment</div>
      <div><?= e(date('l, j F Y, H:i', strtotime($appt['starts_at']))) ?></div>
      <div class="muted">Ref <?= e($appt['ref']) ?> · <span class="badge"><?= e(ucfirst((string)($appt['payment_status'] ?? 'none'))) ?></span></div>
    </div>
  </div>

  <table>
    <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead>
    <tbody>
      <tr>
        <td><?= e($appt['service_name']) ?> <span class="muted">with <?= e($appt['provider_name']) ?></span></td>
        <td class="num"><?= $party ?></td>
        <td class="num"><?= e($money((int)$appt['price_cents'] + $discount - array_sum(array_map(fn($a)=>(int)($a['price_cents'] ?? 0), $addons)))) ?></td>
      </tr>
      <?php foreach ($addons as $a): ?>
        <tr><td>+ <?= e($a['name'] ?? 'Add-on') ?></td><td class="num">1</td><td class="num"><?= e($money((int)($a['price_cents'] ?? 0))) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <table class="totals">
    <tr><td>Subtotal</td><td class="num"><?= e($money($subtotal)) ?></td></tr>
    <?php if ($discount > 0): ?><tr><td>Discount<?= !empty($appt['coupon_code']) ? ' (' . e($appt['coupon_code']) . ')' : '' ?></td><td class="num">- <?= e($money($discount)) ?></td></tr><?php endif; ?>
    <?php if ($tax > 0): ?><tr><td>Tax</td><td class="num"><?= e($money($tax)) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>Total</td><td class="num"><?= e($money($total)) ?></td></tr>
    <?php if ((int)($appt['gift_applied_cents'] ?? 0) > 0): ?><tr><td>Gift card</td><td class="num">- <?= e($money((int)$appt['gift_applied_cents'])) ?></td></tr><?php endif; ?>
    <?php if ((int)$appt['paid_cents'] > 0): ?><tr><td>Paid</td><td class="num">- <?= e($money((int)$appt['paid_cents'])) ?></td></tr><?php endif; ?>
    <tr><td>Balance due</td><td class="num"><?= e($money($balance)) ?></td></tr>
  </table>

  <p class="muted" style="margin-top:32px;">Thank you for your business.</p>
</div>
</body></html>
