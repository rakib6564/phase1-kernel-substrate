<?php
/**
 * Small Business Kit — Theme registry.
 *
 * Each theme = a set of CSS variables that re-style every sb-* block.
 * No layout changes; just colors, type, radii, button style. Stored
 * via SBKit::setActiveTheme(), read by SBKit::injectHead() and
 * inlined into every Content Builder page.
 */

class SBKThemes {

    /** Token defaults shared by every theme — themes override what they want. */
    private static function baseWeights(bool $editorial = false): array {
        return $editorial ? [
            '--sb-weight-display'  => '500',
            '--sb-weight-heading'  => '500',
            '--sb-weight-strong'   => '600',
            '--sb-weight-body'     => '400',
            '--sb-weight-nav'      => '500',
            '--sb-weight-btn'      => '600',
            '--sb-nav-transform'   => 'none',
            '--sb-nav-tracking'    => '.01em',
            '--sb-h1-tracking'     => '-.015em',
        ] : [
            '--sb-weight-display'  => '800',
            '--sb-weight-heading'  => '750',
            '--sb-weight-strong'   => '700',
            '--sb-weight-body'     => '400',
            '--sb-weight-nav'      => '700',
            '--sb-weight-btn'      => '700',
            '--sb-nav-transform'   => 'uppercase',
            '--sb-nav-tracking'    => '.08em',
            '--sb-h1-tracking'     => '-.025em',
        ];
    }

    /** Universal heading typeface — self-hosted Jost (geometric, editorial).
     *  Century Gothic is the closest common local fallback while it swaps. */
    const HEAD = "'Jost', 'Century Gothic', ui-rounded, 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif";

    public static function all(): array {
        $bold      = self::baseWeights(false);
        $editorial = self::baseWeights(true);
        $head      = self::HEAD;
        return [
            'marine-pro' => [
                'label' => 'Marine Pro',
                'blurb' => 'Cool cyan + deep navy. Trustworthy, professional.',
                'tokens' => array_merge($bold, [
                    '--sb-accent'      => '#00c6ff',
                    '--sb-accent-2'    => '#0099cc',
                    '--sb-ink'         => '#0b1c2c',
                    '--sb-ink-2'       => '#1a2f44',
                    '--sb-muted'       => '#5a6b7d',
                    '--sb-line'        => 'rgba(11,28,44,.08)',
                    '--sb-surface'     => '#f6f9fc',
                    '--sb-surface-2'   => '#eef4fa',
                    '--sb-page'        => '#ffffff',
                    '--sb-radius'      => '18px',
                    '--sb-radius-lg'   => '24px',
                    '--sb-btn-radius'  => '999px',
                    '--sb-font-head'   => $head,
                    '--sb-font-body'   => '-apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
                ]),
            ],
            'marine-editorial' => [
                'label' => 'Marine Editorial',
                'blurb' => 'Same cyan + navy palette, with clean geometric display type (Jost) and lighter weights.',
                'tokens' => array_merge($editorial, [
                    '--sb-accent'      => '#00c6ff',
                    '--sb-accent-2'    => '#0099cc',
                    '--sb-ink'         => '#0b1c2c',
                    '--sb-ink-2'       => '#1a2f44',
                    '--sb-muted'       => '#5a6b7d',
                    '--sb-line'        => 'rgba(11,28,44,.10)',
                    '--sb-surface'     => '#f7f9fb',
                    '--sb-surface-2'   => '#edf2f7',
                    '--sb-page'        => '#ffffff',
                    '--sb-radius'      => '10px',
                    '--sb-radius-lg'   => '14px',
                    '--sb-btn-radius'  => '6px',
                    '--sb-font-head'   => $head,
                    '--sb-font-body'   => '"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                ]),
            ],
            'modern-minimal' => [
                'label' => 'Modern Minimal',
                'blurb' => 'Crisp black + warm off-white. Editorial, sharp.',
                'tokens' => array_merge($editorial, [
                    '--sb-accent'      => '#111827',
                    '--sb-accent-2'    => '#000000',
                    '--sb-ink'         => '#0a0a0a',
                    '--sb-ink-2'       => '#1f2937',
                    '--sb-muted'       => '#6b7280',
                    '--sb-line'        => 'rgba(0,0,0,.08)',
                    '--sb-surface'     => '#f7f4ee',
                    '--sb-surface-2'   => '#efeae0',
                    '--sb-page'        => '#ffffff',
                    '--sb-radius'      => '8px',
                    '--sb-radius-lg'   => '12px',
                    '--sb-btn-radius'  => '6px',
                    '--sb-font-head'   => $head,
                    '--sb-font-body'   => '"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                ]),
            ],
            'warm-service' => [
                'label' => 'Warm Service',
                'blurb' => 'Terracotta + cream. Friendly, hospitality-leaning.',
                'tokens' => array_merge($editorial, [
                    '--sb-accent'      => '#d3582a',
                    '--sb-accent-2'    => '#a8431e',
                    '--sb-ink'         => '#2a1a12',
                    '--sb-ink-2'       => '#3d2a20',
                    '--sb-muted'       => '#7a665c',
                    '--sb-line'        => 'rgba(42,26,18,.10)',
                    '--sb-surface'     => '#faf3ec',
                    '--sb-surface-2'   => '#f3e6d4',
                    '--sb-page'        => '#fffbf6',
                    '--sb-radius'      => '14px',
                    '--sb-radius-lg'   => '20px',
                    '--sb-btn-radius'  => '999px',
                    '--sb-font-head'   => $head,
                    '--sb-font-body'   => '-apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, sans-serif',
                ]),
            ],
            'editorial-bold' => [
                'label' => 'Editorial Bold',
                'blurb' => 'Deep green + acid yellow accent. Confident, magazine-like.',
                'tokens' => array_merge($bold, [
                    '--sb-accent'      => '#cdfa45',
                    '--sb-accent-2'    => '#b6e02e',
                    '--sb-ink'         => '#0d3024',
                    '--sb-ink-2'       => '#164232',
                    '--sb-muted'       => '#5e7468',
                    '--sb-line'        => 'rgba(13,48,36,.10)',
                    '--sb-surface'     => '#f4f6ee',
                    '--sb-surface-2'   => '#e6ead6',
                    '--sb-page'        => '#ffffff',
                    '--sb-radius'      => '4px',
                    '--sb-radius-lg'   => '6px',
                    '--sb-btn-radius'  => '4px',
                    '--sb-font-head'   => $head,
                    '--sb-font-body'   => '-apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, sans-serif',
                ]),
            ],
        ];
    }

    /** Optional Google-Fonts URL for the active theme (or empty). */
    public static function fontLink(string $slug): string {
        $t = self::get($slug);
        return $t['fontLink'] ?? '';
    }

    public static function get(string $slug): ?array {
        return self::all()[$slug] ?? null;
    }

    /** CSS variable block ":root { --sb-*: ...; }" for the given theme. */
    public static function rootCss(string $slug): string {
        $theme = self::get($slug) ?? self::get('marine-pro');
        $rows = [];
        foreach ($theme['tokens'] as $k => $v) {
            $rows[] = "  $k: $v;";
        }
        return ":root {\n" . implode("\n", $rows) . "\n}";
    }

    /** Picker options for select fields: [{v,l}, ...]. */
    public static function pickerOptions(): array {
        $out = [];
        foreach (self::all() as $slug => $def) {
            $out[] = ['v' => $slug, 'l' => $def['label']];
        }
        return $out;
    }
}
