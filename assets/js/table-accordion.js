/* Shared Bootstrap collapse behavior for expandable table rows. */
(function () {
  'use strict';

  function bindAccordionRows() {
    document.querySelectorAll('.expanded-detail-row').forEach(function (detailRow) {
      if (detailRow.dataset.accordionBound === 'true') return;
      detailRow.dataset.accordionBound = 'true';

      const mainRow = document.querySelector('[data-bs-target="#' + detailRow.id + '"]');
      if (!mainRow) return;

      detailRow.addEventListener('show.bs.collapse', function () {
        mainRow.classList.add('is-expanded');
        detailRow.classList.add('is-expanded');
        mainRow.setAttribute('aria-expanded', 'true');
      });

      detailRow.addEventListener('hide.bs.collapse', function () {
        mainRow.classList.remove('is-expanded');
        detailRow.classList.remove('is-expanded');
        mainRow.setAttribute('aria-expanded', 'false');
      });
    });
  }

  function stopTableActionPropagation() {
    ['click', 'mousedown', 'focusin'].forEach(function (eventName) {
      document.addEventListener(eventName, function (event) {
        const action = event.target.closest('.no-toggle, form, button, select, input, textarea, a');
        const customSelectAction = event.target.closest('.ui-select-trigger, .custom-select-wrapper, .custom-options-menu');
        if (action && action.closest('table .main-row') && !customSelectAction) event.stopPropagation();
      }, true);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindAccordionRows();
    stopTableActionPropagation();
  });
})();
