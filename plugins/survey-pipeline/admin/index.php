<?php
/**
 * Survey Pipeline — pipeline board.
 * Tabbed view of orders by stage + slide-in detail drawer.
 */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
require_once dirname(__DIR__) . '/SurveyPipelineAPI.php';

Auth::require();
Auth::requirePerm('surveypipeline.view');

$pageTitle  = __('surveypipeline_nav', 'Survey Pipeline');
$currentNav = 'survey-pipeline';

$tid      = current_tenant_id();
$canManage = Auth::can('surveypipeline.manage') || Auth::isSuperAdmin();

// Active tab
$stage  = trim((string)($_GET['stage'] ?? ''));
if ($stage && !in_array($stage, SurveyPipelineAPI::VALID_STAGES, true)) $stage = '';
$formId = (int)($_GET['form_id'] ?? 0);
$page   = max(1, (int)($_GET['page'] ?? 1));

$result = SurveyPipelineAPI::listOrders([
    'stage'   => $stage ?: null,
    'form_id' => $formId ?: null,
    'page'    => $page,
    'limit'   => 25,
]);
$orders     = $result['orders'];
$total      = $result['total'];
$totalPages = (int)ceil($total / 25);
$counts     = SurveyPipelineAPI::stageCounts();
$stages     = SurveyPipelineAPI::STAGES;

// Connected forms for filter dropdown
$connectedForms = SurveyPipelineAPI::connectedForms();

require $root . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => $pageTitle],
]);
?>

<div class="page-header">
    <div>
        <h1><?= __('surveypipeline_nav', 'Survey Pipeline') ?></h1>
        <p class="page-header-sub">
            <?php
            $activeCount = array_sum(array_map(fn($s) => (int)($counts[$s] ?? 0), ['new','quoted','scheduled','active']));
            ?>
            <?= (int)$activeCount ?> active order<?= $activeCount !== 1 ? 's' : '' ?>
        </p>
    </div>
    <?php if ($canManage && count($connectedForms) === 0): ?>
        <a href="<?= e(plugin_url('survey-pipeline', 'admin/settings.php')) ?>" class="btn btn-primary">
            Connect a form →
        </a>
    <?php endif; ?>
</div>

<?php if (count($connectedForms) === 0): ?>
<div class="card">
    <div class="empty">
        <div class="empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="32" height="32">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
        </div>
        <div class="empty-title"><?= __('surveypipeline_no_forms', 'No forms connected yet') ?></div>
        <p><?= __('surveypipeline_no_forms_hint', 'Go to Pipeline Settings to connect your survey order forms.') ?></p>
        <?php if ($canManage): ?>
            <a href="<?= e(plugin_url('survey-pipeline', 'admin/settings.php')) ?>" class="btn btn-primary mt-2">
                Pipeline Settings →
            </a>
        <?php endif; ?>
    </div>
</div>
<?php require $root . '/admin/partials/footer.php'; return; endif; ?>

<!-- Stage tabs -->
<div class="sp-tabs" role="tablist">
    <?php
    $tabDefs = array_merge(['all' => ['label' => 'All', 'badge' => 'muted', 'hex' => '#6B7280']], $stages);
    foreach ($tabDefs as $key => $cfg):
        $href     = plugin_url('survey-pipeline', 'admin/index.php') . '?' . http_build_query(array_filter(['stage' => $key === 'all' ? '' : $key, 'form_id' => $formId]));
        $isActive = ($key === 'all' && $stage === '') || $key === $stage;
        $count    = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0);
    ?>
        <a href="<?= e($href) ?>"
           class="sp-tab<?= $isActive ? ' is-active' : '' ?>"
           role="tab"
           aria-selected="<?= $isActive ? 'true' : 'false' ?>">
            <?= e($cfg['label']) ?>
            <span class="sp-tab-n"><?= (int)$count ?></span>
        </a>
    <?php endforeach; ?>

    <?php if (count($connectedForms) > 1): ?>
    <div class="sp-tab-filter">
        <select onchange="location.href=this.value" style="font-size:12px;border:1px solid var(--border);border-radius:6px;padding:4px 8px;background:var(--surface);color:var(--text-1);">
            <option value="<?= e(plugin_url('survey-pipeline','admin/index.php') . '?' . http_build_query(array_filter(['stage' => $stage]))) ?>">
                All forms
            </option>
            <?php foreach ($connectedForms as $cf): ?>
            <option value="<?= e(plugin_url('survey-pipeline','admin/index.php') . '?' . http_build_query(array_filter(['stage' => $stage, 'form_id' => $cf['form_id']]))) ?>"
                    <?= $formId === (int)$cf['form_id'] ? 'selected' : '' ?>>
                <?= e($cf['form_title']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
</div>

<!-- Orders table -->
<div class="card" style="overflow:hidden;">
    <?php if (empty($orders)): ?>
        <div class="empty" style="padding:40px;">
            <div class="empty-title"><?= __('surveypipeline_no_orders', 'No orders in this stage') ?></div>
            <p><?= __('surveypipeline_no_orders_hint', 'Orders appear here when clients submit connected forms.') ?></p>
        </div>
    <?php else: ?>
    <div class="sp-table-wrap">
        <table class="sp-table">
            <thead>
                <tr>
                    <th><?= __('order', 'Order') ?></th>
                    <th><?= __('vessel_client', 'Vessel / Client') ?></th>
                    <th><?= __('form', 'Form') ?></th>
                    <th><?= __('loa', 'LOA') ?></th>
                    <th><?= __('scheduled', 'Scheduled') ?></th>
                    <th><?= __('status', 'Status') ?></th>
                    <th><?= __('amount', 'Amount') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o):
                $stageCfg = $stages[$o['stage']] ?? ['label' => $o['stage'], 'badge' => 'muted'];
            ?>
                <tr class="sp-row" data-order-id="<?= (int)$o['id'] ?>" tabindex="0" role="button" aria-label="Open order <?= e($o['order_ref']) ?>">
                    <td>
                        <span class="sp-ref"><?= e($o['order_ref']) ?></span>
                    </td>
                    <td>
                        <span class="sp-vessel"><?= e($o['vessel_name'] ?: '—') ?></span>
                        <span class="sp-client"><?= e($o['client_name'] ?: $o['client_email'] ?: '') ?></span>
                    </td>
                    <td>
                        <span class="sp-form-label"><?= e($o['form_title'] ?? '') ?></span>
                    </td>
                    <td><?= e($o['loa_ft'] ?: '—') ?></td>
                    <td>
                        <?= $o['scheduled_at']
                            ? e(date('M j, Y', strtotime($o['scheduled_at'])))
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= e($stageCfg['badge']) ?>">
                            <?= e($stageCfg['label']) ?>
                        </span>
                    </td>
                    <td>
                        <?= $o['quoted_amount']
                            ? '<strong>$' . e(number_format((float)$o['quoted_amount'], 2)) . '</strong>'
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td>
                        <button class="btn btn-sm sp-open-btn"
                                data-order-id="<?= (int)$o['id'] ?>"
                                onclick="event.stopPropagation();spOpenDrawer(<?= (int)$o['id'] ?>)">
                            View →
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div style="margin-top:16px;">
    <?php slate_pagination($page, $totalPages, array_filter(['stage' => $stage, 'form_id' => $formId ?: null])); ?>
</div>
<?php endif; ?>

<!-- ── Order detail drawer ── -->
<div class="sp-overlay" id="spOverlay" hidden></div>
<aside class="sp-drawer" id="spDrawer" hidden aria-label="Order detail" role="dialog" aria-modal="true">
    <div class="sp-drawer-head">
        <button class="btn btn-sm" id="spDrawerClose" aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <span class="sp-drawer-title" id="spDrawerTitle">Order</span>
        <span class="sp-drawer-badge" id="spDrawerBadge"></span>
    </div>
    <div class="sp-drawer-body" id="spDrawerBody">
        <div class="sp-drawer-loading">Loading…</div>
    </div>
</aside>

<style>
/* ── Tabs ── */
.sp-tabs{display:flex;align-items:center;gap:4px;margin-bottom:12px;flex-wrap:wrap;}
.sp-tab{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;font-size:13px;font-weight:600;color:var(--text-2);text-decoration:none;transition:all .14s ease;border:1px solid transparent;}
.sp-tab:hover{background:var(--surface-2);color:var(--text-1);}
.sp-tab.is-active{background:var(--surface);border-color:var(--border);color:var(--text-1);box-shadow:0 1px 3px rgba(0,0,0,.06);}
.sp-tab-n{font-size:11px;font-weight:700;padding:1px 6px;border-radius:99px;background:var(--surface-2);color:var(--text-2);}
.sp-tab.is-active .sp-tab-n{background:var(--accent-pale);color:var(--accent);}
.sp-tab-filter{margin-left:auto;}

/* ── Table ── */
.sp-table-wrap{overflow-x:auto;}
.sp-table{width:100%;border-collapse:collapse;font-size:13px;}
.sp-table th{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--subtle);padding:10px 16px;text-align:left;border-bottom:1px solid var(--faint);background:var(--surface-2);white-space:nowrap;}
.sp-table td{padding:13px 16px;border-bottom:1px solid var(--faint);vertical-align:middle;}
.sp-table tbody tr:last-child td{border-bottom:none;}
.sp-row{cursor:pointer;transition:background .1s ease;}
.sp-row:hover{background:var(--surface-2);}
.sp-row:focus{outline:2px solid var(--accent);outline-offset:-2px;}
.sp-ref{font-size:12px;font-weight:700;color:var(--text-1);font-variant-numeric:tabular-nums;}
.sp-vessel{display:block;font-weight:600;color:var(--text-1);margin-bottom:2px;}
.sp-client{display:block;font-size:11.5px;color:var(--muted);}
.sp-form-label{font-size:12px;color:var(--muted);}

/* ── Drawer ── */
.sp-overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:200;backdrop-filter:blur(2px);}
.sp-drawer{position:fixed;top:0;right:0;bottom:0;width:460px;max-width:100vw;background:var(--surface);border-left:1px solid var(--border);z-index:201;display:flex;flex-direction:column;box-shadow:-12px 0 40px -12px rgba(0,0,0,.2);}
.sp-drawer-head{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid var(--faint);flex-shrink:0;}
.sp-drawer-title{font-weight:700;font-size:14px;flex:1;}
.sp-drawer-badge{}
.sp-drawer-body{flex:1;overflow-y:auto;padding:20px;}
.sp-drawer-loading{color:var(--muted);font-size:13px;padding:20px 0;}

/* ── Drawer internals ── */
.sp-section-label{font-size:10.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--subtle);margin:20px 0 10px;display:flex;align-items:center;gap:8px;}
.sp-section-label::after{content:"";flex:1;height:1px;background:var(--faint);}
.sp-voyage{display:flex;align-items:flex-start;gap:0;margin-bottom:4px;overflow-x:auto;padding-bottom:4px;}
.sp-vleg{display:flex;flex-direction:column;align-items:center;gap:6px;flex:1;position:relative;min-width:64px;}
.sp-vline{position:absolute;top:10px;left:50%;width:100%;height:2px;background:var(--faint);}
.sp-vline.done{background:var(--accent);}
.sp-vleg:last-child .sp-vline{display:none;}
.sp-vnode{width:20px;height:20px;border-radius:50%;border:2px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:var(--subtle);position:relative;z-index:1;flex-shrink:0;}
.sp-vnode.done{background:var(--text-1);border-color:var(--text-1);color:#fff;}
.sp-vnode.current{border-color:var(--accent);color:var(--accent);box-shadow:0 0 0 3px var(--accent-pale);}
.sp-vlabel{font-size:10px;font-weight:600;color:var(--subtle);text-align:center;line-height:1.2;}
.sp-vlabel.done{color:var(--text-2);}
.sp-vlabel.current{color:var(--accent);}

.sp-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px;margin-bottom:4px;}
.sp-field label{display:block;font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--subtle);margin-bottom:4px;}
.sp-field .sp-val{font-size:13.5px;font-weight:600;color:var(--text-1);}
.sp-field .sp-val.empty{color:var(--muted);font-weight:400;font-style:italic;}

.sp-actions-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:4px;}
.sp-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:8px;border:1px solid var(--border);font-size:12px;font-weight:600;color:var(--text-2);cursor:pointer;background:var(--surface-2);transition:all .14s ease;text-decoration:none;}
.sp-chip:hover{border-color:var(--accent);color:var(--accent);}
.sp-chip.primary{background:var(--text-1);border-color:var(--text-1);color:#fff;}
.sp-chip.primary:hover{opacity:.85;}

.sp-stage-select{font-family:inherit;font-size:13px;font-weight:600;padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text-1);cursor:pointer;width:100%;}
.sp-stage-select:focus{outline:2px solid var(--accent);outline-offset:2px;}

.sp-note-form{display:flex;gap:8px;margin-top:8px;}
.sp-note-form textarea{flex:1;font-family:inherit;font-size:13px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;resize:vertical;min-height:64px;background:var(--surface);}
.sp-note-form textarea:focus{outline:2px solid var(--accent);}

.sp-timeline{border-left:2px solid var(--faint);padding-left:14px;margin-top:4px;}
.sp-tl-item{position:relative;margin-bottom:12px;}
.sp-tl-item::before{content:"";position:absolute;left:-19px;top:4px;width:8px;height:8px;border-radius:50%;background:var(--border);border:2px solid var(--surface);}
.sp-tl-item.ev-stage_changed::before{background:var(--accent);}
.sp-tl-item.ev-note_added::before{background:#F59E0B;}
.sp-tl-item.ev-order_created::before{background:var(--text-1);}
.sp-tl-item.ev-quote_sent::before{background:#10B981;}
.sp-tl-item.ev-scheduled::before{background:#8B5CF6;}
.sp-tl-ev{font-size:12.5px;font-weight:600;color:var(--text-1);}
.sp-tl-note{font-size:12px;color:var(--muted);margin-top:2px;}
.sp-tl-ts{font-size:11px;color:var(--subtle);margin-top:2px;}

@media(max-width:600px){.sp-drawer{width:100%;}}
</style>

<script>
(function () {
    var overlay  = document.getElementById('spOverlay');
    var drawer   = document.getElementById('spDrawer');
    var closeBtn = document.getElementById('spDrawerClose');
    var body     = document.getElementById('spDrawerBody');
    var title    = document.getElementById('spDrawerTitle');
    var badge    = document.getElementById('spDrawerBadge');

    function open(orderId) {
        overlay.hidden = false;
        drawer.hidden  = false;
        body.innerHTML = '<div class="sp-drawer-loading">Loading…</div>';
        title.textContent = 'Order';
        badge.innerHTML = '';

        fetch(<?= json_encode(plugin_url('survey-pipeline','admin/api/order.php')) ?> + '?id=' + orderId, {
            credentials: 'same-origin'
        })
        .then(function(r){ return r.json(); })
        .then(function(resp) {
            if (!resp.ok) { body.innerHTML = '<p class="text-danger">' + resp.error + '</p>'; return; }
            renderDrawer(resp.order);
        })
        .catch(function(e){ body.innerHTML = '<p class="text-danger">Failed to load order.</p>'; });
    }

    function close() {
        overlay.hidden = true;
        drawer.hidden  = true;
    }

    function renderDrawer(o) {
        var stages = <?= json_encode(SurveyPipelineAPI::STAGES) ?>;
        var stageKeys = Object.keys(stages);
        var currentIdx = stageKeys.indexOf(o.stage);
        var canManage = <?= $canManage ? 'true' : 'false' ?>;
        var csrfToken = <?= json_encode(csrf_token()) ?>;

        title.textContent = o.order_ref;

        var stageCfg = stages[o.stage] || {label: o.stage, badge: 'muted'};
        badge.innerHTML = '<span class="badge badge-' + stageCfg.badge + '">' + stageCfg.label + '</span>';

        // Voyage progress (skip cancelled from progress rail)
        var progressKeys = ['new','quoted','scheduled','active','delivered'];
        var voyage = '<div class="sp-voyage">' + progressKeys.map(function(key, i){
            var ci = progressKeys.indexOf(o.stage);
            var done    = i < ci;
            var current = key === o.stage;
            var nodeClass = done ? 'done' : current ? 'current' : '';
            var labelClass = nodeClass;
            var inner = done ? '<svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" width="10" height="10"><path d="M4 12.5L9.5 18L20 6"/></svg>' : (i+1);
            return '<div class="sp-vleg">'
                 + '<div class="sp-vline' + (done ? ' done' : '') + '"></div>'
                 + '<div class="sp-vnode ' + nodeClass + '">' + inner + '</div>'
                 + '<div class="sp-vlabel ' + labelClass + '">' + stages[key].label + '</div>'
                 + '</div>';
        }).join('') + '</div>';

        // Stage mover
        var stageSelect = '';
        if (canManage) {
            stageSelect = '<div class="sp-section-label">Change stage</div>'
                + '<select class="sp-stage-select" id="spStageSelect" data-order-id="' + o.id + '">'
                + stageKeys.map(function(k){
                    return '<option value="' + k + '"' + (k===o.stage?' selected':'') + '>' + stages[k].label + '</option>';
                }).join('') + '</select>';
        }

        // Field helper
        function field(label, val, span) {
            var v = val ? ('<span class="sp-val">' + esc(val) + '</span>') : '<span class="sp-val empty">—</span>';
            return '<div class="sp-field' + (span ? ' sp-field-full' : '') + '"><label>' + label + '</label>' + v + '</div>';
        }

        // Note form
        var noteForm = canManage
            ? '<div class="sp-section-label">Add note</div>'
              + '<div class="sp-note-form">'
              + '<textarea id="spNoteText" placeholder="Internal note…"></textarea>'
              + '<button class="btn btn-primary btn-sm" id="spNoteSubmit" data-order-id="' + o.id + '">Save</button>'
              + '</div>'
            : '';

        // Timeline
        var timeline = '<div class="sp-section-label">Timeline</div><div class="sp-timeline">';
        (o.events || []).forEach(function(ev){
            var label = ev.event_type.replace(/_/g,' ');
            if (ev.event_type === 'stage_changed' && ev.from_stage && ev.to_stage) {
                label = 'Moved: ' + (stages[ev.from_stage]||{label:ev.from_stage}).label + ' → ' + (stages[ev.to_stage]||{label:ev.to_stage}).label;
            }
            var ts = ev.created_at ? new Date(ev.created_at).toLocaleString() : '';
            timeline += '<div class="sp-tl-item ev-' + ev.event_type + '">'
                      + '<div class="sp-tl-ev">' + esc(label) + '</div>'
                      + (ev.note ? '<div class="sp-tl-note">' + esc(ev.note) + '</div>' : '')
                      + '<div class="sp-tl-ts">' + esc(ts) + (ev.actor_name ? ' · ' + esc(ev.actor_name) : '') + '</div>'
                      + '</div>';
        });
        timeline += '</div>';

        // Submission link
        var subLink = o.submission_id
            ? '<div class="sp-section-label">Source</div>'
              + '<a class="sp-chip" href="' + esc(<?= json_encode(plugin_url('forms', 'admin/submission.php')) ?> + '?id=' + o.submission_id) + '" target="_blank">'
              + 'View raw submission →</a>'
            : '';

        body.innerHTML =
            '<div class="sp-section-label">Voyage progress</div>'
            + voyage
            + (stageSelect ? stageSelect : '')
            + '<div class="sp-section-label">Actions</div>'
            + '<div class="sp-actions-row">'
            + '<a class="sp-chip" href="mailto:' + esc(o.client_email||'') + '">✉ Email client</a>'
            + '</div>'
            + '<div class="sp-section-label">Vessel &amp; order</div>'
            + '<div class="sp-grid">'
            + field('Vessel', o.vessel_name)
            + field('LOA', o.loa_ft)
            + field('Survey type', o.survey_type)
            + field('Amount', o.quoted_amount ? '$' + parseFloat(o.quoted_amount).toFixed(2) : null)
            + '</div>'
            + '<div class="sp-section-label">Client &amp; location</div>'
            + '<div class="sp-grid">'
            + field('Client', o.client_name)
            + field('Phone', o.client_phone)
            + field('Email', o.client_email)
            + field('Scheduled', o.scheduled_at)
            + '</div>'
            + field('Survey locale', o.survey_locale)
            + field('Form', o.form_title)
            + noteForm
            + timeline
            + subLink;

        // Stage change
        var sel = document.getElementById('spStageSelect');
        if (sel) {
            sel.addEventListener('change', function() {
                var orderId = this.dataset.orderId;
                var stage   = this.value;
                fetch(<?= json_encode(plugin_url('survey-pipeline','admin/api/move-stage.php')) ?>, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: '_csrf=' + encodeURIComponent(csrfToken) + '&order_id=' + orderId + '&stage=' + encodeURIComponent(stage)
                })
                .then(function(r){return r.json();})
                .then(function(resp){
                    if (resp.ok) { open(orderId); }
                    else { alert(resp.error || 'Failed to update stage.'); }
                });
            });
        }

        // Note submit
        var noteBtn = document.getElementById('spNoteSubmit');
        if (noteBtn) {
            noteBtn.addEventListener('click', function() {
                var orderId = this.dataset.orderId;
                var note = document.getElementById('spNoteText').value.trim();
                if (!note) return;
                fetch(<?= json_encode(plugin_url('survey-pipeline','admin/api/add-note.php')) ?>, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: '_csrf=' + encodeURIComponent(csrfToken) + '&order_id=' + orderId + '&note=' + encodeURIComponent(note)
                })
                .then(function(r){return r.json();})
                .then(function(resp){
                    if (resp.ok) { open(orderId); }
                    else { alert(resp.error || 'Failed to add note.'); }
                });
            });
        }
    }

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Wire row clicks
    document.querySelectorAll('.sp-row').forEach(function(row){
        row.addEventListener('click', function(){ open(parseInt(row.dataset.orderId)); });
        row.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(parseInt(row.dataset.orderId)); }
        });
    });

    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', close);
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });

    // Global for open buttons
    window.spOpenDrawer = open;
}());
</script>

<?php require $root . '/admin/partials/footer.php'; ?>
