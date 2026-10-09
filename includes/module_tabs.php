<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }

function render_module_tabs(array $tabs, string $activeKey): void
{
    ?>
    <nav class="module-tabs" aria-label="Module sections">
      <?php foreach ($tabs as $tab):
          $isActive = ($tab['key'] ?? '') === $activeKey;
      ?>
        <a class="module-tab <?= $isActive ? 'active' : '' ?>" href="<?= BASE_URL . ($tab['href'] ?? '#') ?>"<?= isset($tab['target']) ? ' data-scroll-target="' . clean($tab['target']) . '"' : '' ?><?= isset($tab['selectValue']) ? ' data-select-value="' . clean($tab['selectValue']) . '"' : '' ?>><?= clean($tab['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php
}
