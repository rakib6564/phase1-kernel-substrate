<?php
/**
 * Coaching — Client feed.
 *
 * Live-ish feed of the most recent diary entries across all program
 * clients. Wave 2 uses simple pagination on `created_at DESC`; a
 * future wave can layer scheduled-message deliveries + goal check-ins
 * into the same stream.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/CoachingAPI.php';

Auth::require();
Auth::requirePerm('coaching.view_clients');
CoachingAPI::ensureSchema();

$pageTitle  = 'Coaching · Feed';
$currentNav = 'coaching-feed';

$filterClient = (int)($_GET['client'] ?? 0);
if ($filterClient > 0) {
    $tid = current_tenant_id();
    $entries = Database::rows(
        "SELECT e.id, e.customer_id, e.day, e.meal_type, e.emotion, e.summary, e.created_at,
                c.name AS customer_name, c.email AS customer_email
           FROM coaching_diary_entry e
           JOIN customers c ON c.id = e.customer_id
          WHERE e.tenant_id = ? AND e.customer_id = ?
          ORDER BY e.created_at DESC LIMIT 100",
        [$tid, $filterClient]);
} else {
    $entries = CoachingAPI::recentEntriesAcrossClients(50);
}

require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Coaching',  'href' => plugin_url('coaching', 'admin/index.php')],
    ['label' => 'Client feed'],
]);

$emojiMap = ['breakfast'=>'🍳','lunch'=>'🥗','dinner'=>'🍽️','snack'=>'🥨','binge'=>'🍫','drink'=>'☕','other'=>'🍴'];
$emotions = CoachingAPI::emotions();
?>

<div class="page-header">
    <div>
        <h1>Client feed<?php if ($filterClient > 0 && $entries): ?> · <?= e($entries[0]['customer_name']) ?><?php endif; ?></h1>
        <p class="text-muted"><?= $filterClient > 0 ? 'Diary entries for this client.' : 'Latest diary entries across everyone in the program. Newest first.' ?></p>
    </div>
    <?php if ($filterClient > 0): ?>
        <a href="<?= e(plugin_url('coaching', 'admin/feed.php')) ?>" class="btn btn-ghost">All clients</a>
    <?php endif; ?>
</div>

<?php if (!$entries): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title">No entries yet</div>
            <p class="text-sm">This fills up as your clients start logging meals.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <table class="admin-table" style="width:100%;">
            <thead>
                <tr>
                    <th></th>
                    <th>Client</th>
                    <th>Meal</th>
                    <th>Emotion</th>
                    <th>Foods</th>
                    <th>Logged</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $e):
                $icon = $emojiMap[$e['meal_type']] ?? '🍴';
                $emotionLabel = $e['emotion'] ? ($emotions[$e['emotion']] ?? $e['emotion']) : '';
                $ago = time() - strtotime((string)$e['created_at']);
                if ($ago < 60)   $agoLabel = 'just now';
                elseif ($ago < 3600)   $agoLabel = floor($ago/60) . 'm ago';
                elseif ($ago < 86400)  $agoLabel = floor($ago/3600) . 'h ago';
                else                   $agoLabel = date('j M, H:i', strtotime((string)$e['created_at']));
            ?>
                <tr>
                    <td style="font-size:20px;"><?= $icon ?></td>
                    <td>
                        <strong><?= e($e['customer_name']) ?></strong><br>
                        <small class="text-muted"><?= e($e['customer_email']) ?></small>
                    </td>
                    <td style="text-transform:capitalize;"><?= e($e['meal_type']) ?></td>
                    <td><?= $emotionLabel ? e($emotionLabel) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= !empty($e['summary']) ? e($e['summary']) : '<span class="text-muted">—</span>' ?></td>
                    <td>
                        <?= e($agoLabel) ?><br>
                        <small class="text-muted"><?= e(date('j M Y', strtotime((string)$e['day']))) ?></small>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
