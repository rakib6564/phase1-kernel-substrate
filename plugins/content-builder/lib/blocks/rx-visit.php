<?php
/** rx-visit — closing CTA with address/phone + an editable hours table. */
$tone  = ($tone ?? 'surface') === 'dark' ? 'dark' : 'surface';
$hours = is_array($items ?? null) ? $items : [];
?>
<section class="rx rx-visit rx-tone-<?= $tone ?>">
  <div class="rx-inner">
    <div class="rx-visit-card">
      <div class="rx-visit-copy">
        <?php if (!empty($eyebrow)): ?><span class="rx-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
        <?php if (!empty($heading)): ?><h2 class="rx-h2"><?= e($heading) ?></h2><?php endif; ?>
        <?php if (!empty($text)): ?><p class="rx-head-sub"><?= nl2br(e($text)) ?></p><?php endif; ?>
        <div class="rx-actions">
          <?php if (!empty($btnText)): ?><a class="rx-btn rx-btn-primary" href="<?= e($btnHref ?? '#') ?>"><?= e($btnText) ?></a><?php endif; ?>
          <?php if (!empty($btn2Text)): ?><a class="rx-btn rx-btn-ghost" href="<?= e($btn2Href ?? '#') ?>"><?= e($btn2Text) ?></a><?php endif; ?>
        </div>
        <?php if (!empty($address) || !empty($phone)): ?>
          <p class="rx-visit-meta">
            <?php if (!empty($address)): ?>📍 <?= e($address) ?><?php endif; ?>
            <?php if (!empty($address) && !empty($phone)): ?> &nbsp;·&nbsp; <?php endif; ?>
            <?php if (!empty($phone)): ?>📞 <?= e($phone) ?><?php endif; ?>
          </p>
        <?php endif; ?>
      </div>
      <?php if ($hours): ?>
      <ul class="rx-hours">
        <?php foreach ($hours as $h):
          $hi = !empty($h['highlight']) && $h['highlight'] !== 'false'; ?>
          <li<?= $hi ? ' class="rx-hours-open"' : '' ?>>
            <span><?= e($h['label'] ?? '') ?></span>
            <b><?= e($h['value'] ?? '') ?></b>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</section>
