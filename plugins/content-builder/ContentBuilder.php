<?php
/**
 * Content Builder — Slate plugin bootstrap.
 *
 * Provides pages, posts, custom post types, taxonomies, and a block-based
 * page builder. Designed to be EXTENDED by other plugins via three hooks:
 *
 *   - 'content_register_blocks' (action)  : add blocks to the builder palette
 *   - 'content_edit_sidebar'    (action)  : add fields to the post editor
 *   - 'content_head_tags'       (filter)  : inject tags into the rendered <head>
 *
 * This plugin depends on NOTHING. Forms, Stripe, SEO, etc. depend on it.
 */

class ContentBuilder extends Plugin {

    /** Bump to bust the cached admin stylesheet. */
    public const ASSET_VER = '1.9.5';

    public function boot(): void {
        // Eagerly require API + lib classes (Slate has no autoloader).
        foreach ([
            'ContentBuilderAPI.php',
            'lib/PostType.php',
            'lib/Taxonomy.php',
            'lib/BlockRegistry.php',
            'lib/PatternLibrary.php',
            'lib/Renderer.php',
            'lib/Branding.php',
            'lib/Theme.php',
        ] as $f) {
            $path = $this->dir($f);
            if (file_exists($path)) require_once $path;
        }

        // Tell lib classes where the plugin lives (for block templates etc.)
        ContentBuilderAPI::setPluginDir($this->getDir());
        ContentBuilderAPI::ensureSchema();
        BlockRegistry::registerDefaults($this->dir('lib/blocks'));

        // Let other active plugins register their own blocks.
        Hook::doAction('content_register_blocks', BlockRegistry::class);

        // …and their own premade section patterns (drop-in section library).
        Hook::doAction('content_register_patterns', PatternLibrary::class);

        // Admin chrome.
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget']);

        // Single source of truth for the admin stylesheet — loaded once in
        // the <head> of every admin page instead of a per-page <link> tag.
        Hook::addAction('admin_head', [$this, 'adminHead']);

        // Clean public URLs: /p/<slug> for pages, /<type>/<slug> for posts/CPTs.
        Hook::addFilter('public_routes', [$this, 'addPublicRoutes']);
    }

    /** Expose the plugin base dir so the API/Renderer can resolve files. */
    protected function getDir(): string {
        return $this->dir('');
    }

    /** Emit the admin stylesheet once, in <head>, on every admin page. */
    public function adminHead(): void {
        $href = SLATE_URL . '/plugins/content-builder/admin/assets/builder.css?v=' . self::ASSET_VER;
        echo '<link rel="stylesheet" href="' . e($href) . '">' . "\n";
    }

    public function addAdminNav(array $items): array {
        $order = 200;
        foreach (PostType::all() as $pt) {
            // Map to icons that exist in the core sidebar set. Built-in
            // Pages → folder, Posts → edit-3; custom types → box.
            if ($pt['slug'] === 'page')      $icon = 'folder';
            elseif ($pt['slug'] === 'post')  $icon = 'edit-3';
            else                             $icon = $pt['hierarchical'] ? 'folder' : 'box';
            $items[] = [
                'slug'  => 'content-' . $pt['slug'],
                'label' => $pt['label'],
                'href'  => $this->url('admin/posts.php?type=' . urlencode($pt['slug'])),
                'icon'  => $icon,
                'perm'  => 'content.view',
                'order' => $order++,
            ];
        }
        $items[] = [
            'slug' => 'content-types', 'label' => 'Post Types',
            'href' => $this->url('admin/post-types.php'),
            'icon' => 'box', 'perm' => 'content.manage_types', 'order' => 290,
        ];
        $items[] = [
            'slug' => 'content-tax', 'label' => 'Taxonomies',
            'href' => $this->url('admin/taxonomies.php'),
            'icon' => 'tag', 'perm' => 'content.manage_types', 'order' => 291,
        ];
        $items[] = [
            'slug' => 'content-menus', 'label' => 'Menus',
            'href' => $this->url('admin/menus.php'),
            'icon' => 'list', 'perm' => 'content.edit', 'order' => 292,
        ];
        $items[] = [
            'slug' => 'content-site', 'label' => 'Site Settings',
            'href' => $this->url('admin/site.php'),
            'icon' => 'settings', 'perm' => 'content.manage_types', 'order' => 293,
        ];
        return $items;
    }

    /**
     * Register clean public URL prefixes. Pages get '/p', each non-page
     * post type gets its own slug as a prefix (e.g. '/post', '/product').
     */
    public function addPublicRoutes(array $routes): array {
        $handler = $this->dir('public/router.php');
        $routes['p'] = ['handler' => $handler, 'methods' => ['GET']];
        foreach (PostType::all() as $pt) {
            if ($pt['slug'] === 'page') continue;
            if (!isset($routes[$pt['slug']])) {
                $routes[$pt['slug']] = ['handler' => $handler, 'methods' => ['GET']];
            }
        }
        return $routes;
    }

    public function addDashboardWidget(array $widgets): array {
        $counts = ContentBuilderAPI::countsByType();
        ob_start(); ?>
        <div class="card">
            <div class="card-header"><h2>Content</h2></div>
            <p class="text-sub">
                <?php if (!$counts): ?>
                    No content yet.
                <?php else: foreach ($counts as $type => $n): ?>
                    <strong><?= (int)$n ?></strong> <?= e($type) ?>&nbsp;&nbsp;
                <?php endforeach; endif; ?>
            </p>
            <a href="<?= e($this->url('admin/posts.php?type=page')) ?>"
               class="btn btn-sm mt-2">Open Content &rarr;</a>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }
}
