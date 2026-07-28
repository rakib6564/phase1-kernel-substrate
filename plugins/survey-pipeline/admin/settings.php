<?php
/**
 * Survey Pipeline — settings page.
 * Connect/disconnect forms, configure field mapping per connection.
 */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
require_once dirname(__DIR__) . '/SurveyPipelineAPI.php';

Auth::require();
Auth::requirePerm('surveypipeline.admin');

$pageTitle  = __('surveypipeline_nav_settings', 'Pipeline Settings');
$currentNav = 'survey-pipeline-settings';

$tid    = current_tenant_id();
$userId = (int)Auth::userId();
$flash  = null;

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'connect') {
            $formId    = (int)($_POST['form_id'] ?? 0);
            $formTitle = trim((string)($_POST['form_title'] ?? ''));
            $type      = trim((string)($_POST['survey_type'] ?? 'general'));
            $fieldMap  = [];
            foreach (array_keys(SurveyPipelineAPI::MAP_KEYS) as $k) {
                $v = trim((string)($_POST['map_' . $k] ?? ''));
                if ($v !== '') $fieldMap[$k] = $v;
            }
            if ($formId > 0 && $formTitle !== '') {
                SurveyPipelineAPI::connectForm($formId, $formTitle, $type, $fieldMap, $userId);
                $flash = ['type' => 'success', 'msg' => 'Form connected to pipeline.'];
            } else {
                $flash = ['type' => 'error', 'msg' => 'Select a form first.'];
            }
        } elseif ($action === 'update_map') {
            $formId   = (int)($_POST['form_id'] ?? 0);
            $type     = trim((string)($_POST['survey_type'] ?? 'general'));
            $fieldMap = [];
            foreach (array_keys(SurveyPipelineAPI::MAP_KEYS) as $k) {
                $v = trim((string)($_POST['map_' . $k] ?? ''));
                if ($v !== '') $fieldMap[$k] = $v;
            }
            $conn = Database::row(
                "SELECT form_title FROM surveypipeline_connections WHERE form_id = ? AND tenant_id = ?",
                [$formId, $tid]
            );
            if ($conn && $formId > 0) {
                SurveyPipelineAPI::connectForm($formId, $conn['form_title'], $type, $fieldMap, $userId);
                $flash = ['type' => 'success', 'msg' => 'Field mapping updated.'];
            }
        } elseif ($action === 'disconnect') {
            $formId = (int)($_POST['form_id'] ?? 0);
            if ($formId > 0) {
                SurveyPipelineAPI::disconnectForm($formId);
                $flash = ['type' => 'success', 'msg' => 'Form disconnected. Existing orders are kept.'];
            }
        } elseif ($action === 'save_general') {
            $prefix = trim((string)($_POST['order_ref_prefix'] ?? 'ORD'));
            $email  = trim((string)($_POST['admin_email'] ?? ''));
            $notify = isset($_POST['notify_on_new']) ? '1' : '0';
            Database::setSetting('survey-pipeline.order_ref_prefix', mb_substr($prefix, 0, 10) ?: 'ORD');
            Database::setSetting('survey-pipeline.admin_email', $email);
            Database::setSetting('survey-pipeline.notify_on_new', $notify);
            AuditLog::record('surveypipeline.settings_changed');
            $flash = ['type' => 'success', 'msg' => 'Settings saved.'];
        }
    }
}

$connectedForms  = SurveyPipelineAPI::connectedForms();
$connectedIds    = array_map(fn($c) => (int)$c['form_id'], $connectedForms);
$availableForms  = SurveyPipelineAPI::availableForms();
$unconnectedForms = array_filter($availableForms, fn($f) => !in_array((int)$f['id'], $connectedIds, true));

$genPrefix = Database::setting('survey-pipeline.order_ref_prefix') ?: 'ORD';
$genEmail  = Database::setting('survey-pipeline.admin_email') ?: '';
$genNotify = Database::setting('survey-pipeline.notify_on_new');
$genNotify = $genNotify === null ? true : (bool)(int)$genNotify;

require $root . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('surveypipeline_nav', 'Survey Pipeline'), 'href' => plugin_url('survey-pipeline', 'admin/index.php')],
    ['label' => $pageTitle],
]);
?>

<div class="page-header">
    <div>
        <h1><?= __('surveypipeline_nav_settings', 'Pipeline Settings') ?></h1>
        <p class="page-header-sub">Connect forms to the pipeline and map their fields.</p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<!-- ── Connected forms ── -->
<div class="card-header"><h2>Connected forms</h2><span class="text-muted text-sm"><?= count($connectedForms) ?></span></div>

<?php if (empty($connectedForms)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title">No forms connected</div>
            <p>Connect a form below to start tracking its submissions in the pipeline.</p>
        </div>
    </div>
<?php else: ?>
<div class="data-list" data-single-open>
    <?php foreach ($connectedForms as $cf):
        $map = json_decode((string)($cf['field_map'] ?? '{}'), true) ?: [];
        $formFields = SurveyPipelineAPI::formFields((int)$cf['form_id']);

        ob_start(); ?>
        <form method="post" style="margin:0;display:inline;"
              onsubmit="return confirm('Disconnect this form? Existing orders will be kept.');">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="disconnect">
            <input type="hidden" name="form_id" value="<?= (int)$cf['form_id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Disconnect</button>
        </form>
        <?php $rowActions = ob_get_clean();

        ob_start(); ?>
        <form method="post" class="sp-map-form">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="update_map">
            <input type="hidden" name="form_id" value="<?= (int)$cf['form_id'] ?>">

            <div class="field">
                <label class="field-label">Survey type</label>
                <select name="survey_type" class="sp-select">
                    <?php foreach (['sailboat'=>'Sailboat','powerboat'=>'Powerboat','general'=>'General'] as $tv=>$tl): ?>
                        <option value="<?= e($tv) ?>" <?= ($cf['survey_type']===$tv)?'selected':'' ?>><?= e($tl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field-row field-row-2">
            <?php foreach (SurveyPipelineAPI::MAP_KEYS as $key => $label): ?>
                <div class="field">
                    <label class="field-label"><?= e($label) ?></label>
                    <select name="map_<?= e($key) ?>" class="sp-select">
                        <option value="">— Not mapped —</option>
                        <?php foreach ($formFields as $ff): ?>
                            <option value="<?= e($ff['name']) ?>" <?= (($map[$key] ?? '')===$ff['name'])?'selected':'' ?>>
                                <?= e($ff['label']) ?> <span style="color:var(--subtle)">(<?= e($ff['name']) ?>)</span>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-sm btn-primary mt-2">Save mapping</button>
        </form>
        <?php $detailHtml = ob_get_clean();
        ?>
        <article class="data-row">
            <button type="button" class="data-row-summary" aria-expanded="false">
                <span class="data-row-avatar is-success" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($cf['form_title'],0,2))) ?></span>
                <span class="data-row-main">
                    <span class="data-row-title"><?= e($cf['form_title']) ?></span>
                    <span class="data-row-meta"><?= count($formFields) ?> fields · connected <?= e(date('M j, Y', strtotime($cf['connected_at']))) ?></span>
                </span>
                <span class="badge badge-active"><?= e(ucfirst($cf['survey_type'])) ?></span>
                <svg class="data-row-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="data-row-detail" hidden>
                <?= $detailHtml ?>
                <div class="data-row-actions" style="margin-top:12px;"><?= $rowActions ?></div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php slate_data_list_script(); ?>
<?php endif; ?>

<!-- ── Available (unconnected) forms ── -->
<div class="card-header" style="margin-top:28px;"><h2>Available forms</h2><span class="text-muted text-sm"><?= count($unconnectedForms) ?></span></div>

<?php if (empty($unconnectedForms)): ?>
    <div class="card">
        <p class="text-muted" style="padding:16px;">All published forms are connected, or none exist yet. Create a form in <a href="<?= e(plugin_url('forms','admin/index.php')) ?>">Forms</a> first.</p>
    </div>
<?php else: ?>
<div class="data-list" data-single-open>
    <?php foreach ($unconnectedForms as $f):
        $formFields = SurveyPipelineAPI::formFields((int)$f['id']);
        ob_start(); ?>
        <form method="post" class="sp-map-form">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="connect">
            <input type="hidden" name="form_id" value="<?= (int)$f['id'] ?>">
            <input type="hidden" name="form_title" value="<?= e($f['title']) ?>">

            <div class="field">
                <label class="field-label">Survey type</label>
                <select name="survey_type" class="sp-select">
                    <option value="sailboat">Sailboat</option>
                    <option value="powerboat">Powerboat</option>
                    <option value="general" selected>General</option>
                </select>
            </div>

            <p class="field-hint" style="margin:8px 0 12px;">Map fields now, or connect first and map later.</p>
            <div class="field-row field-row-2">
            <?php foreach (SurveyPipelineAPI::MAP_KEYS as $key => $label): ?>
                <div class="field">
                    <label class="field-label"><?= e($label) ?></label>
                    <select name="map_<?= e($key) ?>" class="sp-select">
                        <option value="">— Not mapped —</option>
                        <?php foreach ($formFields as $ff): ?>
                            <option value="<?= e($ff['name']) ?>"><?= e($ff['label']) ?> <span style="color:var(--subtle)">(<?= e($ff['name']) ?>)</span></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-sm btn-primary mt-2">Connect form</button>
        </form>
        <?php $detailHtml = ob_get_clean(); ?>
        <article class="data-row">
            <button type="button" class="data-row-summary" aria-expanded="false">
                <span class="data-row-avatar is-muted" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($f['title'],0,2))) ?></span>
                <span class="data-row-main">
                    <span class="data-row-title"><?= e($f['title']) ?></span>
                    <span class="data-row-meta"><?= (int)$f['field_count'] ?> fields · <?= e($f['slug']) ?></span>
                </span>
                <svg class="data-row-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="data-row-detail" hidden><?= $detailHtml ?></div>
        </article>
    <?php endforeach; ?>
</div>
<?php slate_data_list_script(); ?>
<?php endif; ?>

<!-- ── General settings ── -->
<div class="card-header" style="margin-top:28px;"><h2>General settings</h2></div>
<div class="card">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_general">

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="order_ref_prefix">Order reference prefix</label>
                <input type="text" id="order_ref_prefix" name="order_ref_prefix" maxlength="10"
                       value="<?= e($genPrefix) ?>" placeholder="ORD">
                <p class="field-hint">Orders are numbered e.g. <?= e($genPrefix) ?>-<?= date('Y') ?>-0001</p>
            </div>
            <div class="field">
                <label class="field-label" for="admin_email">Notification email</label>
                <input type="text" id="admin_email" name="admin_email"
                       value="<?= e($genEmail) ?>" placeholder="surveyor@cmsurveyors.com">
                <p class="field-hint">Receives an email for every new order. Leave blank to use site default.</p>
            </div>
        </div>

        <div class="field" style="margin-top:8px;">
            <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
                <input type="checkbox" name="notify_on_new" <?= $genNotify ? 'checked' : '' ?>>
                Email me when a new order is ingested
            </label>
        </div>

        <button type="submit" class="btn btn-primary mt-3">Save settings</button>
    </form>
</div>

<style>
.sp-select{width:100%;font-family:inherit;font-size:13px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text-1);}
.sp-select:focus{outline:2px solid var(--accent);outline-offset:1px;}
.sp-map-form{padding:4px 2px 8px;}
</style>

<?php require $root . '/admin/partials/footer.php'; ?>
