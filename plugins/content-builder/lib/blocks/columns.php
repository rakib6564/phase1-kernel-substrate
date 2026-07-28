<?php /** @var array $cols @var callable $renderBlock */
$cols = is_array($cols ?? null) ? $cols : []; ?>
<div class="cb-columns cb-cols-<?= max(1, count($cols)) ?>">
    <?php foreach ($cols as $col): ?>
        <div class="cb-col">
            <?php foreach (($col['blocks'] ?? []) as $b) {
                if (is_array($b)) echo $renderBlock($b);
            } ?>
        </div>
    <?php endforeach; ?>
</div>
