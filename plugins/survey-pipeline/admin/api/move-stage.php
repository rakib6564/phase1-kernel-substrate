<?php
/**
 * Survey Pipeline — AJAX: move an order to a new stage.
 * POST order_id, stage, _csrf
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
$stage   = trim((string)($_POST['stage'] ?? ''));

if ($orderId <= 0 || !in_array($stage, SurveyPipelineAPI::VALID_STAGES, true)) {
    json_error('Invalid order or stage.', 400);
}

$ok = SurveyPipelineAPI::moveStage($orderId, $stage, (int)Auth::userId());

if (!$ok) {
    json_error('Order not found or stage unchanged.', 404);
}

json_response(['ok' => true]);
