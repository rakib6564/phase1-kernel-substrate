<?php
/**
 * Survey Pipeline — AJAX: add an internal note to an order.
 * POST order_id, note, _csrf
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
$note    = trim((string)($_POST['note'] ?? ''));

if ($orderId <= 0 || $note === '') {
    json_error('Order id and note are required.', 400);
}

$eventId = SurveyPipelineAPI::addNote($orderId, $note, (int)Auth::userId());

if ($eventId <= 0) {
    json_error('Order not found or note could not be saved.', 404);
}

json_response(['ok' => true, 'event_id' => $eventId]);
