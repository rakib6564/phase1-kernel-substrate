<?php /** @var string $text @var string $href @var string $style */
$st = ($style ?? 'primary') === 'secondary' ? 'btn-secondary' : 'btn-primary'; ?>
<p class="cb-button">
    <a class="btn <?= $st ?>" href="<?= e($href ?? '#') ?>"><?= e($text ?? 'Button') ?></a>
</p>
