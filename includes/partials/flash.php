<?php
/** @var array $__flashes optionally pre-fetched; falls back to flash_all() */
$__flashes = $__flashes ?? flash_all();
foreach ($__flashes as $__f):
    $__type = $__f['type'] === 'error' ? 'error' : ($__f['type'] === 'success' ? 'success' : 'info');
?>
  <div class="nb-alert nb-alert--<?= e($__type) ?>" role="alert"><?= e($__f['message']) ?></div>
<?php endforeach; ?>
