<?php
/**
 * Coaching — customer-facing area.  URL: /coaching?view=X
 *
 *   ?view=home    (default) — Today dashboard
 *   ?view=profile           — profile edit form (identity + body + medical)
 *   ?view=goals             — all goals + daily check-in
 *   ?view=diary             — food diary list (past 14 days)
 *   ?view=entry             — new/edit diary entry (?id=N to edit)
 *   ?view=charts            — insight charts (?range=7|14|30|90)
 *   ?view=chat              — practitioner thread
 *   ?view=structure         — meal structure
 *   ?view=shopping          — shopping lists
 *   ?view=recipes / recipe  — recipe library + detail
 *   ?view=motivation        — challenges & exercises
 *   ?view=summary           — end-of-program summary
 *
 * Identity is core customers. Access is gated on active enrollment
 * (membership plugin). The router falls back to a friendly "not enrolled
 * yet" landing when the gate fails.
 *
 * LAYOUT — this file renders its own document shell (coaching_head /
 * coaching_foot) rather than requiring customer/partials/header.php.
 * That partial only offers an 'auth' variant (a centred 400px login card)
 * and a 'dashboard' variant (with its own branded topbar); both fight an
 * app layout, and requiring it is what produced the duplicated brand
 * block. coaching_head() calls exactly the same core helpers that partial
 * does — slate_ui_emit_css(), slate_brand_accent_emit(), a11y_head.php,
 * the customer_head hook and renderQueuedStyles(). If core ever adds
 * something to the customer <head>, mirror it there.
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
require_once SLATE_ROOT . '/includes/portal_ui.php';
require_once dirname(__DIR__) . '/CoachingAPI.php';
require_once dirname(__DIR__) . '/CoachingCharts.php';
CoachingAPI::ensureSchema();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

Auth::requireCustomer();
$cid  = (int) Auth::customerId();
$view = (string)($_GET['view'] ?? 'home');
$flash = null;

// Ensure a profile row exists (self-heal — customer_registered hook covers
// new signups but this catches customers who existed before the plugin).
CoachingAPI::provisionProfile($cid);

$enrolled = CoachingAPI::isEnrolled($cid);

// ── POST actions (all views) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $enrolled) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'save_profile') {
            $fields = [];
            foreach (['dob','gender','height_cm','weight_kg','body_type',
                      'pathologies','ongoing_care','alternative_medicine','personal_issues'] as $k) {
                if (isset($_POST[$k])) $fields[$k] = $_POST[$k];
            }
            // JSON arrays from CSV inputs.
            if (isset($_POST['intolerances_csv'])) {
                $csv = trim((string)$_POST['intolerances_csv']);
                $fields['intolerances'] = $csv === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $csv))));
            }
            if (isset($_POST['dietary_pref_csv'])) {
                $csv = trim((string)$_POST['dietary_pref_csv']);
                $fields['dietary_preferences'] = $csv === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $csv))));
            }
            CoachingAPI::saveProfile($cid, $fields);
            $flash = ['type' => 'success', 'msg' => 'Profile updated.'];
        }
        elseif ($action === 'checkin') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'not_achieved');
            $day    = (string)($_POST['day'] ?? date('Y-m-d'));
            CoachingAPI::recordCheckIn($cid, $goalId, $day, $status);
            $flash = ['type' => 'success', 'msg' => 'Check-in recorded.'];
        }
        elseif ($action === 'extra_action') {
            $text = trim((string)($_POST['action_text'] ?? ''));
            $day  = (string)($_POST['day'] ?? date('Y-m-d'));
            if ($text !== '') CoachingAPI::recordExtraAction($cid, $day, $text);
            $flash = ['type' => 'success', 'msg' => 'Added.'];
        }
        elseif ($action === 'save_entry') {
            $foods = [];
            $names      = (array)($_POST['food_name'] ?? []);
            $cats       = (array)($_POST['food_category'] ?? []);
            $pleasures  = (array)($_POST['food_pleasure'] ?? []);
            foreach ($names as $i => $name) {
                $foods[] = [
                    'name'             => (string)$name,
                    'category'         => (string)($cats[$i] ?? ''),
                    'is_pleasure_food' => !empty($pleasures[$i]),
                ];
            }
            $entryId = CoachingAPI::saveDiaryEntry($cid, [
                'id'            => (int)($_POST['id'] ?? 0),
                'day'           => (string)($_POST['day'] ?? date('Y-m-d')),
                'meal_type'     => (string)($_POST['meal_type'] ?? 'other'),
                'started_at'    => (string)($_POST['started_at'] ?? ''),
                'duration_min'  => $_POST['duration_min'] ?? '',
                'emotion'       => (string)($_POST['emotion'] ?? ''),
                'emotion_note'  => (string)($_POST['emotion_note'] ?? ''),
                'hunger_before' => $_POST['hunger_before'] ?? '',
                'satiety_after' => $_POST['satiety_after'] ?? '',
                'context'       => (string)($_POST['context'] ?? ''),
                'context_note'  => (string)($_POST['context_note'] ?? ''),
                'quantity_note' => (string)($_POST['quantity_note'] ?? ''),
                'notes'         => (string)($_POST['notes'] ?? ''),
                'foods'         => $foods,
            ]);
            // Optional photo upload alongside entry save.
            if (!empty($_FILES['photo']['tmp_name'])) {
                CoachingAPI::saveDiaryPhoto($entryId, $_FILES['photo'], (string)($_POST['photo_caption'] ?? ''));
            }
            header('Location: ?view=entry&id=' . $entryId . '&saved=1');
            exit;
        }
        elseif ($action === 'delete_entry') {
            $entryId = (int)($_POST['entry_id'] ?? 0);
            if ($entryId > 0) CoachingAPI::deleteDiaryEntry($entryId, $cid);
            header('Location: ?view=diary');
            exit;
        }
        elseif ($action === 'delete_photo') {
            $photoId = (int)($_POST['photo_id'] ?? 0);
            $entryId = (int)($_POST['entry_id'] ?? 0);
            if ($photoId > 0) CoachingAPI::deleteDiaryPhoto($photoId, $cid);
            header('Location: ?view=entry&id=' . $entryId);
            exit;
        }
        elseif ($action === 'save_hydration') {
            $day    = (string)($_POST['day'] ?? date('Y-m-d'));
            $liters = (float)($_POST['liters'] ?? 0);
            $glass  = (int)($_POST['glass_count'] ?? 0);
            $other  = (string)($_POST['other_drinks'] ?? '');
            CoachingAPI::upsertHydration($cid, $day, $liters, $glass, $other);
            $flash = ['type' => 'success', 'msg' => 'Hydration saved.'];
        }
        elseif ($action === 'add_activity') {
            $day      = (string)($_POST['day'] ?? date('Y-m-d'));
            $kind     = (string)($_POST['kind'] ?? '');
            $duration = isset($_POST['duration_min']) && $_POST['duration_min'] !== '' ? (int)$_POST['duration_min'] : null;
            $notes    = (string)($_POST['notes'] ?? '');
            if ($kind !== '') {
                CoachingAPI::addActivity($cid, $day, $kind, $duration, $notes);
                $flash = ['type' => 'success', 'msg' => 'Activity logged.'];
            }
        }
        elseif ($action === 'delete_activity') {
            $activityId = (int)($_POST['activity_id'] ?? 0);
            if ($activityId > 0) CoachingAPI::deleteActivity($activityId, $cid);
            $flash = ['type' => 'success', 'msg' => 'Removed.'];
        }
        elseif ($action === 'send_message') {
            $threadId = CoachingAPI::ensureThread($cid);
            $body     = (string)($_POST['body'] ?? '');
            $photo    = !empty($_FILES['photo']['tmp_name']) ? CoachingAPI::saveChatPhoto($_FILES['photo']) : null;
            CoachingAPI::sendMessage($threadId, 'customer', $body, $photo, null);
            header('Location: ?view=chat');
            exit;
        }
        elseif ($action === 'complete_challenge') {
            $chId = (int)($_POST['challenge_id'] ?? 0);
            $note = trim((string)($_POST['client_note'] ?? ''));
            if ($chId > 0) CoachingAPI::completeChallenge($chId, $cid, $note);
            header('Location: ?view=motivation');
            exit;
        }
        elseif ($action === 'share_recipe') {
            $ingredients = array_filter(array_map('trim', explode("\n", (string)($_POST['ingredients_text'] ?? ''))));
            $photoPath = !empty($_FILES['photo']['tmp_name']) ? CoachingAPI::saveRecipePhoto($_FILES['photo']) : null;
            CoachingAPI::saveRecipe([
                'author'            => 'customer',
                'customer_id'       => $cid,
                'title'             => (string)($_POST['title'] ?? ''),
                'photo_path'        => $photoPath,
                'ingredients'       => $ingredients,
                'instructions_html' => (string)($_POST['instructions_html'] ?? ''),
                'notes'             => (string)($_POST['notes'] ?? ''),
            ]);
            header('Location: ?view=recipes&shared=1');
            exit;
        }
    }
}


// ── Dispatch ────────────────────────────────────────────────────────────
// Every view gets an identical shell: head → topbar → nav → tiles → FAB.

$pageMeta = [
    'home'       => ['Today',     date('l, j F')],
    'profile'    => ['Account',   'My profile'],
    'goals'      => ['Progress',  'My goals'],
    'diary'      => ['Nutrition', 'Food diary'],
    'entry'      => ['Nutrition', 'Log a meal'],
    'charts'     => ['Insights',  'My charts'],
    'chat'       => ['Coach',     'Chat'],
    'structure'  => ['Nutrition', 'Meal structure'],
    'shopping'   => ['Nutrition', 'Shopping list'],
    'recipes'    => ['Nutrition', 'Recipes'],
    'recipe'     => ['Nutrition', 'Recipe'],
    'motivation' => ['Coach',     'Motivation'],
    'summary'    => ['Coach',     'Program summary'],
];
if (!isset($pageMeta[$view])) $view = 'home';
[$eyebrow, $title] = $pageMeta[$view];

// The entry view titles itself after the entry's own day.
if ($view === 'entry') {
    $peekId = (int)($_GET['id'] ?? 0);
    if ($peekId > 0) {
        $peek = CoachingAPI::getDiaryEntry($peekId);
        if ($peek && (int)$peek['customer_id'] === $cid) {
            $eyebrow = 'Editing entry';
            $title   = date('l, j F', strtotime((string)$peek['day']));
        }
    }
}

// Which of the 5 bottom-nav slots owns this view.
$navGroups = [
    'home'    => ['home', 'goals'],
    'diary'   => ['diary', 'entry', 'structure', 'shopping', 'recipes', 'recipe'],
    'charts'  => ['charts', 'summary'],
    'chat'    => ['chat', 'motivation'],
    'profile' => ['profile'],
];
$navKey = 'home';
foreach ($navGroups as $slot => $slotViews) {
    if (in_array($view, $slotViews, true)) { $navKey = $slot; break; }
}

coaching_head($title . ' — Body & Soul Program');

if (!$enrolled) {
    coaching_topbar('Program', 'Body & Soul');
    echo '<main class="ck-wrap" id="ck-main">';
    coaching_render_not_enrolled();
    echo '</main>';
    coaching_foot();
    return;
}

coaching_topbar($eyebrow, $title);
coaching_bottom_nav($cid, $navKey);
echo '<main class="ck-wrap" id="ck-main">';

switch ($view) {
    case 'profile':    coaching_render_profile($cid, $flash);    break;
    case 'goals':      coaching_render_goals($cid, $flash);      break;
    case 'diary':      coaching_render_diary($cid, $flash);      break;
    case 'entry':      coaching_render_entry($cid, $flash);      break;
    case 'charts':     coaching_render_charts($cid, $flash);     break;
    case 'chat':       coaching_render_chat($cid, $flash);       break;
    case 'structure':  coaching_render_structure($cid);          break;
    case 'shopping':   coaching_render_shopping($cid);           break;
    case 'recipes':    coaching_render_recipes($cid, $flash);    break;
    case 'recipe':     coaching_render_recipe_detail($cid);      break;
    case 'motivation': coaching_render_motivation($cid, $flash); break;
    case 'summary':    coaching_render_summary($cid);            break;
    case 'home':
    default:           coaching_render_home($cid, $flash);       break;
}

echo '</main>';
coaching_fab($view);
coaching_foot();


// ═══ Shell ══════════════════════════════════════════════════════════════

/**
 * Opens the document. Mirrors the <head> of customer/partials/header.php —
 * see the file-level note above for why this isn't just requiring it.
 */
function coaching_head(string $title): void {
    // Shell comes from the shared portal kit so chrome, tokens and the icon
    // engine match the rest of the customer portal. The bento body below is
    // coaching's own — portal.css is emitted BEFORE the plugin's queued
    // styles, so customer.css still wins every collision.
    slate_portal_head($title, 'ck-body');
}

function coaching_foot(): void {
    slate_portal_foot();
}

/**
 * The single branded header. Renders the tenant's own logo
 * (brand_logo_path) when set, falling back to an initial mark — and
 * nothing else renders a brand block anywhere in this portal.
 */
function coaching_topbar(string $eyebrow, string $title): void {
    // Delegates to the shared portal topbar (one branded header, logo
    // rendered once). title_in_bar keeps coaching's compact layout: its body
    // is a narrow mobile-first column, so a full-width title block below the
    // bar would not line up with the tiles.
    slate_portal_topbar([
        'eyebrow'      => $eyebrow,
        'title'        => $title,
        'title_in_bar' => true,
        'brand_href'   => '?view=home',
    ]);
}

/** 5-slot bottom nav (mobile) / centred pill nav (desktop). */
function coaching_bottom_nav(int $cid, string $current): void {
    $unread = 0;
    try { $unread = CoachingAPI::unreadForCustomer($cid); } catch (\Throwable $e) {}

    $items = [
        ['home',    '🏠', 'Home'],
        ['diary',   '📖', 'Diary'],
        ['charts',  '📊', 'Charts'],
        ['chat',    '💬', 'Chat'],
        ['profile', '👤', 'Profile'],
    ];
    ?>
    <nav class="ck-nav" aria-label="Program sections">
        <?php foreach ($items as [$slot, $glyph, $label]):
            $on = ($current === $slot);
        ?>
            <a href="?view=<?= e($slot) ?>" class="<?= $on ? 'is-on' : '' ?>"
               <?= $on ? 'aria-current="page"' : '' ?>>
                <span class="ck-nav-glyph" aria-hidden="true"><?= $glyph ?></span>
                <span><?= e($label) ?></span>
                <?php if ($slot === 'chat' && $unread > 0): ?>
                    <span class="ck-nav-badge" aria-label="<?= (int)$unread ?> unread messages"><?= (int)$unread ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}

/** Floating "log a meal" button. Hidden on the entry view — you're there. */
function coaching_fab(string $view): void {
    if ($view === 'entry') return;
    ?>
    <a href="?view=entry" class="ck-fab" aria-label="Log a meal">
        <span aria-hidden="true">+</span>
    </a>
    <?php
}


// ═══ Components ═════════════════════════════════════════════════════════

/**
 * SVG progress ring with a stroke-dashoffset sweep.
 * $tone is a CSS custom-property name from the brand ladder.
 */
function coaching_ring(float $pct, string $tone, string $glyph, string $value, string $label): string {
    $pct    = max(0.0, min(100.0, $pct));
    $r      = 38;
    $circ   = 2 * M_PI * $r;
    $offset = $circ - ($circ * $pct / 100);

    ob_start();
    ?>
    <div>
        <div class="ck-ring">
            <svg viewBox="0 0 92 92" width="92" height="92" role="img"
                 aria-label="<?= e($label . ': ' . $value . ', ' . round($pct) . '% of target') ?>">
                <circle class="ck-ring-track" cx="46" cy="46" r="<?= $r ?>"/>
                <circle class="ck-ring-fill" cx="46" cy="46" r="<?= $r ?>"
                        stroke="var(<?= e($tone) ?>)"
                        style="--ck-circ: <?= number_format($circ, 2, '.', '') ?>;"
                        stroke-dasharray="<?= number_format($circ, 2, '.', '') ?>"
                        stroke-dashoffset="<?= number_format($offset, 2, '.', '') ?>"/>
            </svg>
            <div class="ck-ring-mid" aria-hidden="true">
                <span class="ck-ring-glyph"><?= $glyph ?></span>
                <span class="ck-ring-val"><?= e($value) ?></span>
            </div>
        </div>
        <div class="ck-ring-label"><?= e($label) ?></div>
    </div>
    <?php
    return ob_get_clean();
}

/** Tile header: eyebrow + title, with an optional right-hand slot. */
function coaching_tile_head(string $eyebrow, string $title, string $rightHtml = ''): void {
    ?>
    <div class="ck-tile-head">
        <div>
            <span class="ck-eyebrow"><?= e($eyebrow) ?></span>
            <h2 class="ck-tile-title"><?= e($title) ?></h2>
        </div>
        <?= $rightHtml ?>
    </div>
    <?php
}

function coaching_alert(?array $flash): void {
    if (!$flash) return;
    $kind = (($flash['type'] ?? '') === 'success') ? 'success' : 'danger';
    ?>
    <div class="ck-alert ck-alert--<?= e($kind) ?>" role="status">
        <span aria-hidden="true"><?= $kind === 'success' ? '✓' : '!' ?></span>
        <span><?= e($flash['msg']) ?></span>
    </div>
    <?php
}

function coaching_empty(string $glyph, string $title, string $subHtml = ''): void {
    ?>
    <div class="ck-empty">
        <div class="ck-empty-glyph" aria-hidden="true"><?= $glyph ?></div>
        <div class="ck-empty-title"><?= e($title) ?></div>
        <?php if ($subHtml !== ''): ?><div class="ck-empty-sub"><?= $subHtml ?></div><?php endif; ?>
    </div>
    <?php
}

/** Shared emoji vocabulary. */
function coaching_emotion_emoji(): array {
    return [
        'joy'=>'😊','stress'=>'😰','fatigue'=>'😴','anxiety'=>'😟','boredom'=>'😑',
        'anger'=>'😠','sadness'=>'😢','serenity'=>'😌','neutrality'=>'😐','other'=>'🙂',
    ];
}
function coaching_meal_emoji(): array {
    return [
        'breakfast'=>'🍳','lunch'=>'🥗','dinner'=>'🍽️','snack'=>'🥨',
        'binge'=>'🍫','drink'=>'☕','other'=>'🍴',
    ];
}
function coaching_context_emoji(): array {
    return [
        'home'=>'🏠','work'=>'💼','friends'=>'👯','family'=>'👨‍👩‍👧',
        'restaurant'=>'🍽️','commute'=>'🚌','other'=>'📍',
    ];
}

/** "Today" / "Yesterday" / "Monday, 7 July" */
function coaching_day_label(string $day): string {
    if ($day === date('Y-m-d'))                      return 'Today';
    if ($day === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('l, j F', strtotime($day));
}

/** The program areas the 5-slot bottom nav can't hold. */
function coaching_program_links(): array {
    return [
        ['goals',      '🎯', 'My goals'],
        ['structure',  '🍱', 'Meal structure'],
        ['shopping',   '🛒', 'Shopping list'],
        ['recipes',    '🥘', 'Recipes'],
        ['motivation', '💪', 'Motivation'],
        ['summary',    '📄', 'Summary'],
    ];
}


// ═══ Views ══════════════════════════════════════════════════════════════

function coaching_render_not_enrolled(): void {
    ?>
    <div class="ck-bento">
        <div class="ck-tile ck-s12">
            <?php coaching_empty('🌱', 'Not enrolled yet',
                'The daily-tracking program opens up here once you&rsquo;re enrolled. '
                . 'If you&rsquo;ve booked a program, this unlocks as soon as your membership is activated.'); ?>
        </div>
    </div>
    <?php
}

function coaching_render_home(int $cid, ?array $flash): void {
    $day       = date('Y-m-d');
    $profile   = CoachingAPI::getProfile($cid);
    $goals     = CoachingAPI::listGoals($cid, 'daily', true);
    $hydration = CoachingAPI::getHydration($cid, $day);
    $todayEntries  = CoachingAPI::listDiaryEntries($cid, $day, $day);
    $todayActivity = CoachingAPI::listActivity($cid, $day);
    $challenges      = CoachingAPI::listChallenges($cid, true);
    $activeChallenge = $challenges[0] ?? null;

    $tid = current_tenant_id();

    $todayCheckins = [];
    foreach (Database::rows(
        "SELECT goal_id, status FROM coaching_goal_checkin
          WHERE tenant_id = ? AND customer_id = ? AND day = ?", [$tid, $cid, $day]) as $r) {
        $todayCheckins[(int)$r['goal_id']] = $r['status'];
    }

    // Small wins logged today. No API method exists for these yet — this is
    // the same direct-read pattern the check-in query above uses.
    $extraActions = Database::rows(
        "SELECT id, action_text FROM coaching_extra_action
          WHERE tenant_id = ? AND customer_id = ? AND day = ?
          ORDER BY created_at DESC", [$tid, $cid, $day]);

    $threadId  = CoachingAPI::ensureThread($cid);
    $latestMsg = Database::row(
        "SELECT * FROM coaching_message
          WHERE thread_id = ? AND sender = 'practitioner' AND sent_at IS NOT NULL
          ORDER BY sent_at DESC LIMIT 1", [$threadId]);
    $unreadFromCoach = CoachingAPI::unreadForCustomer($cid);

    // Consistency streak — consecutive days back from today with an entry.
    // Today not yet logged doesn't break a streak that's alive yesterday.
    $recentDays = Database::rows(
        "SELECT DISTINCT day FROM coaching_diary_entry
          WHERE tenant_id = ? AND customer_id = ? AND day <= ?
          ORDER BY day DESC LIMIT 90", [$tid, $cid, $day]);
    $loggedDays = array_column($recentDays, 'day');
    $streak = 0;
    for ($i = 0; $i < 90; $i++) {
        $probe = date('Y-m-d', strtotime("-{$i} days"));
        if (in_array($probe, $loggedDays, true)) { $streak++; continue; }
        if ($i === 0) continue;
        break;
    }

    $customer  = Database::row("SELECT name FROM customers WHERE id = ?", [$cid]);
    $firstName = $customer ? explode(' ', trim((string)$customer['name']))[0] : 'there';

    $hydroLitres = (float)($hydration['liters'] ?? 0);
    $hydroTarget = 2.0;
    $hydroPct    = min(100, ($hydroLitres / $hydroTarget) * 100);

    $mealsToday  = count($todayEntries);
    $mealsTarget = 3;
    $mealsPct    = min(100, ($mealsToday / $mealsTarget) * 100);

    $goalsDone = 0;
    foreach ($todayCheckins as $st) {
        if (in_array($st, ['achieved', 'exceeded'], true)) $goalsDone++;
    }
    $goalCount = count($goals);
    $goalsPct  = $goalCount > 0 ? min(100, ($goalsDone / $goalCount) * 100) : 0;

    $mealsBySlot = [];
    foreach ($todayEntries as $me) { $mealsBySlot[$me['meal_type']][] = $me; }

    $hour = (int) date('G');
    $timeOfDay = $hour < 5  ? 'Good night'
               : ($hour < 12 ? 'Good morning'
               : ($hour < 17 ? 'Good afternoon'
               : 'Good evening'));

    $emotions        = CoachingAPI::emotions();
    $emotionEmojis   = coaching_emotion_emoji();
    $profileComplete = $profile && !empty($profile['dob'])
                       && !empty($profile['height_cm']) && !empty($profile['weight_kg']);
    ?>

    <?php coaching_alert($flash); ?>

    <?php if (!$profileComplete): ?>
        <div class="ck-alert ck-alert--warn">
            <span aria-hidden="true">👋</span>
            <span><strong>Your profile is incomplete.</strong>
                  <a href="?view=profile">Fill it in →</a></span>
        </div>
    <?php endif; ?>

    <div class="ck-bento">

        <!-- Hero -->
        <section class="ck-tile ck-hero ck-s12">
            <div class="ck-hero-eyebrow"><?= e($timeOfDay) ?></div>
            <h1 class="ck-hero-name"><?= e($firstName) ?></h1>
            <p class="ck-hero-sub">
                <?php if ($mealsToday === 0 && $hydroLitres < 0.1): ?>
                    A fresh page. Log a meal, a glass of water, or check in on a goal —
                    whatever's easiest to start with.
                <?php else: ?>
                    You've logged <?= (int)$mealsToday ?> meal<?= $mealsToday === 1 ? '' : 's' ?>
                    and <?= number_format($hydroLitres, 1) ?>L of water today. Keep going.
                <?php endif; ?>
            </p>
            <div class="ck-hero-stats">
                <div class="ck-hero-stat">
                    <div class="ck-hero-stat-value"><?= (int)$streak ?><small> d</small></div>
                    <div class="ck-hero-stat-label">Streak</div>
                </div>
                <div class="ck-hero-stat">
                    <div class="ck-hero-stat-value"><?= (int)$mealsToday ?><small>/<?= (int)$mealsTarget ?></small></div>
                    <div class="ck-hero-stat-label">Meals today</div>
                </div>
                <div class="ck-hero-stat">
                    <div class="ck-hero-stat-value"><?= number_format($hydroLitres, 1) ?><small>L</small></div>
                    <div class="ck-hero-stat-label">Hydration</div>
                </div>
                <?php if ($goalCount > 0): ?>
                    <div class="ck-hero-stat">
                        <div class="ck-hero-stat-value"><?= (int)$goalsDone ?><small>/<?= (int)$goalCount ?></small></div>
                        <div class="ck-hero-stat-label">Goals hit</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Rings -->
        <section class="ck-tile ck-s5">
            <?php coaching_tile_head('Today', 'Progress'); ?>
            <div class="ck-rings">
                <?= coaching_ring($hydroPct, '--ck-ring-1', '💧',
                        number_format($hydroLitres, 1) . 'L', 'Water') ?>
                <?= coaching_ring($mealsPct, '--ck-ring-2', '🍽️',
                        (int)$mealsToday . '/' . (int)$mealsTarget, 'Meals') ?>
                <?= coaching_ring($goalsPct, '--ck-ring-3', '🎯',
                        (int)$goalsDone . '/' . (int)$goalCount, 'Goals') ?>
            </div>
        </section>

        <!-- Hydration -->
        <section class="ck-tile ck-s4">
            <?php coaching_tile_head('Hydration', 'Water intake'); ?>
            <div class="ck-hydro-value">
                <?= number_format($hydroLitres, 1) ?><small>L / <?= number_format($hydroTarget, 0) ?>L</small>
            </div>
            <div class="ck-glasses" role="img"
                 aria-label="<?= (int)round($hydroLitres * 4) ?> of 8 glasses filled">
                <?php for ($i = 0; $i < 8; $i++): ?>
                    <div class="ck-glass <?= $i < round($hydroLitres * 4) ? 'is-full' : '' ?>"></div>
                <?php endfor; ?>
            </div>
            <form method="post" class="ck-hydro-controls">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_hydration">
                <input type="hidden" name="day" value="<?= e($day) ?>">
                <input type="hidden" name="glass_count" value="<?= (int)($hydration['glass_count'] ?? 0) ?>">
                <input type="hidden" name="other_drinks" value="<?= e((string)($hydration['other_drinks'] ?? '')) ?>">
                <button type="submit" name="liters" value="<?= max(0, $hydroLitres - 0.25) ?>"
                        class="ck-step" aria-label="Remove a glass of water">−</button>
                <button type="submit" name="liters" value="<?= $hydroLitres + 0.25 ?>"
                        class="ck-step ck-step--go" aria-label="Add a glass of water">+</button>
            </form>
        </section>

        <!-- Mood -->
        <section class="ck-tile ck-s3">
            <?php coaching_tile_head('Right now', 'Mood'); ?>
            <div class="ck-emoji-grid">
                <?php foreach ($emotionEmojis as $key => $glyph): ?>
                    <a href="?view=entry&amp;emotion=<?= e($key) ?>" class="ck-emoji"
                       aria-label="Log a meal feeling <?= e($emotions[$key] ?? $key) ?>">
                        <span class="ck-emoji-glyph" aria-hidden="true"><?= $glyph ?></span>
                        <span class="ck-emoji-label"><?= e($emotions[$key] ?? $key) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Today's meals -->
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Nutrition', "Today's meals",
                '<a href="?view=diary" class="ck-tile-action">Diary →</a>'); ?>
            <div class="ck-meals">
                <?php foreach (['breakfast' => '🍳', 'lunch' => '🥗', 'dinner' => '🍽️'] as $slot => $glyph):
                    $loggedMeal = $mealsBySlot[$slot] ?? [];
                    if ($loggedMeal):
                        $me = $loggedMeal[0];
                ?>
                    <a href="?view=entry&amp;id=<?= (int)$me['id'] ?>" class="ck-meal">
                        <span class="ck-meal-icon" aria-hidden="true"><?= $glyph ?></span>
                        <span class="ck-meal-body">
                            <span class="ck-meal-title"><?= e($slot) ?></span>
                            <?php if (!empty($me['summary'])): ?>
                                <span class="ck-meal-sub"><?= e($me['summary']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="ck-meal-status" aria-label="Logged">✓</span>
                    </a>
                <?php else: ?>
                    <a href="?view=entry" class="ck-meal ck-meal--empty">
                        <span class="ck-meal-icon" aria-hidden="true"><?= $glyph ?></span>
                        <span class="ck-meal-body">
                            <span class="ck-meal-title"><?= e($slot) ?></span>
                            <span class="ck-meal-sub">Tap to log</span>
                        </span>
                        <span class="ck-meal-status" aria-label="Not logged">+</span>
                    </a>
                <?php endif; endforeach; ?>
            </div>
        </section>

        <!-- Daily goals -->
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Progress', 'Daily goals',
                $goalCount > 0
                    ? '<span class="ck-count">' . (int)$goalsDone . '/' . (int)$goalCount . '</span>'
                    : ''); ?>
            <?php if (!$goals): ?>
                <?php coaching_empty('🎯', 'No daily goals set',
                    'Your practitioner will set your goals soon.'); ?>
            <?php else: foreach ($goals as $g):
                $current = $todayCheckins[(int)$g['id']] ?? '';
            ?>
                <div class="ck-goal">
                    <div class="ck-goal-title"><?= e($g['title']) ?></div>
                    <form method="post" class="ck-goal-btns">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="checkin">
                        <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
                        <input type="hidden" name="day" value="<?= e($day) ?>">
                        <?php
                        $statuses = [
                            'not_achieved' => ['miss',   'Missed'],
                            'partial'      => ['part',   'Partial'],
                            'achieved'     => ['done',   'Done'],
                            'exceeded'     => ['exceed', 'Exceeded'],
                        ];
                        foreach ($statuses as $value => [$mod, $label]):
                            $on = ($current === $value);
                        ?>
                            <button type="submit" name="status" value="<?= e($value) ?>"
                                    class="ck-gbtn ck-gbtn--<?= e($mod) ?> <?= $on ? 'is-on' : '' ?>"
                                    aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= e($label) ?></button>
                        <?php endforeach; ?>
                    </form>
                </div>
            <?php endforeach; endif; ?>
        </section>

        <!-- Active challenge -->
        <?php if ($activeChallenge): ?>
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Motivation',
                $activeChallenge['kind'] === 'exercise' ? 'Active exercise' : 'Active challenge',
                '<a href="?view=motivation" class="ck-tile-action">Open →</a>'); ?>
            <div class="ck-row">
                <span class="ck-row-glyph" aria-hidden="true"><?= $activeChallenge['kind'] === 'exercise' ? '💪' : '🎯' ?></span>
                <div class="ck-row-body">
                    <div class="ck-row-title"><?= e($activeChallenge['title']) ?></div>
                    <?php if (!empty($activeChallenge['description_html'])):
                        $plain = trim(strip_tags((string)$activeChallenge['description_html']));
                    ?>
                        <div class="ck-row-sub">
                            <?= e(mb_substr($plain, 0, 140)) ?><?= mb_strlen($plain) > 140 ? '…' : '' ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Chat preview -->
        <?php if ($latestMsg): ?>
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('From your practitioner', 'Latest message',
                $unreadFromCoach > 0
                    ? '<span class="ck-badge">' . (int)$unreadFromCoach . ' new</span>'
                    : '<a href="?view=chat" class="ck-tile-action">Open →</a>'); ?>
            <a href="?view=chat" class="ck-chat-preview">
                <span class="ck-chat-avatar" aria-hidden="true">C</span>
                <span class="ck-row-body">
                    <span class="ck-chat-name">Your practitioner</span>
                    <span class="ck-chat-msg"><?= !empty($latestMsg['body']) ? e($latestMsg['body']) : '📷 Photo' ?></span>
                </span>
            </a>
        </section>
        <?php endif; ?>

        <!-- Activity -->
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Movement', 'Physical activity'); ?>
            <?php foreach ($todayActivity as $a): ?>
                <div class="ck-row">
                    <span class="ck-row-glyph" aria-hidden="true">🚶</span>
                    <div class="ck-row-body">
                        <div class="ck-row-title"><?= e($a['kind']) ?></div>
                        <?php if (!empty($a['duration_min'])): ?>
                            <div class="ck-row-sub"><?= (int)$a['duration_min'] ?> min</div>
                        <?php endif; ?>
                    </div>
                    <form method="post" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="delete_activity">
                        <input type="hidden" name="activity_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="ck-icon-btn"
                                aria-label="<?= e('Remove ' . $a['kind']) ?>">×</button>
                    </form>
                </div>
            <?php endforeach; ?>
            <form method="post" class="ck-inline-form" style="margin-top:<?= $todayActivity ? '12px' : '0' ?>;">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="add_activity">
                <input type="hidden" name="day" value="<?= e($day) ?>">
                <input type="text" name="kind" required class="ck-input"
                       placeholder="Walk, yoga, cardio…" aria-label="Activity type">
                <input type="number" name="duration_min" class="ck-input" style="flex:0 1 90px;"
                       placeholder="min" aria-label="Duration in minutes">
                <button type="submit" class="ck-btn ck-btn--primary ck-btn--sm">Log</button>
            </form>
        </section>

        <!-- Small wins -->
        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Bonus', 'Small wins'); ?>
            <?php if ($extraActions): ?>
                <?php foreach ($extraActions as $ea): ?>
                    <div class="ck-row">
                        <span class="ck-row-glyph" aria-hidden="true">⭐</span>
                        <div class="ck-row-body">
                            <div class="ck-row-title"><?= e($ea['action_text']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="ck-tile-sub" style="margin-top:0;">
                    Something extra today? Every small win counts.
                </p>
            <?php endif; ?>
            <form method="post" class="ck-inline-form" style="margin-top:12px;">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="extra_action">
                <input type="hidden" name="day" value="<?= e($day) ?>">
                <input type="text" name="action_text" required class="ck-input"
                       placeholder="e.g. Took the stairs at work" aria-label="Small win">
                <button type="submit" class="ck-btn ck-btn--primary ck-btn--sm">Add</button>
            </form>
        </section>

        <!-- Program areas -->
        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Your program', 'Everything else'); ?>
            <div class="ck-navgrid">
                <?php foreach (coaching_program_links() as [$slug, $glyph, $label]): ?>
                    <a href="?view=<?= e($slug) ?>" class="ck-navcard">
                        <span aria-hidden="true"><?= $glyph ?></span>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

    </div>
    <?php
}

function coaching_render_profile(int $cid, ?array $flash): void {
    $p = CoachingAPI::getProfile($cid) ?? [];
    $intolerances = is_array($p['intolerances'] ?? null) ? implode(', ', $p['intolerances']) : '';
    $dietPref     = is_array($p['dietary_preferences'] ?? null) ? implode(', ', $p['dietary_preferences']) : '';
    ?>
    <?php coaching_alert($flash); ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_profile">

        <div class="ck-bento">

            <section class="ck-tile ck-s6">
                <?php coaching_tile_head('About you', 'Identity'); ?>
                <div class="ck-grid-2">
                    <div class="ck-field">
                        <label class="ck-label" for="dob">Date of birth</label>
                        <input class="ck-input" type="date" id="dob" name="dob" value="<?= e($p['dob'] ?? '') ?>">
                    </div>
                    <div class="ck-field">
                        <label class="ck-label" for="gender">Gender</label>
                        <select class="ck-input" id="gender" name="gender">
                            <?php foreach ([
                                ''            => '— pick one —',
                                'female'      => 'Female',
                                'male'        => 'Male',
                                'other'       => 'Other',
                                'undisclosed' => 'Prefer not to say',
                            ] as $v => $lbl): ?>
                                <option value="<?= e($v) ?>" <?= (($p['gender'] ?? '') === $v) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="body_type">Body type</label>
                    <input class="ck-input" type="text" id="body_type" name="body_type" maxlength="80"
                           value="<?= e($p['body_type'] ?? '') ?>"
                           placeholder="Ectomorph / Mesomorph / Endomorph…">
                </div>
            </section>

            <section class="ck-tile ck-s6">
                <?php coaching_tile_head('Measurements', 'Body'); ?>
                <div class="ck-grid-2">
                    <div class="ck-field">
                        <label class="ck-label" for="height_cm">Height (cm)</label>
                        <input class="ck-input" type="number" step="0.1" id="height_cm" name="height_cm"
                               value="<?= e($p['height_cm'] ?? '') ?>">
                    </div>
                    <div class="ck-field">
                        <label class="ck-label" for="weight_kg">Weight (kg)</label>
                        <input class="ck-input" type="number" step="0.1" id="weight_kg" name="weight_kg"
                               value="<?= e($p['weight_kg'] ?? '') ?>">
                    </div>
                </div>
                <?php if (!empty($p['bmi']) || !empty($p['bmr'])): ?>
                    <div class="ck-grid-auto" style="margin-top:6px;">
                        <div>
                            <div class="ck-stat-value"><?= $p['bmi'] ? e(number_format((float)$p['bmi'], 1)) : '—' ?></div>
                            <div class="ck-stat-label">BMI</div>
                        </div>
                        <div>
                            <div class="ck-stat-value"><?= $p['bmr'] ? (int)$p['bmr'] : '—' ?><small> kcal</small></div>
                            <div class="ck-stat-label">BMR / day</div>
                        </div>
                        <div>
                            <div class="ck-stat-value"><?= $p['tdee'] ? (int)$p['tdee'] : '—' ?><small> kcal</small></div>
                            <div class="ck-stat-label">TDEE / day</div>
                        </div>
                    </div>
                    <p class="ck-hint">Calculated from your height, weight and date of birth.</p>
                <?php endif; ?>
            </section>

            <section class="ck-tile ck-s6">
                <?php coaching_tile_head('Nutrition', 'Diet'); ?>
                <div class="ck-field">
                    <label class="ck-label" for="intolerances_csv">Intolerances</label>
                    <input class="ck-input" type="text" id="intolerances_csv" name="intolerances_csv"
                           value="<?= e($intolerances) ?>" placeholder="gluten, lactose, peanuts…">
                    <div class="ck-hint">Comma-separated.</div>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="dietary_pref_csv">Dietary preferences</label>
                    <input class="ck-input" type="text" id="dietary_pref_csv" name="dietary_pref_csv"
                           value="<?= e($dietPref) ?>" placeholder="vegetarian, low sugar…">
                    <div class="ck-hint">Anything I should know when I plan meals for you.</div>
                </div>
            </section>

            <section class="ck-tile ck-s6">
                <?php coaching_tile_head('Private', 'Medical'); ?>
                <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                    These stay between us. Skip anything you're not ready to share —
                    you can always update it later.
                </p>
                <div class="ck-field">
                    <label class="ck-label" for="pathologies">Pathologies (past / current)</label>
                    <textarea class="ck-input" id="pathologies" name="pathologies" rows="3"><?= e($p['pathologies'] ?? '') ?></textarea>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="ongoing_care">Ongoing care</label>
                    <textarea class="ck-input" id="ongoing_care" name="ongoing_care" rows="3"><?= e($p['ongoing_care'] ?? '') ?></textarea>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="alternative_medicine">Alternative medicine follow-up</label>
                    <textarea class="ck-input" id="alternative_medicine" name="alternative_medicine" rows="2"><?= e($p['alternative_medicine'] ?? '') ?></textarea>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="personal_issues">Personal issues (stress, weight, work…)</label>
                    <textarea class="ck-input" id="personal_issues" name="personal_issues" rows="3"><?= e($p['personal_issues'] ?? '') ?></textarea>
                </div>
            </section>

        </div>

        <div class="ck-savebar">
            <a href="<?= e(SLATE_URL) ?>/customer/logout.php?csrf=<?= e(csrf_token()) ?>"
               class="ck-btn ck-btn--quiet">Sign out</a>
            <div class="ck-savebar-right">
                <button type="submit" class="ck-btn ck-btn--primary">Save profile</button>
            </div>
        </div>
    </form>
    <?php
}

function coaching_render_goals(int $cid, ?array $flash): void {
    $goals = CoachingAPI::listGoals($cid, null, true);
    $day   = date('Y-m-d');
    $tid   = current_tenant_id();

    $checkins = [];
    foreach (Database::rows(
        "SELECT goal_id, status FROM coaching_goal_checkin
          WHERE tenant_id = ? AND customer_id = ? AND day = ?", [$tid, $cid, $day]) as $r) {
        $checkins[(int)$r['goal_id']] = $r['status'];
    }

    $grouped = [];
    foreach ($goals as $g) { $grouped[$g['scope']][] = $g; }

    $dailyGoals = $grouped['daily'] ?? [];
    $dailyDone  = 0;
    foreach ($dailyGoals as $g) {
        if (in_array($checkins[(int)$g['id']] ?? '', ['achieved', 'exceeded'], true)) $dailyDone++;
    }
    $dailyPct = count($dailyGoals) > 0 ? ($dailyDone / count($dailyGoals)) * 100 : 0;

    $scopeLabels = [
        'daily'    => 'Daily goals',
        'weekly'   => 'Weekly goals',
        'monthly'  => 'Monthly goals',
        'general'  => 'General goals',
        'personal' => 'Personal goals',
    ];
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-bento">

        <?php if (!$goals): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_empty('🎯', 'No goals set yet',
                    'Your practitioner will set your daily, weekly and monthly goals here.'); ?>
            </section>
        <?php else: ?>

            <section class="ck-tile ck-s4">
                <?php coaching_tile_head('Today', 'Scorecard'); ?>
                <div class="ck-rings" style="grid-template-columns:1fr;">
                    <?= coaching_ring($dailyPct, '--ck-ring-1', '🎯',
                            (int)$dailyDone . '/' . count($dailyGoals), 'Daily goals') ?>
                </div>
            </section>

            <div class="ck-s8" style="display:grid;gap:16px;">
            <?php foreach ($scopeLabels as $scope => $label):
                if (empty($grouped[$scope])) continue;
                $isDaily = ($scope === 'daily');
            ?>
                <section class="ck-tile">
                    <?php coaching_tile_head(ucfirst($scope), $label); ?>
                    <?php foreach ($grouped[$scope] as $g):
                        $current = $checkins[(int)$g['id']] ?? '';
                    ?>
                        <div class="ck-goal">
                            <div class="ck-goal-title"><?= e($g['title']) ?></div>
                            <?php if (!empty($g['description'])): ?>
                                <div class="ck-goal-desc"><?= e($g['description']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($g['target_count'])): ?>
                                <div class="ck-goal-desc">Target: <?= (int)$g['target_count'] ?>/day</div>
                            <?php endif; ?>

                            <?php if ($isDaily): ?>
                                <form method="post" class="ck-goal-btns">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="checkin">
                                    <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
                                    <input type="hidden" name="day" value="<?= e($day) ?>">
                                    <?php
                                    $statuses = [
                                        'not_achieved' => ['miss',   'Missed'],
                                        'partial'      => ['part',   'Partial'],
                                        'achieved'     => ['done',   'Done'],
                                        'exceeded'     => ['exceed', 'Exceeded'],
                                    ];
                                    foreach ($statuses as $value => [$mod, $lbl]):
                                        $on = ($current === $value);
                                    ?>
                                        <button type="submit" name="status" value="<?= e($value) ?>"
                                                class="ck-gbtn ck-gbtn--<?= e($mod) ?> <?= $on ? 'is-on' : '' ?>"
                                                aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= e($lbl) ?></button>
                                    <?php endforeach; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>
    <?php
}

function coaching_render_diary(int $cid, ?array $flash): void {
    $to   = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-13 days'));
    $entries = CoachingAPI::listDiaryEntries($cid, $from, $to);

    $byDay = [];
    foreach ($entries as $en) { $byDay[$en['day']][] = $en; }

    $mealEmoji = coaching_meal_emoji();
    $emotions  = CoachingAPI::emotions();
    $daysWithEntries = count($byDay);
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-bento">

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Last 14 days', 'Consistency',
                '<a href="?view=entry" class="ck-tile-action">+ New entry</a>'); ?>
            <div class="ck-grid-auto">
                <div>
                    <div class="ck-stat-value"><?= (int)count($entries) ?></div>
                    <div class="ck-stat-label">Entries</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= (int)$daysWithEntries ?><small>/14</small></div>
                    <div class="ck-stat-label">Days logged</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= (int)round(($daysWithEntries / 14) * 100) ?><small>%</small></div>
                    <div class="ck-stat-label">Consistency</div>
                </div>
            </div>
        </section>

        <?php if (!$byDay): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_empty('📖', 'Nothing logged yet',
                    'Meals, snacks and drinks you log show up here. '
                    . '<a href="?view=entry">Log your first meal →</a>'); ?>
            </section>
        <?php else: foreach ($byDay as $day => $items): ?>
            <section class="ck-tile ck-s6">
                <?php coaching_tile_head(date('j M', strtotime($day)), coaching_day_label($day),
                    '<span class="ck-count">' . count($items) . '</span>'); ?>
                <?php foreach ($items as $en): ?>
                    <a href="?view=entry&amp;id=<?= (int)$en['id'] ?>" class="ck-row"
                       style="text-decoration:none;color:inherit;">
                        <span class="ck-row-glyph" aria-hidden="true"><?= $mealEmoji[$en['meal_type']] ?? '🍴' ?></span>
                        <span class="ck-row-body">
                            <span class="ck-row-title" style="text-transform:capitalize;">
                                <?= e($en['meal_type']) ?>
                                <?php if (!empty($en['started_at'])): ?>
                                    · <?= e(substr((string)$en['started_at'], 0, 5)) ?>
                                <?php endif; ?>
                            </span>
                            <span class="ck-row-sub">
                                <?php if (!empty($en['emotion'])): ?>
                                    <?= e($emotions[$en['emotion']] ?? $en['emotion']) ?><?= !empty($en['summary']) ? ' · ' : '' ?>
                                <?php endif; ?>
                                <?= e((string)($en['summary'] ?? '')) ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php endforeach; endif; ?>

    </div>
    <?php
}

function coaching_render_entry(int $cid, ?array $flash): void {
    $entryId = (int)($_GET['id'] ?? 0);
    $entry   = $entryId > 0 ? CoachingAPI::getDiaryEntry($entryId) : null;
    if ($entry && (int)$entry['customer_id'] !== $cid) $entry = null;

    // Emotion preselected from ?emotion=key on the home mood picker.
    $prefEmotion = (string)($_GET['emotion'] ?? '');
    $saved = !empty($_GET['saved']);

    $foods = $entry['foods'] ?? [];
    while (count($foods) < 4) $foods[] = ['name' => '', 'category' => '', 'is_pleasure_food' => 0];

    $categories   = CoachingAPI::foodCategories();
    $emotions     = CoachingAPI::emotions();
    $contexts     = CoachingAPI::contexts();
    $emotionEmoji = coaching_emotion_emoji();
    $mealEmoji    = coaching_meal_emoji();
    $contextEmoji = coaching_context_emoji();

    $mealLabels = [
        'breakfast'=>'Breakfast','lunch'=>'Lunch','dinner'=>'Dinner','snack'=>'Snack',
        'binge'=>'Binge','drink'=>'Drink','other'=>'Other',
    ];

    $curMeal    = (string)($entry['meal_type'] ?? 'other');
    $curEmotion = (string)($entry['emotion'] ?? $prefEmotion);
    $curContext = (string)($entry['context'] ?? '');
    $curHunger  = (int)($entry['hunger_before'] ?? 3);
    $curSatiety = (int)($entry['satiety_after'] ?? 3);
    ?>

    <div style="margin-bottom:14px;">
        <a href="?view=diary" class="ck-btn ck-btn--ghost ck-btn--sm">← All entries</a>
    </div>

    <?php if ($saved): ?>
        <div class="ck-alert ck-alert--success" role="status">
            <span aria-hidden="true">✓</span>
            <span>Saved — your practitioner sees it live.</span>
        </div>
    <?php else: coaching_alert($flash); endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_entry">
        <input type="hidden" name="id" value="<?= (int)($entry['id'] ?? 0) ?>">
        <input type="hidden" name="meal_type"     id="ck-meal-input"    value="<?= e($curMeal) ?>">
        <input type="hidden" name="emotion"       id="ck-emotion-input" value="<?= e($curEmotion) ?>">
        <input type="hidden" name="context"       id="ck-context-input" value="<?= e($curContext) ?>">
        <input type="hidden" name="hunger_before" id="ck-hunger-input"  value="<?= (int)$curHunger ?>">
        <input type="hidden" name="satiety_after" id="ck-satiety-input" value="<?= (int)$curSatiety ?>">

        <div class="ck-bento">

            <!-- When & what kind -->
            <section class="ck-tile ck-s12">
                <?php coaching_tile_head('The moment', 'When & what kind'); ?>
                <div class="ck-grid-auto">
                    <div class="ck-field">
                        <label class="ck-label" for="ck-day">Day</label>
                        <input class="ck-input" type="date" id="ck-day" name="day" required
                               value="<?= e($entry['day'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="ck-field">
                        <label class="ck-label" for="ck-started">Started at</label>
                        <input class="ck-input" type="time" id="ck-started" name="started_at"
                               value="<?= e(substr((string)($entry['started_at'] ?? ''), 0, 5)) ?>">
                    </div>
                    <div class="ck-field">
                        <label class="ck-label" for="ck-duration">Duration (min)</label>
                        <input class="ck-input" type="number" id="ck-duration" name="duration_min"
                               min="0" max="600" value="<?= e((string)($entry['duration_min'] ?? '')) ?>">
                    </div>
                </div>

                <div class="ck-field">
                    <span class="ck-label" id="ck-meal-label">Meal type</span>
                    <div class="ck-chips" id="ck-meal-chips" role="group" aria-labelledby="ck-meal-label">
                        <?php foreach ($mealLabels as $key => $label):
                            $on = ($curMeal === $key);
                        ?>
                            <button type="button" class="ck-chip <?= $on ? 'is-on' : '' ?>"
                                    data-value="<?= e($key) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
                                <span class="ck-chip-glyph" aria-hidden="true"><?= $mealEmoji[$key] ?? '🍴' ?></span>
                                <?= e($label) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ck-field">
                    <label class="ck-label" for="ck-qty">Quantity note</label>
                    <input class="ck-input" type="text" id="ck-qty" name="quantity_note" maxlength="300"
                           value="<?= e((string)($entry['quantity_note'] ?? '')) ?>"
                           placeholder="small plate, medium bowl, one glass…">
                </div>
            </section>

            <!-- Foods -->
            <section class="ck-tile ck-s7">
                <?php coaching_tile_head('Nutrition', 'What you ate'); ?>
                <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                    One item per line. Pick a category so I can build your food-group chart.
                </p>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($foods as $i => $f): ?>
                        <div style="display:grid;grid-template-columns:1.7fr 1fr auto;gap:8px;align-items:center;">
                            <input class="ck-input" type="text" name="food_name[]" maxlength="200"
                                   value="<?= e((string)$f['name']) ?>" placeholder="e.g. green salad"
                                   aria-label="Food item <?= (int)$i + 1 ?>">
                            <select class="ck-input" name="food_category[]"
                                    aria-label="Category for item <?= (int)$i + 1 ?>">
                                <option value="">— category —</option>
                                <?php foreach ($categories as $v => $lbl): ?>
                                    <option value="<?= e($v) ?>" <?= (($f['category'] ?? '') === $v) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="ck-tag" style="cursor:pointer;padding:9px 11px;display:inline-flex;gap:6px;align-items:center;"
                                   title="Pleasure food">
                                <input type="checkbox" name="food_pleasure[<?= (int)$i ?>]" value="1"
                                       <?= !empty($f['is_pleasure_food']) ? 'checked' : '' ?>
                                       aria-label="Item <?= (int)$i + 1 ?> is a pleasure food">
                                <span aria-hidden="true">🍭</span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Feeling -->
            <section class="ck-tile ck-s5">
                <?php coaching_tile_head('Right then', 'How were you feeling?'); ?>

                <div class="ck-field">
                    <span class="ck-label" id="ck-emo-label">Emotion</span>
                    <div class="ck-emoji-grid" id="ck-emotion-chips" role="group" aria-labelledby="ck-emo-label">
                        <?php foreach ($emotionEmoji as $key => $glyph):
                            $on = ($curEmotion === $key);
                        ?>
                            <button type="button" class="ck-emoji <?= $on ? 'is-on' : '' ?>"
                                    data-value="<?= e($key) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
                                <span class="ck-emoji-glyph" aria-hidden="true"><?= $glyph ?></span>
                                <span class="ck-emoji-label"><?= e($emotions[$key] ?? $key) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ck-field">
                    <span class="ck-label ck-scale-label" id="ck-hunger-label">
                        <span>Hunger before</span><span>1 starving · 5 not hungry</span>
                    </span>
                    <div class="ck-dots" id="ck-hunger-dots" role="group" aria-labelledby="ck-hunger-label">
                        <?php for ($h = 1; $h <= 5; $h++):
                            $on = ($curHunger === $h);
                        ?>
                            <button type="button" class="ck-dot <?= $on ? 'is-on' : '' ?>"
                                    data-value="<?= $h ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"
                                    aria-label="Hunger <?= $h ?> of 5"><?= $h ?></button>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="ck-field">
                    <span class="ck-label ck-scale-label" id="ck-satiety-label">
                        <span>Satiety after</span><span>1 hungry · 5 overfull</span>
                    </span>
                    <div class="ck-dots" id="ck-satiety-dots" role="group" aria-labelledby="ck-satiety-label">
                        <?php for ($h = 1; $h <= 5; $h++):
                            $on = ($curSatiety === $h);
                        ?>
                            <button type="button" class="ck-dot <?= $on ? 'is-on' : '' ?>"
                                    data-value="<?= $h ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"
                                    aria-label="Satiety <?= $h ?> of 5"><?= $h ?></button>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="ck-field">
                    <span class="ck-label" id="ck-ctx-label">Where / with whom</span>
                    <div class="ck-chips" id="ck-context-chips" role="group" aria-labelledby="ck-ctx-label">
                        <?php foreach ($contexts as $key => $label):
                            $on = ($curContext === $key);
                        ?>
                            <button type="button" class="ck-chip <?= $on ? 'is-on' : '' ?>"
                                    data-value="<?= e($key) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
                                <span class="ck-chip-glyph" aria-hidden="true"><?= $contextEmoji[$key] ?? '📍' ?></span>
                                <?= e($label) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ck-field">
                    <label class="ck-label" for="ck-emotion-note">Anything to add</label>
                    <input class="ck-input" type="text" id="ck-emotion-note" name="emotion_note" maxlength="500"
                           value="<?= e((string)($entry['emotion_note'] ?? '')) ?>"
                           placeholder="quick note about how you felt">
                </div>
            </section>

            <!-- Photos & notes -->
            <section class="ck-tile ck-s12">
                <?php coaching_tile_head('Extra', 'Photos & notes'); ?>

                <?php if (!empty($entry['photos'])): ?>
                    <div class="ck-photos">
                        <?php foreach ($entry['photos'] as $ph): ?>
                            <div class="ck-photo">
                                <img src="<?= e(SLATE_URL . '/' . ltrim($ph['file_path'], '/')) ?>" alt="">
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete_photo">
                                    <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
                                    <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                                    <button type="submit" class="ck-photo-del" aria-label="Delete this photo">×</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <label class="ck-dropzone" for="ck-photo">
                    <span aria-hidden="true">📷</span>
                    <span>Add a photo</span>
                    <small>up to 10 MB</small>
                </label>
                <input type="file" id="ck-photo" name="photo" accept="image/*" style="display:none;">

                <div class="ck-field" style="margin-top:14px;">
                    <label class="ck-label" for="ck-notes">Notes</label>
                    <textarea class="ck-input" id="ck-notes" name="notes" rows="4"
                              placeholder="Anything else on your mind about this meal…"><?= e((string)($entry['notes'] ?? '')) ?></textarea>
                </div>
            </section>

        </div>

        <div class="ck-savebar">
            <?php if ($entry): ?>
                <button type="button" class="ck-btn ck-btn--danger ck-btn--sm" id="ck-delete-entry">
                    🗑 Delete
                </button>
            <?php else: ?>
                <a href="?view=home" class="ck-btn ck-btn--quiet">Cancel</a>
            <?php endif; ?>
            <div class="ck-savebar-right">
                <?php if ($entry): ?>
                    <a href="?view=home" class="ck-btn ck-btn--ghost ck-btn--sm">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="ck-btn ck-btn--primary">✓ Save entry</button>
            </div>
        </div>
    </form>

    <?php if ($entry): ?>
        <form method="post" id="ck-delete-form" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="delete_entry">
            <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
        </form>
    <?php endif; ?>

    <script>
    (function () {
        // Chip / emoji / dot pickers all follow the same contract: a group of
        // buttons carrying data-value, writing into one hidden input. State
        // lives entirely in the class + aria-pressed, so CSS owns the look.
        function bindGroup(groupId, hiddenId) {
            var group  = document.getElementById(groupId);
            var hidden = document.getElementById(hiddenId);
            if (!group || !hidden) return;

            group.addEventListener('click', function (ev) {
                var btn = ev.target.closest('button[data-value]');
                if (!btn || !group.contains(btn)) return;
                hidden.value = btn.dataset.value;
                group.querySelectorAll('button[data-value]').forEach(function (b) {
                    var on = (b === btn);
                    b.classList.toggle('is-on', on);
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
            });
        }

        bindGroup('ck-meal-chips',    'ck-meal-input');
        bindGroup('ck-context-chips', 'ck-context-input');
        bindGroup('ck-emotion-chips', 'ck-emotion-input');
        bindGroup('ck-hunger-dots',   'ck-hunger-input');
        bindGroup('ck-satiety-dots',  'ck-satiety-input');

        var del = document.getElementById('ck-delete-entry');
        var delForm = document.getElementById('ck-delete-form');
        if (del && delForm) {
            del.addEventListener('click', function () {
                if (confirm('Delete this entry? This cannot be undone.')) delForm.submit();
            });
        }

        // Show the chosen filename so the dropzone confirms the pick.
        var photo = document.getElementById('ck-photo');
        if (photo) {
            photo.addEventListener('change', function () {
                if (!photo.files || !photo.files.length) return;
                var zone = document.querySelector('label[for="ck-photo"] span:nth-of-type(2)');
                if (zone) zone.textContent = photo.files[0].name;
            });
        }
    })();
    </script>
    <?php
}

function coaching_render_charts(int $cid, ?array $flash): void {
    $range = (int)($_GET['range'] ?? 30);
    if (!in_array($range, [7, 14, 30, 90], true)) $range = 30;
    $to   = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-' . ($range - 1) . ' days'));

    $tid = current_tenant_id();
    $meals = (int) Database::value(
        "SELECT COUNT(*) FROM coaching_diary_entry
          WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?",
        [$tid, $cid, $from, $to]);
    $daysLogged = (int) Database::value(
        "SELECT COUNT(DISTINCT day) FROM coaching_diary_entry
          WHERE tenant_id = ? AND customer_id = ? AND day BETWEEN ? AND ?",
        [$tid, $cid, $from, $to]);

    ob_start();
    ?>
    <div class="ck-chips">
        <?php foreach ([7 => '7d', 14 => '14d', 30 => '30d', 90 => '90d'] as $v => $lbl): ?>
            <a href="?view=charts&amp;range=<?= (int)$v ?>"
               class="ck-chip ck-chip--link <?= $range === $v ? 'is-on' : '' ?>"
               <?= $range === $v ? 'aria-current="true"' : '' ?>><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>
    <?php
    $rangeChips = ob_get_clean();
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-bento">

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Insights', 'Last ' . $range . ' days', $rangeChips); ?>
            <div class="ck-grid-auto">
                <div>
                    <div class="ck-stat-value"><?= (int)$meals ?></div>
                    <div class="ck-stat-label">Meals logged</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= (int)$daysLogged ?><small>/<?= (int)$range ?></small></div>
                    <div class="ck-stat-label">Days with entries</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= $range > 0 ? (int)round(($daysLogged / $range) * 100) : 0 ?><small>%</small></div>
                    <div class="ck-stat-label">Consistency</div>
                </div>
            </div>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Hydration', 'Litres per day'); ?>
            <div class="ck-chart"><?= CoachingCharts::hydrationLine($cid, $from, $to) ?></div>
        </section>

        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Nutrition', 'Food groups'); ?>
            <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                Every classified food item, grouped by category.
            </p>
            <div class="ck-chart"><?= CoachingCharts::foodDistributionPie($cid, $from, $to) ?></div>
        </section>

        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Mood', 'Dominant emotions'); ?>
            <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                Emotions logged at meal times.
            </p>
            <div class="ck-chart"><?= CoachingCharts::emotionPie($cid, $from, $to) ?></div>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Pattern', 'Emotion → food choices'); ?>
            <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                What you tend to reach for depending on how you're feeling.
                A longer bar means more entries with that emotion.
            </p>
            <div class="ck-chart"><?= CoachingCharts::emotionFoodCorrelation($cid, $from, $to) ?></div>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Progress', 'Goal check-ins'); ?>
            <p class="ck-tile-sub" style="margin:-8px 0 14px;">
                Daily goal check-ins across the period.
            </p>
            <div class="ck-chart"><?= CoachingCharts::goalProgress($cid, $from, $to) ?></div>
        </section>

    </div>
    <?php
}

function coaching_render_chat(int $cid, ?array $flash): void {
    $threadId = CoachingAPI::ensureThread($cid);
    // Mark all incoming (practitioner-sent) messages as read.
    CoachingAPI::markThreadRead($threadId, 'customer');
    $messages = CoachingAPI::listMessages($threadId, false, 500);
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-bento">
        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Coach', 'Direct line to your practitioner'); ?>

            <div class="ck-thread" id="ck-thread">
                <?php if (!$messages): ?>
                    <?php coaching_empty('💬', 'No messages yet',
                        'Send the first one below — this replaces WhatsApp for the program.'); ?>
                <?php else:
                    $lastDay = '';
                    foreach ($messages as $m):
                        $stamp = $m['sent_at'] ?: $m['created_at'];
                        $day   = date('Y-m-d', strtotime($stamp));
                        if ($day !== $lastDay):
                            $lastDay = $day;
                        ?>
                            <div class="ck-daydiv"><?= e(coaching_day_label($day)) ?></div>
                        <?php endif;
                        $mine = ($m['sender'] === 'customer');
                    ?>
                        <div class="ck-bubble ck-bubble--<?= $mine ? 'me' : 'them' ?>">
                            <?php if (!empty($m['photo_path'])): ?>
                                <a href="<?= e(SLATE_URL . '/' . ltrim($m['photo_path'], '/')) ?>"
                                   target="_blank" rel="noopener">
                                    <img src="<?= e(SLATE_URL . '/' . ltrim($m['photo_path'], '/')) ?>" alt="Shared photo">
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($m['body'])): ?>
                                <div class="ck-bubble-text"><?= e($m['body']) ?></div>
                            <?php endif; ?>
                            <div class="ck-bubble-meta">
                                <span><?= e(date('H:i', strtotime($stamp))) ?></span>
                                <?php if ($mine): ?>
                                    <span class="ck-seen"
                                          aria-label="<?= !empty($m['seen_at']) ? 'Seen' : 'Sent' ?>"
                                          title="<?= !empty($m['seen_at']) ? 'Seen' : 'Sent' ?>"><?= !empty($m['seen_at']) ? '✓✓' : '✓' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                <?php endforeach; endif; ?>
            </div>

            <form method="post" enctype="multipart/form-data" class="ck-composer">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="send_message">
                <textarea class="ck-input" name="body" rows="2"
                          placeholder="Write a message…" aria-label="Message"></textarea>
                <label class="ck-btn ck-btn--ghost" style="cursor:pointer;" title="Attach a photo">
                    <span aria-hidden="true">📷</span>
                    <span class="sr-only">Attach a photo</span>
                    <input type="file" name="photo" accept="image/*" style="display:none;"
                           onchange="this.form.submit();">
                </label>
                <button type="submit" class="ck-btn ck-btn--primary">Send</button>
            </form>
        </section>
    </div>

    <script>
    (function () {
        var thread = document.getElementById('ck-thread');
        if (thread) thread.scrollTop = thread.scrollHeight;
    })();
    </script>
    <?php
}

function coaching_render_structure(int $cid): void {
    $items = CoachingAPI::listMealStructure($cid);
    $slotLabels = [
        'breakfast' => ['🍳', 'Breakfast'],
        'lunch'     => ['🥗', 'Lunch'],
        'dinner'    => ['🍽️', 'Dinner'],
        'snack'     => ['🥨', 'Snacks'],
        'note'      => ['💡', 'Notes'],
    ];
    ?>
    <div class="ck-bento">
        <?php if (!$items): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_empty('🍱', 'Nothing here yet',
                    'Your practitioner hasn&rsquo;t shared a meal structure yet.'); ?>
            </section>
        <?php else:
            $grouped = ['breakfast' => [], 'lunch' => [], 'dinner' => [], 'snack' => [], 'note' => []];
            foreach ($items as $it) { $grouped[$it['slot']][] = $it; }

            foreach ($slotLabels as $slot => [$glyph, $label]):
                if (empty($grouped[$slot])) continue;
        ?>
            <section class="ck-tile ck-s6">
                <?php coaching_tile_head($glyph . ' Structure', $label); ?>
                <?php foreach ($grouped[$slot] as $it): ?>
                    <div class="ck-row" style="align-items:flex-start;">
                        <div class="ck-row-body">
                            <div class="ck-row-title">
                                <?= e($it['title']) ?>
                                <?php foreach ($it['tags'] as $t): ?>
                                    <span class="ck-tag"><?= e($t) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($it['notes_html'])): ?>
                                <div class="ck-prose" style="margin-top:6px;">
                                    <?= strip_tags($it['notes_html'], '<ul><ol><li><strong><em><br><p>') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; endif; ?>
    </div>
    <?php
}

function coaching_render_shopping(int $cid): void {
    $lists = CoachingAPI::listShoppingLists($cid);
    ?>
    <div class="ck-bento">
        <?php if (!$lists): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_empty('🛒', 'Nothing here yet',
                    'Your practitioner hasn&rsquo;t shared a shopping list yet.'); ?>
            </section>
        <?php else: foreach ($lists as $l): ?>
            <section class="ck-tile ck-s6">
                <?php
                ob_start();
                foreach ($l['tags'] as $t) {
                    echo '<span class="ck-tag">' . e($t) . '</span> ';
                }
                $tagsHtml = ob_get_clean();
                coaching_tile_head('Shopping', (string)$l['name'], $tagsHtml);
                ?>
                <?php foreach ($l['sections'] as $sec): ?>
                    <div style="margin-bottom:14px;">
                        <?php if (!empty($sec['heading'])): ?>
                            <div class="ck-label" style="margin-bottom:8px;"><?= e($sec['heading']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($sec['items'])): ?>
                            <ul class="ck-prose" style="margin:0;padding-left:18px;">
                                <?php foreach ($sec['items'] as $it): ?>
                                    <li><?= e($it) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; endif; ?>
    </div>
    <?php
}

function coaching_render_recipes(int $cid, ?array $flash): void {
    $recipes = CoachingAPI::listRecipes($cid);
    ?>
    <?php coaching_alert($flash); ?>

    <?php if (!empty($_GET['shared'])): ?>
        <div class="ck-alert ck-alert--success" role="status">
            <span aria-hidden="true">✓</span>
            <span>Recipe shared. Your practitioner will see it in their submissions inbox.</span>
        </div>
    <?php endif; ?>

    <div class="ck-bento">

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Library', 'Recipes',
                '<span class="ck-count">' . count($recipes) . '</span>'); ?>
            <?php if (!$recipes): ?>
                <?php coaching_empty('🥘', 'No recipes yet',
                    'Your practitioner hasn&rsquo;t shared any yet — but you can share one of yours below.'); ?>
            <?php else: ?>
                <div class="ck-recipes">
                    <?php foreach ($recipes as $r): ?>
                        <a href="?view=recipe&amp;id=<?= (int)$r['id'] ?>" class="ck-recipe">
                            <?php if (!empty($r['photo_path'])): ?>
                                <img class="ck-recipe-img"
                                     src="<?= e(SLATE_URL . '/' . ltrim($r['photo_path'], '/')) ?>" alt="">
                            <?php else: ?>
                                <div class="ck-recipe-ph" aria-hidden="true">🥘</div>
                            <?php endif; ?>
                            <div class="ck-recipe-body">
                                <div class="ck-recipe-title"><?= e($r['title']) ?></div>
                                <div class="ck-recipe-sub">
                                    <?= $r['author'] === 'customer'
                                        ? 'You shared this'
                                        : count($r['ingredients']) . ' ingredients' ?>
                                    <?php if ($r['author'] === 'customer' && !empty($r['notes'])): ?>
                                        · Practitioner commented
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Your turn', 'Share a recipe'); ?>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="share_recipe">
                <div class="ck-grid-2">
                    <div class="ck-field">
                        <label class="ck-label" for="ck-recipe-title">Title</label>
                        <input class="ck-input" type="text" id="ck-recipe-title" name="title"
                               required maxlength="200" placeholder="My favourite quick lunch">
                    </div>
                    <div class="ck-field">
                        <label class="ck-label" for="ck-recipe-photo">Photo</label>
                        <input class="ck-input" type="file" id="ck-recipe-photo" name="photo" accept="image/*">
                    </div>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="ck-ingredients">Ingredients (one per line)</label>
                    <textarea class="ck-input" id="ck-ingredients" name="ingredients_text" rows="5"></textarea>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="ck-instructions">How you make it</label>
                    <textarea class="ck-input" id="ck-instructions" name="instructions_html" rows="5"></textarea>
                </div>
                <div class="ck-field">
                    <label class="ck-label" for="ck-recipe-notes">Personal note</label>
                    <textarea class="ck-input" id="ck-recipe-notes" name="notes" rows="2"
                              placeholder="Anything you want me to know about this one?"></textarea>
                </div>
                <div style="text-align:right;margin-top:14px;">
                    <button type="submit" class="ck-btn ck-btn--primary">Share with my practitioner</button>
                </div>
            </form>
        </section>

    </div>
    <?php
}

function coaching_render_recipe_detail(int $cid): void {
    $rid = (int)($_GET['id'] ?? 0);
    $r   = CoachingAPI::getRecipe($rid, $cid);

    if (!$r) {
        ?>
        <div class="ck-bento">
            <section class="ck-tile ck-s12">
                <?php coaching_empty('🔍', 'Recipe not found',
                    '<a href="?view=recipes">← Back to recipes</a>'); ?>
            </section>
        </div>
        <?php
        return;
    }
    ?>
    <div style="margin-bottom:14px;">
        <a href="?view=recipes" class="ck-btn ck-btn--ghost ck-btn--sm">← All recipes</a>
    </div>

    <div class="ck-bento">

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head(
                $r['author'] === 'customer' ? 'Your submission' : 'From your practitioner',
                (string)$r['title']); ?>
            <?php if (!empty($r['photo_path'])): ?>
                <img class="ck-hero-img" src="<?= e(SLATE_URL . '/' . ltrim($r['photo_path'], '/')) ?>" alt="">
            <?php endif; ?>
            <?php if ($r['author'] === 'customer' && !empty($r['notes'])): ?>
                <div class="ck-alert ck-alert--info" style="margin:14px 0 0;">
                    <span aria-hidden="true">💬</span>
                    <span><strong>Practitioner comment:</strong> <?= e($r['notes']) ?></span>
                </div>
            <?php endif; ?>
        </section>

        <section class="ck-tile ck-s4">
            <?php coaching_tile_head('Recipe', 'Ingredients'); ?>
            <?php if (!$r['ingredients']): ?>
                <p class="ck-tile-sub" style="margin-top:0;">None listed.</p>
            <?php else: ?>
                <ul class="ck-prose" style="margin:0;padding-left:18px;">
                    <?php foreach ($r['ingredients'] as $it): ?>
                        <li><?= e($it) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="ck-tile ck-s8">
            <?php coaching_tile_head('Recipe', 'Instructions'); ?>
            <?php if (!empty($r['instructions_html'])): ?>
                <div class="ck-prose">
                    <?= strip_tags($r['instructions_html'], '<ul><ol><li><strong><em><br><p>') ?>
                </div>
            <?php else: ?>
                <p class="ck-tile-sub" style="margin-top:0;">None yet.</p>
            <?php endif; ?>
            <?php if (!empty($r['video_url'])): ?>
                <p style="margin-top:14px;">
                    <a class="ck-btn ck-btn--ghost ck-btn--sm"
                       href="<?= e($r['video_url']) ?>" target="_blank" rel="noopener">▶ Watch the video</a>
                </p>
            <?php endif; ?>
        </section>

    </div>
    <?php
}

function coaching_render_motivation(int $cid, ?array $flash): void {
    $active = CoachingAPI::listChallenges($cid, true);
    $tid = current_tenant_id();
    $completed = Database::rows(
        "SELECT * FROM coaching_challenge
          WHERE tenant_id = ? AND customer_id = ? AND completed_at IS NOT NULL
          ORDER BY completed_at DESC LIMIT 20", [$tid, $cid]);
    ?>
    <?php coaching_alert($flash); ?>

    <div class="ck-bento">

        <?php if (!$active): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_empty('💪', 'No active challenges',
                    'You&rsquo;re all caught up — your practitioner will send new ones as you go.'); ?>
            </section>
        <?php else: foreach ($active as $ch): ?>
            <section class="ck-tile ck-s6">
                <?php coaching_tile_head(
                    $ch['kind'] === 'exercise' ? '💪 Exercise' : '🎯 Challenge',
                    (string)$ch['title']); ?>

                <div class="ck-tile-sub" style="margin:-8px 0 12px;">
                    From <?= e(date('j M', strtotime($ch['starts_at']))) ?>
                    <?php if (!empty($ch['ends_at'])): ?>
                        to <?= e(date('j M', strtotime($ch['ends_at']))) ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($ch['description_html'])): ?>
                    <div class="ck-prose">
                        <?= strip_tags($ch['description_html'], '<ul><ol><li><strong><em><br><p>') ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($ch['video_url'])): ?>
                    <p style="margin:12px 0 0;">
                        <a class="ck-btn ck-btn--ghost ck-btn--sm"
                           href="<?= e($ch['video_url']) ?>" target="_blank" rel="noopener">▶ Watch the video</a>
                    </p>
                <?php endif; ?>

                <form method="post" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--ck-line);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="complete_challenge">
                    <input type="hidden" name="challenge_id" value="<?= (int)$ch['id'] ?>">
                    <div class="ck-field">
                        <label class="ck-label" for="ck-note-<?= (int)$ch['id'] ?>">How did it go? (optional)</label>
                        <textarea class="ck-input" id="ck-note-<?= (int)$ch['id'] ?>" name="client_note" rows="2"
                                  placeholder="A quick reflection to share with your practitioner"></textarea>
                    </div>
                    <div style="text-align:right;">
                        <button type="submit" class="ck-btn ck-btn--primary ck-btn--sm">✓ Mark done</button>
                    </div>
                </form>
            </section>
        <?php endforeach; endif; ?>

        <?php if ($completed): ?>
            <section class="ck-tile ck-s12">
                <?php coaching_tile_head('History', 'Completed',
                    '<span class="ck-count">' . count($completed) . '</span>'); ?>
                <?php foreach ($completed as $ch): ?>
                    <div class="ck-row" style="align-items:flex-start;">
                        <span class="ck-row-glyph" aria-hidden="true">✅</span>
                        <div class="ck-row-body">
                            <div class="ck-row-title">
                                <?= e($ch['title']) ?>
                                <span class="ck-tag"><?= e(date('j M', strtotime($ch['completed_at']))) ?></span>
                            </div>
                            <?php if (!empty($ch['client_note'])): ?>
                                <div class="ck-row-sub" style="font-style:italic;">
                                    “<?= e($ch['client_note']) ?>”
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

    </div>
    <?php
}

function coaching_render_summary(int $cid): void {
    $s = CoachingAPI::getSummary($cid);

    if (!$s || empty($s['data'])) {
        ?>
        <div class="ck-bento">
            <section class="ck-tile ck-s12">
                <?php coaching_empty('📄', 'Not ready yet',
                    'Your summary is generated near the end of your program.'); ?>
            </section>
        </div>
        <?php
        return;
    }

    $d = $s['data'];
    $emotions = CoachingAPI::emotions();
    ?>
    <div class="ck-bento">

        <section class="ck-tile ck-hero ck-s12">
            <div class="ck-hero-eyebrow">Body &amp; Soul Program</div>
            <h1 class="ck-hero-name">Your summary</h1>
            <p class="ck-hero-sub">
                <?= e(date('j F Y', strtotime($d['period']['start']))) ?>
                → <?= e(date('j F Y', strtotime($d['period']['end']))) ?>
            </p>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('The numbers', 'At a glance'); ?>
            <div class="ck-grid-auto">
                <div>
                    <div class="ck-stat-value"><?= (int)$d['period']['days'] ?></div>
                    <div class="ck-stat-label">Days</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= (int)$d['metrics']['meals_logged'] ?></div>
                    <div class="ck-stat-label">Meals logged</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= (int)$d['metrics']['consistency_pct'] ?><small>%</small></div>
                    <div class="ck-stat-label">Consistency</div>
                </div>
                <div>
                    <div class="ck-stat-value"><?= number_format((float)$d['metrics']['avg_hydration_l'], 1) ?><small>L</small></div>
                    <div class="ck-stat-label">Avg hydration</div>
                </div>
                <?php if (!empty($d['metrics']['dominant_emotion'])): ?>
                    <div>
                        <div class="ck-stat-value" style="font-size:20px;">
                            <?= e($emotions[$d['metrics']['dominant_emotion']] ?? $d['metrics']['dominant_emotion']) ?>
                        </div>
                        <div class="ck-stat-label">Dominant emotion</div>
                    </div>
                <?php endif; ?>
                <?php if ((int)$d['metrics']['challenges_done'] > 0): ?>
                    <div>
                        <div class="ck-stat-value"><?= (int)$d['metrics']['challenges_done'] ?></div>
                        <div class="ck-stat-label">Challenges done</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Wins', 'Successes'); ?>
            <ul class="ck-prose" style="margin:0;padding-left:18px;">
                <?php foreach ($d['successes'] as $line): ?>
                    <li><?= e($line) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="ck-tile ck-s6">
            <?php coaching_tile_head('Next', 'A recommendation'); ?>
            <div class="ck-prose"><?= e($d['recommendation']) ?></div>
        </section>

        <section class="ck-tile ck-s12">
            <?php coaching_tile_head('Personal', 'A word from your practitioner'); ?>
            <blockquote class="ck-quote">“<?= e($d['message']) ?>”</blockquote>
        </section>

    </div>
    <?php
}
