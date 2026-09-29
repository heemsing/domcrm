<?php
/**
 * Flash-сообщения (alerts).
 */
defined('CRM_APP') or die('Direct access denied');
foreach (flash_get() as $f):
    $type = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
?>
<div class="alert alert-<?= $type ?> alert-dismissible fade show py-2" role="alert">
  <?= e($f['message']) ?>
  <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
<?php endforeach;
