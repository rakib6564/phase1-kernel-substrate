<?php
/**
 * Renderer — turns a saved layout (array of blocks) into HTML.
 *
 * Each block is either:
 *   - a built-in block with a 'tpl' PHP template, or
 *   - a plugin block with a 'render' callback.
 *
 * Nested blocks (columns) get a $renderBlock closure to recurse.
 */
class Renderer {

    public static function render(?array $layout): string {
        if (!$layout) return '';
        $out = '';
        foreach ($layout as $block) {
            if (is_array($block)) $out .= self::renderBlock($block);
        }
        return $out;
    }

    public static function renderBlock(array $block): string {
        $type = $block['type'] ?? '';
        $def  = BlockRegistry::get($type);
        if (!$def) return '';

        $props = $block['props'] ?? [];

        // Plugin-provided block via callback.
        if (!empty($def['render']) && is_callable($def['render'])) {
            try {
                return (string)call_user_func($def['render'], $props, $block);
            } catch (\Throwable $ex) {
                return '<!-- block "' . htmlspecialchars($type) . '" failed to render -->';
            }
        }

        // Built-in block via template file.
        if (!empty($def['tpl']) && is_file($def['tpl'])) {
            $renderBlock = fn(array $b) => self::renderBlock($b);
            ob_start();
            extract($props, EXTR_SKIP);
            include $def['tpl'];
            return ob_get_clean();
        }

        return '';
    }
}
