<?php
/**
 * Survey Pipeline — AJAX: update editable order fields.
 * POST order_id, _csrf, plus any of:
 *   vessel_name, client_name, client_email, client_phone,
 *   survey_locale, loa_ft, quoted_amount, scheduled_at, notes, assigned_to
 */
$root = realpath(__DIR__ . '/../../../..');
require $root . '/config.php';
require_once dirname(__DIR__, 2) . '/SurveyPipelineAPI.php';

Auth::require();
Auth::requirePerm('surveypipeline.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}
if (!csrf_verify($_POST['_csrf'] ?? null)) {
    json_error('Security check failed.', 403);
}

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    json_error('Invalid order id.', 400);
}

$allowed = ['vessel_name','client_name','client_email','client_phone',
            'survey_locale','loa_ft','quoted_amount','scheduled_at',
            'notes','assigned_to'];

$fields = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $_POST)) {
        $fields[$key] = trim((string)$_POST[$key]);
    }
}

if (empty($fields)) {
    json_error('No fields to update.', 400);
}

$ok = SurveyPipelineAPI::updateOrder($orderId, $fields, (int)Auth::userId());

if (!$ok) {
    json_error('Order not found or update failed.', 404);
}

json_response(['ok' => true]);
