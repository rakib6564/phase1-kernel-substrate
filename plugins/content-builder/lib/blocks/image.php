<?php /** @var string $src @var string $alt @var string $width */
if (empty($src)) return;
$wv = $width ?? 'full'; $w = in_array($wv, ['full','wide','normal'], true) ? $wv : 'full'; ?>
<figure class="cb-image cb-image-<?= e($w) ?>">
    <img src="<?= e(ContentBuilderAPI::mediaUrl($src)) ?>" alt="<?= e($alt ?? '') ?>" loading="lazy">
</figure>
