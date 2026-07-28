<?php
/**
 * Small Business Kit — Theme picker admin page.
 *
 * Cards-grid of available themes. Click "Switch" on a card → that
 * theme becomes active site-wide. Every Content Builder page picks
 * up the new tokens on the next render.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/SBKitAPI.php';

Auth::require();
Auth::requirePerm('sbk.theme');

$pageTitle  = 'Site theme';
$currentNav = 'sbk-theme';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>'Security check failed.'];
    } else {
        $slug = (string)($_POST['theme'] ?? '');
        if (SBKThemes::get($slug)) {
            SBKitAPI::setActiveTheme($slug);
            AuditLog::record('sbk.theme.set', $slug);
            $flash = ['type'=>'success','msg'=>'Theme switched to "' . e(SBKThemes::get($slug)['label']) . '". Reload any public page to see it.'];
        } else {
            $flash = ['type'=>'error','msg'=>'Unknown theme.'];
        }
    }
}

$active = SBKitAPI::activeTheme();
$themes = SBKThemes::all();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Site theme'],
]); ?>

<div class="page-header">
    <div>
        <h1>Site theme</h1>
        <p class="page-header-sub">Pick the theme that styles your small-business pages. Switching is instant — content stays, only colours, type and spacing change.</p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<style>
    .sbk-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 18px; }
    @media (max-width: 720px) { .sbk-grid { grid-template-columns: 1fr; } }
    .sbk-card {
        display: flex; flex-direction: column; gap: 14px;
        padding: 22px; border-radius: var(--radius, 12px);
        background: var(--surface);
        border: 1px solid var(--border);
        transition: border-color .2s, transform .2s, box-shadow .2s;
    }
    .sbk-card:hover { transform: translateY(-2px); border-color: color-mix(in srgb, var(--accent, #2563eb) 35%, var(--border)); }
    .sbk-card.is-active { border-color: var(--accent, #2563eb); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent, #2563eb) 14%, transparent); }
    .sbk-swatch { display: flex; gap: 8px; padding: 14px; border-radius: 10px; background: var(--surface-2, #f7f7f7); }
    .sbk-swatch span { flex: 1; height: 56px; border-radius: 8px; }
    .sbk-meta h3 { margin: 0 0 4px; display: flex; align-items: center; gap: 8px; font-size: 17px; color: var(--text); }
    .sbk-meta p { margin: 0; color: var(--muted); font-size: 13.5px; line-height: 1.5; }
    .sbk-active-pill { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; padding: 3px 8px; border-radius: 999px; background: color-mix(in srgb, var(--accent, #2563eb) 18%, transparent); color: var(--accent, #2563eb); }
    .sbk-foot { display: flex; justify-content: flex-end; }
</style>

<div class="sbk-grid">
    <?php foreach ($themes as $slug => $t):
        $isActive = $slug === $active;
        $tokens = $t['tokens'];
    ?>
        <form method="post" class="sbk-card<?= $isActive ? ' is-active' : '' ?>">
            <?php csrf_field(); ?>
            <input type="hidden" name="theme" value="<?= e($slug) ?>">
            <div class="sbk-swatch">
                <span style="background: <?= e($tokens['--sb-ink']) ?>;"></span>
                <span style="background: <?= e($tokens['--sb-accent']) ?>;"></span>
                <span style="background: <?= e($tokens['--sb-surface']) ?>; border: 1px solid <?= e($tokens['--sb-line']) ?>;"></span>
                <span style="background: <?= e($tokens['--sb-page']) ?>; border: 1px solid <?= e($tokens['--sb-line']) ?>;"></span>
            </div>
            <div class="sbk-meta">
                <h3><?= e($t['label']) ?><?php if ($isActive): ?> <span class="sbk-active-pill">Active</span><?php endif; ?></h3>
                <p><?= e($t['blurb']) ?></p>
            </div>
            <div class="sbk-foot">
                <?php if (!$isActive): ?>
                    <button class="btn btn-primary" type="submit">Switch to this theme</button>
                <?php else: ?>
                    <span class="btn" style="pointer-events:none;opacity:.7;">Currently active</span>
                <?php endif; ?>
            </div>
        </form>
    <?php endforeach; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
