<?php
/**
 * Small Business Kit — Public API.
 *
 * Lightweight facade for theme switching and per-page applications.
 * Other plugins / admin UI call SBKitAPI:: never read settings directly.
 */

require_once __DIR__ . '/lib/Themes.php';

class SBKitAPI {

    public const SETTING_KEY = 'small-business-kit.active_theme';

    /** Currently active theme slug (defaults to 'marine-pro'). */
    public static function activeTheme(): string {
        $val = (string)Database::setting(self::SETTING_KEY);
        return $val !== '' ? $val : 'marine-pro';
    }

    public static function setActiveTheme(string $slug): void {
        if (SBKThemes::get($slug)) {
            Database::setSetting(self::SETTING_KEY, $slug);
        }
    }

    public static function themes(): array {
        return SBKThemes::all();
    }

    /**
     * Apply a SBK page template to a Content Builder post.
     * Replaces the layout with the template's blocks, sets render mode
     * to 'builder' (so the user can edit field-by-field).
     */
    public static function applyTemplate(string $templateKey, int $postId, ?int $tenantId = null): bool {
        $tpls = SBKTemplates::all();
        $tpl = $tpls[$templateKey] ?? null;
        if (!$tpl) return false;
        $tenantId = $tenantId ?? current_tenant_id();
        $post = ContentBuilderAPI::getPost($postId, $tenantId);
        if (!$post) return false;
        ContentBuilderAPI::savePost([
            'id'     => $postId,
            'type'   => $post['type'],
            'title'  => $post['title'],
            'slug'   => $post['slug'],
            'status' => $post['status'] === 'trash' ? 'draft' : $post['status'],
            'layout' => $tpl['blocks'],
        ], $tenantId);
        ContentBuilderAPI::setMeta($postId, 'cb_render_mode', 'builder');
        ContentBuilderAPI::setMeta($postId, 'cb_width', 'full');
        return true;
    }

    /**
     * HTML to inject into <head> on every Content Builder page.
     *
     * Inlines everything (tokens + sb.css) — no extra HTTP requests, no
     * dependency on /plugins/* being publicly served. Cached per request.
     */
    public static function headInjection(?array $post = null): string {
        $slug = self::activeTheme();
        $tokens = SBKThemes::rootCss($slug);
        $css = self::inlineCss();

        // Self-hosted editorial display font (Jost variable WOFF2),
        // embedded as a base64 data URL so it ALWAYS loads — no separate
        // HTTP request, no dependency on Apache serving /plugins/* assets,
        // no Cloudflare/ad-blocker interference. ~35KB inline. Cached
        // per-request via the static in fontFaceBlock().
        $fontTags = self::fontFaceBlock($slug);

        $meta = self::metaTags($post);

        return $meta . $fontTags
             . "\n<style id=\"sbk-tokens\">{$tokens}</style>\n"
             . "<style id=\"sbk-css\">{$css}</style>";
    }

    /**
     * @font-face block with the WOFF2 inlined as a base64 data URL.
     *
     * Every theme now uses the self-hosted, geometric editorial display
     * face 'Jost' for headings (see SBKThemes::HEAD), so we inline it on
     * every page unconditionally — no separate HTTP request, no dependency
     * on Apache serving /plugins/* assets, no CDN/ad-blocker interference.
     * Cached per-request — base64 encoding is the slow bit.
     */
    private static function fontFaceBlock(string $themeSlug = ''): string {
        static $cache = null;
        if ($cache !== null) return $cache;

        $woffPath = __DIR__ . '/assets/fonts/jost-latin.woff2';
        if (!is_file($woffPath)) return ($cache = '');

        $bin = @file_get_contents($woffPath);
        if ($bin === false || $bin === '') return ($cache = '');
        $b64 = base64_encode($bin);
        $dataUrl = "data:font/woff2;base64,{$b64}";

        // Variable WOFF2 (weight 400–700 axis). format('woff2') only — all
        // modern browsers support variable fonts this way. ~35KB inline.
        $cache = "\n<style id=\"sbk-fontface\">"
               . "@font-face{font-family:'Jost';font-style:normal;font-weight:400 700;font-display:swap;"
               . "src:url({$dataUrl}) format('woff2');"
               . "}</style>";
        return $cache;
    }

    /** Meta description + favicon + OG tags + hero preload. */
    private static function metaTags(?array $post): string {
        $desc = '';
        if ($post) {
            $desc = trim((string)($post['excerpt'] ?? ''));
            if ($desc === '') {
                foreach (($post['layout'] ?? []) as $b) {
                    $type = $b['type'] ?? '';
                    if (in_array($type, ['sb-hero','sb-page-hero','paragraph','sb-split'], true)) {
                        $candidate = $b['props']['lede'] ?? $b['props']['body'] ?? $b['props']['text'] ?? '';
                        $candidate = trim((string)$candidate);
                        if ($candidate !== '') { $desc = $candidate; break; }
                    }
                }
            }
        }
        if ($desc === '') {
            $desc = trim((string)ContentBuilderAPI::getSiteSetting('tagline', ''));
        }
        $desc = mb_substr(strip_tags($desc), 0, 160);

        $out = '';

        // ── Favicon (Slate core stores it at brand_favicon_path; fall back
        //    to the site logo so something always renders).
        $favicon = self::brandUrl((string)Database::setting('brand_favicon_path'));
        if ($favicon === '') {
            $favicon = self::brandUrl((string)Database::setting('brand_logo_path'));
        }
        if ($favicon === '') {
            $favicon = ContentBuilderAPI::mediaUrl((string)ContentBuilderAPI::getSiteSetting('logo_url', ''));
        }
        if ($favicon !== '') {
            $type = self::imageMime($favicon);
            $typeAttr = $type ? ' type="' . htmlspecialchars($type, ENT_QUOTES) . '"' : '';
            $out .= "\n<link rel=\"icon\"" . $typeAttr . " href=\"" . htmlspecialchars($favicon, ENT_QUOTES) . "\">";
            $out .= "\n<link rel=\"apple-touch-icon\" href=\"" . htmlspecialchars($favicon, ENT_QUOTES) . "\">";
        }

        // ── Meta description
        if ($desc !== '') {
            $out .= "\n<meta name=\"description\" content=\"" . htmlspecialchars($desc, ENT_QUOTES) . "\">";
            $out .= "\n<meta property=\"og:description\" content=\"" . htmlspecialchars($desc, ENT_QUOTES) . "\">";
            $out .= "\n<meta name=\"twitter:description\" content=\"" . htmlspecialchars($desc, ENT_QUOTES) . "\">";
        }

        // ── OG title + type + site + URL
        $siteName = trim((string)ContentBuilderAPI::getSiteSetting('site_name', ''));
        if ($siteName !== '') {
            $out .= "\n<meta property=\"og:site_name\" content=\"" . htmlspecialchars($siteName, ENT_QUOTES) . "\">";
        }
        if ($post) {
            $title = trim((string)($post['title'] ?? ''));
            if ($title !== '') {
                $out .= "\n<meta property=\"og:title\" content=\"" . htmlspecialchars($title, ENT_QUOTES) . "\">";
                $out .= "\n<meta name=\"twitter:title\" content=\"" . htmlspecialchars($title, ENT_QUOTES) . "\">";
            }
            // Prefer the short URL (/slate/<slug>) over CB's default /p/<slug>.
            $type = (string)($post['type'] ?? 'page');
            $slug = (string)($post['slug'] ?? '');
            $url = ($type === 'page' && $slug !== '')
                ? rtrim(SLATE_URL, '/') . '/' . rawurlencode($slug)
                : ContentBuilderAPI::permalink($post);
            $out .= "\n<meta property=\"og:url\" content=\"" . htmlspecialchars($url, ENT_QUOTES) . "\">";
            $out .= "\n<link rel=\"canonical\" href=\"" . htmlspecialchars($url, ENT_QUOTES) . "\">";
        }
        $out .= "\n<meta property=\"og:type\" content=\"website\">";
        $out .= "\n<meta name=\"twitter:card\" content=\"summary_large_image\">";

        // ── OG image: page hero → explicit SBK share image → Slate's
        //    login/landing image → favicon. Per-page heroes win, but every
        //    page always has *something* for social share previews.
        $heroSrc  = self::findHeroImage($post);
        $shareImg = self::brandUrl((string)Database::setting('small-business-kit.og_image'));
        $loginImg = self::brandUrl((string)Database::setting('brand_login_image_path'));
        $ogImage  = $heroSrc !== ''  ? $heroSrc
                  : ($shareImg !== '' ? $shareImg
                  : ($loginImg !== '' ? $loginImg
                  : ($favicon !== ''  ? $favicon : '')));
        if ($ogImage !== '') {
            // Make sure it's absolute — social crawlers won't follow root-relative.
            $abs = self::absoluteUrl($ogImage);
            $out .= "\n<meta property=\"og:image\" content=\"" . htmlspecialchars($abs, ENT_QUOTES) . "\">";
            $out .= "\n<meta name=\"twitter:image\" content=\"" . htmlspecialchars($abs, ENT_QUOTES) . "\">";
        }

        // ── Preload the hero image (LCP win)
        if ($heroSrc !== '') {
            $out .= "\n<link rel=\"preload\" as=\"image\" href=\"" . htmlspecialchars($heroSrc, ENT_QUOTES) . "\" fetchpriority=\"high\">";
        }

        return $out;
    }

    /** Convert a Slate brand path (often root-relative) to a usable URL. */
    private static function brandUrl(string $path): string {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('#^(?:https?:)?//#i', $path)) return $path;
        return rtrim(SLATE_URL, '/') . '/' . ltrim($path, '/');
    }

    /** Turn a media URL into an absolute URL (with scheme + host). */
    private static function absoluteUrl(string $url): string {
        if (preg_match('#^(?:https?:)?//#i', $url)) return $url;
        $base = SLATE_URL;
        $host = parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST);
        if ($url[0] === '/') {
            // Already root-relative — host + path
            return $host . $url;
        }
        return rtrim($base, '/') . '/' . $url;
    }

    private static function imageMime(string $url): string {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        return [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'gif'  => 'image/gif',
        ][$ext] ?? '';
    }

    private static function findHeroImage(?array $post): string {
        if (!$post) return '';
        foreach (($post['layout'] ?? []) as $b) {
            $type = $b['type'] ?? '';
            if (in_array($type, ['sb-hero','sb-page-hero'], true)) {
                $img = (string)($b['props']['image'] ?? '');
                if ($img !== '') return ContentBuilderAPI::mediaUrl($img);
            }
        }
        return '';
    }

    private static function inlineCss(): string {
        $f = __DIR__ . '/assets/css/sb.css';
        return is_file($f) ? (string)file_get_contents($f) : '';
    }
}
