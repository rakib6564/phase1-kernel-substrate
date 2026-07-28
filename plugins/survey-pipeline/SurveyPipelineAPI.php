<?php
/**
 * Survey Pipeline — public API.
 *
 * Static methods. Other plugins (and future automation) call these
 * instead of touching the tables directly.
 *
 * Usage:
 *   SurveyPipelineAPI::stageCounts()
 *   SurveyPipelineAPI::ordersByStage('new')
 *   SurveyPipelineAPI::getOrder($id)
 *   SurveyPipelineAPI::moveStage($orderId, 'scheduled', Auth::userId())
 *   SurveyPipelineAPI::addNote($orderId, 'Checked in at marina', Auth::userId())
 */

class SurveyPipelineAPI {

    // ── Stage definitions ─────────────────────────────────────

    const STAGES = [
        'new'       => ['label' => 'New',         'badge' => 'info',    'hex' => '#3B82F6'],
        'quoted'    => ['label' => 'Quoted',       'badge' => 'warning', 'hex' => '#F59E0B'],
        'scheduled' => ['label' => 'Scheduled',    'badge' => 'accent',  'hex' => '#8B5CF6'],
        'active'    => ['label' => 'In Progress',  'badge' => 'success', 'hex' => '#10B981'],
        'delivered' => ['label' => 'Delivered',    'badge' => 'muted',   'hex' => '#B68A4E'],
        'cancelled' => ['label' => 'Cancelled',    'badge' => 'danger',  'hex' => '#EF4444'],
    ];

    const VALID_STAGES = ['new','quoted','scheduled','active','delivered','cancelled'];

    // ── Field mapping keys ────────────────────────────────────

    /** Keys the settings page lets admins map to form field names. */
    const MAP_KEYS = [
        'vessel_name'   => 'Vessel / Boat name',
        'client_name'   => 'Client name',
        'client_email'  => 'Client email',
        'client_phone'  => 'Client phone',
        'survey_locale' => 'Survey location / locale',
        'loa_ft'        => 'LOA / Length (ft)',
    ];

    // ── Connections ───────────────────────────────────────────

    /**
     * All forms connected to the pipeline for this tenant.
     * Returns array of surveypipeline_connections rows.
     */
    public static function connectedForms(): array {
        return Database::rows(
            "SELECT * FROM surveypipeline_connections WHERE tenant_id = ? ORDER BY form_title ASC",
            [current_tenant_id()]
        );
    }

    /**
     * Check whether a form_id is connected.
     */
    public static function isConnected(int $formId): bool {
        return (bool) Database::value(
            "SELECT id FROM surveypipeline_connections WHERE tenant_id = ? AND form_id = ?",
            [current_tenant_id(), $formId]
        );
    }

    /**
     * Connect a form to the pipeline.
     * $formId    — forms_definitions.id
     * $formTitle — cached display name
     * $type      — 'sailboat' | 'powerboat' | 'general'
     * $fieldMap  — ['vessel_name'=>'field_name', ...] mapping key → form field name
     * $actorId   — admin user doing the connecting
     */
    public static function connectForm(int $formId, string $formTitle, string $type, array $fieldMap, int $actorId): void {
        $tid = current_tenant_id();
        Database::query(
            "INSERT INTO surveypipeline_connections
                (tenant_id, form_id, form_title, survey_type, field_map, connected_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                form_title  = VALUES(form_title),
                survey_type = VALUES(survey_type),
                field_map   = VALUES(field_map),
                connected_by = VALUES(connected_by)",
            [$tid, $formId, mb_substr($formTitle, 0, 200), mb_substr($type, 0, 80),
             json_encode($fieldMap), $actorId]
        );
        AuditLog::record('surveypipeline.form_connected', (string)$formId, ['title' => $formTitle]);
    }

    /**
     * Disconnect a form. Existing orders are kept; new submissions
     * from that form will no longer be ingested.
     */
    public static function disconnectForm(int $formId): void {
        Database::delete(
            'surveypipeline_connections',
            'form_id = ? AND tenant_id = ?',
            [$formId, current_tenant_id()]
        );
        AuditLog::record('surveypipeline.form_disconnected', (string)$formId);
    }

    // ── Ingestion ─────────────────────────────────────────────

    /**
     * Called by the forms_submitted hook. Creates a pipeline order if
     * the form is connected. Silently no-ops if not connected.
     *
     * @param int   $submissionId  forms_submissions.id
     * @param int   $formId        forms_definitions.id
     * @param array $data          decoded field_name => value from data_json
     */
    public static function ingestSubmission(int $submissionId, int $formId, array $data): void {
        $tid = current_tenant_id();

        $conn = Database::row(
            "SELECT * FROM surveypipeline_connections WHERE tenant_id = ? AND form_id = ?",
            [$tid, $formId]
        );
        if (!$conn) return; // form not connected — do nothing

        // Prevent duplicates (in case hook fires twice)
        $existing = Database::value(
            "SELECT id FROM surveypipeline_orders WHERE tenant_id = ? AND submission_id = ?",
            [$tid, $submissionId]
        );
        if ($existing) return;

        // Extract field snapshots using the saved field map
        $map = json_decode((string)($conn['field_map'] ?? '{}'), true) ?: [];
        $get = static function (string $key) use ($data, $map): ?string {
            $fieldName = $map[$key] ?? '';
            if ($fieldName === '') return null;
            $val = trim((string)($data[$fieldName] ?? ''));
            return $val !== '' ? $val : null;
        };

        $ref   = self::generateRef($tid);
        $stage = Database::setting('survey-pipeline.default_stage') ?: 'new';
        if (!in_array($stage, self::VALID_STAGES, true)) $stage = 'new';

        $orderId = Database::insert('surveypipeline_orders', [
            'tenant_id'     => $tid,
            'submission_id' => $submissionId,
            'form_id'       => $formId,
            'order_ref'     => $ref,
            'stage'         => $stage,
            'survey_type'   => (string)($conn['survey_type'] ?? 'general'),
            'vessel_name'   => $get('vessel_name'),
            'client_name'   => $get('client_name'),
            'client_email'  => $get('client_email'),
            'client_phone'  => $get('client_phone'),
            'survey_locale' => $get('survey_locale'),
            'loa_ft'        => $get('loa_ft'),
        ]);

        self::recordEvent($orderId, $tid, 'order_created', null, $stage, 'Order created from form submission', 0, 'System');

        // Admin notification email
        self::notifyAdmin($orderId, $conn, $get);

        slate_log("SurveyPipeline: ingested submission $submissionId as $ref (order $orderId)", 'info');
    }

    // ── Orders ────────────────────────────────────────────────

    /**
     * Paginated list of orders, optionally filtered by stage and/or form_id.
     * Returns ['orders' => [...], 'total' => int].
     */
    public static function listOrders(array $opts = []): array {
        $tid    = current_tenant_id();
        $stage  = $opts['stage']   ?? null;
        $formId = $opts['form_id'] ?? null;
        $page   = max(1, (int)($opts['page'] ?? 1));
        $limit  = min(100, max(1, (int)($opts['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;

        $where  = ['o.tenant_id = ?'];
        $params = [$tid];

        if ($stage && in_array($stage, self::VALID_STAGES, true)) {
            $where[]  = 'o.stage = ?';
            $params[] = $stage;
        }
        if ($formId > 0) {
            $where[]  = 'o.form_id = ?';
            $params[] = (int)$formId;
        }

        $whereStr = implode(' AND ', $where);

        $total = (int)Database::value(
            "SELECT COUNT(*) FROM surveypipeline_orders o WHERE $whereStr",
            $params
        );

        $rows = Database::rows(
            "SELECT o.*, c.form_title, c.survey_type AS conn_type
               FROM surveypipeline_orders o
               LEFT JOIN surveypipeline_connections c
                      ON c.form_id = o.form_id AND c.tenant_id = o.tenant_id
              WHERE $whereStr
              ORDER BY o.created_at DESC
              LIMIT $limit OFFSET $offset",
            $params
        );

        return ['orders' => $rows, 'total' => $total];
    }

    /**
     * Single order with its events timeline.
     */
    public static function getOrder(int $orderId): ?array {
        $tid = current_tenant_id();
        $order = Database::row(
            "SELECT o.*, c.form_title, c.field_map
               FROM surveypipeline_orders o
               LEFT JOIN surveypipeline_connections c
                      ON c.form_id = o.form_id AND c.tenant_id = o.tenant_id
              WHERE o.id = ? AND o.tenant_id = ?",
            [$orderId, $tid]
        );
        if (!$order) return null;

        $order['events'] = Database::rows(
            "SELECT * FROM surveypipeline_events WHERE order_id = ? AND tenant_id = ? ORDER BY created_at ASC",
            [$orderId, $tid]
        );
        return $order;
    }

    /**
     * Count of orders per stage for the current tenant.
     * Returns ['new' => int, 'quoted' => int, ...].
     */
    public static function stageCounts(): array {
        $tid  = current_tenant_id();
        $rows = Database::rows(
            "SELECT stage, COUNT(*) AS n FROM surveypipeline_orders
              WHERE tenant_id = ? GROUP BY stage",
            [$tid]
        );
        $out = array_fill_keys(array_keys(self::STAGES), 0);
        foreach ($rows as $r) {
            if (isset($out[$r['stage']])) $out[$r['stage']] = (int)$r['n'];
        }
        return $out;
    }

    // ── Stage changes ─────────────────────────────────────────

    /**
     * Move an order to a new stage. Records an event.
     * Returns true on success, false if order not found.
     */
    public static function moveStage(int $orderId, string $toStage, int $actorId, string $actorName = ''): bool {
        if (!in_array($toStage, self::VALID_STAGES, true)) return false;
        $tid = current_tenant_id();

        $order = Database::row(
            "SELECT id, stage FROM surveypipeline_orders WHERE id = ? AND tenant_id = ?",
            [$orderId, $tid]
        );
        if (!$order) return false;

        $fromStage = $order['stage'];
        if ($fromStage === $toStage) return true;

        Database::update(
            'surveypipeline_orders',
            ['stage' => $toStage],
            'id = ? AND tenant_id = ?',
            [$orderId, $tid]
        );

        if ($actorName === '') $actorName = self::resolveActorName($actorId);

        self::recordEvent($orderId, $tid, 'stage_changed', $fromStage, $toStage, null, $actorId, $actorName);
        AuditLog::record('surveypipeline.stage_changed', (string)$orderId, [
            'from' => $fromStage, 'to' => $toStage,
        ]);
        return true;
    }

    // ── Notes ─────────────────────────────────────────────────

    /**
     * Add an internal note to an order. Returns the new event id.
     */
    public static function addNote(int $orderId, string $note, int $actorId, string $actorName = ''): int {
        $tid  = current_tenant_id();
        $note = trim($note);
        if ($note === '') return 0;

        // Confirm order belongs to this tenant
        $exists = Database::value(
            "SELECT id FROM surveypipeline_orders WHERE id = ? AND tenant_id = ?",
            [$orderId, $tid]
        );
        if (!$exists) return 0;

        if ($actorName === '') $actorName = self::resolveActorName($actorId);

        return self::recordEvent($orderId, $tid, 'note_added', null, null, $note, $actorId, $actorName);
    }

    // ── Order field updates ───────────────────────────────────

    /**
     * Update editable order fields (quoted_amount, scheduled_at, notes, assigned_to).
     * Only updates the fields present in $fields array.
     */
    public static function updateOrder(int $orderId, array $fields, int $actorId, string $actorName = ''): bool {
        $tid     = current_tenant_id();
        $allowed = ['vessel_name','client_name','client_email','client_phone',
                    'survey_locale','loa_ft','quoted_amount','scheduled_at',
                    'notes','assigned_to'];
        $update  = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $fields)) {
                $update[$k] = $fields[$k] === '' ? null : $fields[$k];
            }
        }
        if (!$update) return false;

        $rows = Database::update(
            'surveypipeline_orders',
            $update,
            'id = ? AND tenant_id = ?',
            [$orderId, $tid]
        );
        if ($rows === 0) return false;

        if ($actorName === '') $actorName = self::resolveActorName($actorId);

        // Record a quote_sent event when amount is set
        if (isset($update['quoted_amount']) && $update['quoted_amount'] !== null) {
            self::recordEvent($orderId, $tid, 'quote_sent', null, null,
                'Quote set: $' . number_format((float)$update['quoted_amount'], 2),
                $actorId, $actorName);
        }
        // Record a scheduled event when scheduled_at is set
        if (isset($update['scheduled_at']) && $update['scheduled_at'] !== null) {
            self::recordEvent($orderId, $tid, 'scheduled', null, null,
                'Scheduled for: ' . $update['scheduled_at'],
                $actorId, $actorName);
        }
        AuditLog::record('surveypipeline.order_updated', (string)$orderId);
        return true;
    }

    // ── Form field listing (for field-map UI) ─────────────────

    /**
     * Returns all field names + labels for a form so the settings page
     * can render a mapping dropdown. Goes through FormsAPI rather than
     * querying forms_definitions directly (soft coupling — see
     * BUILDING-PLUGINS.md §4). Returns [] if Forms isn't active.
     * Returns [['name'=>..., 'label'=>...], ...]
     */
    public static function formFields(int $formId): array {
        if (!PluginLoader::isActive('forms') || !class_exists('FormsAPI')) return [];

        $form = FormsAPI::getFormById($formId);
        if (!$form || empty($form['fields']) || !is_array($form['fields'])) return [];

        $out = [];
        foreach ($form['fields'] as $f) {
            if (!is_array($f)) continue;
            $name  = trim((string)($f['name']  ?? ''));
            $label = trim((string)($f['label'] ?? $name));
            if ($name !== '') $out[] = ['name' => $name, 'label' => $label];
        }
        return $out;
    }

    /**
     * All published forms, with field counts, sourced entirely through
     * FormsAPI (soft coupling — never query forms_definitions directly).
     * Returns [] if Forms isn't active.
     * Returns [['id'=>, 'title'=>, 'slug'=>, 'status'=>, 'field_count'=>], ...]
     */
    public static function availableForms(): array {
        if (!PluginLoader::isActive('forms') || !class_exists('FormsAPI')) return [];

        $picker = FormsAPI::pickerOptions(); // [['v'=>slug, 'l'=>title], ...] published only
        $out = [];
        foreach ($picker as $opt) {
            $slug = (string)($opt['v'] ?? '');
            if ($slug === '') continue;
            $form = FormsAPI::getForm($slug);
            if (!$form) continue;
            $out[] = [
                'id'          => (int)$form['id'],
                'title'       => (string)($form['title'] ?? $opt['l'] ?? $slug),
                'slug'        => $slug,
                'status'      => (string)($form['status'] ?? 'published'),
                'field_count' => is_array($form['fields'] ?? null) ? count($form['fields']) : 0,
            ];
        }
        return $out;
    }

    // ── Private helpers ───────────────────────────────────────

    private static function recordEvent(
        int    $orderId,
        int    $tid,
        string $eventType,
        ?string $fromStage,
        ?string $toStage,
        ?string $note,
        int    $actorId,
        string $actorName
    ): int {
        return Database::insert('surveypipeline_events', [
            'tenant_id'  => $tid,
            'order_id'   => $orderId,
            'event_type' => $eventType,
            'from_stage' => $fromStage,
            'to_stage'   => $toStage,
            'note'       => $note,
            'actor_id'   => $actorId,
            'actor_name' => mb_substr($actorName, 0, 190),
        ]);
    }

    private static function generateRef(int $tid): string {
        $prefix = Database::setting('survey-pipeline.order_ref_prefix') ?: 'ORD';
        $year   = date('Y');
        $max    = Database::value(
            "SELECT MAX(CAST(SUBSTRING(order_ref, ?) AS UNSIGNED))
               FROM surveypipeline_orders
              WHERE tenant_id = ? AND order_ref LIKE ?",
            [strlen($prefix) + strlen($year) + 2, $tid, $prefix . '-' . $year . '-%']
        );
        $next = ((int)$max) + 1;
        return $prefix . '-' . $year . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Resolve an admin user's display name from their id.
     *
     * NOTE: `users` is a Slate *core* table, not another plugin's table —
     * the §4.3 boundary rule in BUILDING-PLUGINS.md is about not reaching
     * into other plugins' schemas, and there's no UsersAPI to go through
     * for an arbitrary-id lookup (Auth::user() only returns the current
     * session's user). Kept intentionally minimal: read-only, single row,
     * keyed by primary key.
     */
    private static function resolveActorName(int $actorId): string {
        if ($actorId <= 0) return 'System';
        $row = Database::row(
            "SELECT name, email FROM users WHERE id = ? LIMIT 1",
            [$actorId]
        );
        if (!$row) return 'User #' . $actorId;
        $name = trim((string)($row['name'] ?? ''));
        return $name !== '' ? $name : (string)($row['email'] ?? 'User #' . $actorId);
    }

    private static function notifyAdmin(int $orderId, array $conn, callable $get): void {
        $notifyEnabled = Database::setting('survey-pipeline.notify_on_new');
        // Default to enabled if the setting has never been saved.
        if ($notifyEnabled !== null && (int)$notifyEnabled === 0) return;

        $email = Database::setting('survey-pipeline.admin_email');
        if (!$email) $email = Database::setting('admin_email');
        if (!$email) return;

        $order = Database::row(
            "SELECT order_ref, stage, created_at FROM surveypipeline_orders WHERE id = ? AND tenant_id = ?",
            [$orderId, current_tenant_id()]
        );
        if (!$order) return;

        $ref     = $order['order_ref'];
        $vessel  = $get('vessel_name')   ?: '(not specified)';
        $client  = $get('client_name')   ?: '(not specified)';
        $cEmail  = $get('client_email')  ?: '';
        $phone   = $get('client_phone')  ?: '';
        $locale  = $get('survey_locale') ?: '';
        $loa     = $get('loa_ft')        ?: '';
        $form    = e((string)($conn['form_title'] ?? ''));
        $detailUrl = defined('SLATE_URL') ? rtrim(SLATE_URL, '/') . '/plugins/survey-pipeline/admin/index.php' : '';

        $body  = '<h2 style="margin-top:0">New survey order: ' . e($ref) . '</h2>';
        $body .= '<table style="border-collapse:collapse;font-size:14px;">';
        foreach ([
            'Form'     => $form,
            'Vessel'   => e($vessel),
            'Client'   => e($client),
            'Email'    => e($cEmail),
            'Phone'    => e($phone),
            'Locale'   => e($locale),
            'LOA'      => e($loa),
        ] as $k => $v) {
            if ($v === '') continue;
            $body .= "<tr><td style='padding:4px 12px 4px 0;color:#666;white-space:nowrap'>$k</td>"
                  .  "<td style='padding:4px 0'><strong>$v</strong></td></tr>";
        }
        $body .= '</table>';
        if ($detailUrl) {
            $body .= '<p style="margin-top:16px"><a href="' . e($detailUrl) . '">View in Survey Pipeline →</a></p>';
        }

        try {
            Mailer::send($email, 'New survey order: ' . $vessel . ' — ' . $ref, $body, '', true);
            AuditLog::record('surveypipeline.email_sent', (string)$orderId, ['to' => $email]);
        } catch (\Throwable $e) {
            slate_log('SurveyPipeline: admin notify failed: ' . $e->getMessage(), 'warning');
        }
    }
}
