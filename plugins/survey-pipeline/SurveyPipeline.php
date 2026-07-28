<?php
/**
 * Survey Pipeline — bootstrap.
 *
 * Hooks:
 *   - admin_nav_items          → sidebar entry
 *   - admin_dashboard_widgets  → stage-count card
 *   - forms_submitted          → ingest connected-form submissions into the pipeline
 *
 * Heavy logic lives in SurveyPipelineAPI (loaded here so other
 * plugins can call it without going through Forms).
 */

require_once __DIR__ . '/SurveyPipelineAPI.php';

class SurveyPipeline extends Plugin {

    public function boot(): void {
        Hook::addFilter('admin_nav_items',         [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget']);

        // forms_submitted fires after every successful submission:
        //   Hook::doAction('forms_submitted', $submissionId, $formId, $data)
        // $data is the decoded array of field_name => value from data_json.
        Hook::addAction('forms_submitted', [$this, 'onFormSubmitted'], 10, 3);
    }

    // ── Nav ───────────────────────────────────────────────────

    public function addAdminNav(array $items): array {
        if (!Auth::can('surveypipeline.view') && !Auth::isSuperAdmin()) return $items;

        $items[] = [
            'slug'  => 'survey-pipeline',
            'label' => __('surveypipeline_nav', 'Survey Pipeline'),
            'href'  => $this->url('admin/index.php'),
            'icon'  => 'activity',
            'perm'  => 'surveypipeline.view',
            'order' => 240,
            'group' => 'content',
        ];
        $items[] = [
            'slug'  => 'survey-pipeline-settings',
            'label' => __('surveypipeline_nav_settings', 'Pipeline Settings'),
            'href'  => $this->url('admin/settings.php'),
            'icon'  => 'git-merge',
            'perm'  => 'surveypipeline.admin',
            'order' => 241,
            'group' => 'content',
        ];
        return $items;
    }

    // ── Dashboard widget ──────────────────────────────────────

    public function addDashboardWidget(array $widgets): array {
        if (!Auth::can('surveypipeline.view')) return $widgets;

        try {
            $counts = SurveyPipelineAPI::stageCounts();
        } catch (\Throwable $e) {
            return $widgets;
        }

        $stages = SurveyPipelineAPI::STAGES;
        $active = 0;
        foreach (['new','quoted','scheduled','active'] as $s) {
            $active += (int)($counts[$s] ?? 0);
        }

        $url = $this->url('admin/index.php');
        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('surveypipeline_nav', 'Survey Pipeline') ?></h2>
                <a href="<?= e($url) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('surveypipeline_active', 'Active orders') ?></div>
                    <div class="dwidget-kpi-v"><?= (int)$active ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('surveypipeline_new', 'New') ?></div>
                    <div class="dwidget-kpi-v"><?= (int)($counts['new'] ?? 0) ?></div>
                </div>
            </div>
            <ul style="list-style:none;padding:12px 0 4px;margin:0;border-top:1px solid var(--faint);">
            <?php foreach ($stages as $key => $cfg):
                $n = (int)($counts[$key] ?? 0);
                if ($n === 0) continue; ?>
                <li style="display:flex;align-items:center;justify-content:space-between;padding:5px 16px;font-size:13px;">
                    <span style="display:flex;align-items:center;gap:7px;">
                        <span style="width:7px;height:7px;border-radius:50%;background:<?= e($cfg['hex']) ?>;flex-shrink:0;"></span>
                        <?= e($cfg['label']) ?>
                    </span>
                    <span style="font-weight:700;color:var(--text-1);"><?= $n ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ── Ingestion hook ────────────────────────────────────────

    /**
     * Called by Forms after every successful submission.
     * Only acts on forms that are connected to the pipeline.
     *
     * @param int   $submissionId  forms_submissions.id
     * @param int   $formId        forms_definitions.id
     * @param array $data          decoded field_name => value map from data_json
     */
    public function onFormSubmitted(int $submissionId, int $formId, array $data): void {
        try {
            SurveyPipelineAPI::ingestSubmission($submissionId, $formId, $data);
        } catch (\Throwable $e) {
            slate_log('SurveyPipeline: ingest failed for submission ' . $submissionId . ': ' . $e->getMessage(), 'error');
        }
    }
}
