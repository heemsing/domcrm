<?php
/**
 * Подвал интерфейса CRM.
 */
defined('CRM_APP') or die('Direct access denied');
?>
  </main>
  <footer class="crm-footer px-3 py-2 small text-white-50">
    <?= e(SITE_NAME) ?> · CRM учета рабочих v<?= e(APP_VERSION ?? '1.0.0') ?> · <?= date('d.m.Y H:i') ?>
  </footer>
</div>

<script src="<?= e(url('assets/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
