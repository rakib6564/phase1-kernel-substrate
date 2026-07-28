<?php
/**
 * Content Builder — public route handler.
 *
 * Registered on the `public_routes` filter under the 'p' prefix for pages
 * and one prefix per non-page post type. Receives from PublicRouter:
 *   $_GET['_route_prefix']  e.g. 'p' or 'post'
 *   $_GET['_route_path']    e.g. 'about' or 'hello-world'
 *
 * Pages:  /p/<slug>           → type=page
 * Posts:  /<type>/<slug>      → type=<prefix>
 *
 * Draft preview (admin only): append ?preview=1; requires content.view.
 *
 * The full HTML document (header, nav, footer) is produced by Theme.php.
 */

$prefix = (string)($_GET['_route_prefix'] ?? 'p');
$path   = trim((string)($_GET['_route_path'] ?? ''), '/');

$type = ($prefix === 'p') ? 'page' : $prefix;
$slug = $path !== '' ? $path : 'home';

// Only the last path segment is the slug (ignore any nested remainder).
if (str_contains($slug, '/')) {
    $slug = substr($slug, strrpos($slug, '/') + 1);
}

$preview = !empty($_GET['preview']);

$post = ContentBuilderAPI::getPostBySlug($type, $slug);

// Preview mode: allow viewing a draft, but only for logged-in users with
// content.view. Everyone else gets the published-only behaviour.
$canPreview = $preview && class_exists('Auth') && Auth::check() && Auth::can('content.view');

if (!$post || ($post['status'] !== 'published' && !$canPreview)) {
    http_response_code(404);
    $headTags = '<title>404 — Not found</title>';
    $bodyHtml = '<main class="cb-page"><h1>404</h1><p>That page could not be found.</p></main>';
    echo Theme::renderPage($headTags, $bodyHtml, null);
    return;
}

$default  = '<title>' . e($post['title'] ?: 'Untitled') . '</title>';
$headTags = Hook::applyFilters('content_head_tags', $default, $post);

// Render mode: 'full_html' outputs the page's raw HTML verbatim (no theme/
// header/footer shell) — for pasting a complete standalone document.
$mode = (string)ContentBuilderAPI::getMeta((int)$post['id'], 'cb_render_mode', 'builder');
if ($mode === 'full_html') {
    // Concatenate the raw content of every 'html' block, output as-is.
    $html = '';
    foreach (($post['layout'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'html') {
            $html .= (string)($block['props']['html'] ?? '');
        }
    }
    if ($post['status'] !== 'published') {
        echo '<div style="background:#fef3c7;color:#92400e;padding:.5rem 1rem;text-align:center;font:14px system-ui">Draft preview — not publicly visible.</div>';
    }
    echo $html;
    return;
}

$banner = ($post['status'] !== 'published')
    ? '<div class="cb-preview-banner">Draft preview — not publicly visible.</div>'
    : '';

$bodyHtml = $banner . '<main class="cb-page">'
          . ContentBuilderAPI::renderLayout($post['layout'])
          . '</main>';

echo Theme::renderPage($headTags, $bodyHtml, $post);
