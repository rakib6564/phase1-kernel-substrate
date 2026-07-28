<?php
/**
 * Survey Pipeline — AJAX: get a single order + its event timeline.
 * GET ?id=123
 */
$root = realpath(__DIR__ . '/../../../..');
require $root . '/config.php';
require_once dirname(__DIR__, 2) . '/SurveyPipelineAPI.php';

Auth::require();
Auth::requirePerm('surveypipeline.view');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    json_error('Invalid order id.', 400);
}

$order = SurveyPipelineAPI::getOrder($id);
if (!$order) {
    json_error('Order not found.', 404);
}

json_response(['ok' => true, 'order' => $order]);
