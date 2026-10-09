/* Accessible, form-compatible custom select enhancement. */
(function () {
  'use strict';

  function getOptions(select) {
    return Array.from(select.options).map(function (option, index) {
      return {
        element: option,
        index: index,
        text: option.textContent.trim(),
        value: option.value,
        disabled: option.disabled
      };
    });
  }

  function initializeSelect(select) {
    if (select.dataset.customSelectBound === 'true' || select.multiple || select.size > 1) return;
    select.dataset.customSelectBound = 'true';

    const wrapper = document.createElement('div');
    wrapper.className = 'ui-select custom-select-wrapper';
    if (select.disabled) wrapper.classList.add('is-disabled');
    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(select);
    select.classList.add('ui-select-native');

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'ui-select-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.disabled = select.disabled;

    const label = document.createElement('span');
    label.className = 'ui-select-label';
    const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    chevron.classList.add('ui-select-chevron');
    chevron.setAttribute('viewBox', '0 0 24 24');
    chevron.setAttribute('aria-hidden', 'true');
    chevron.innerHTML = '<path d="m6 9 6 6 6-6"></path>';
    trigger.append(label, chevron);
    wrapper.appendChild(trigger);

    const menu = document.createElement('ul');
    menu.className = 'ui-select-menu custom-options-menu';
    menu.setAttribute('role', 'listbox');
    menu.id = (select.id || 'ui-select') + '-menu-' + Math.random().toString(36).slice(2, 8);
    trigger.setAttribute('aria-controls', menu.id);
    wrapper.appendChild(menu);

    function close() {
      wrapper.classList.remove('is-open');
      wrapper.classList.remove('open');
      trigger.setAttribute('aria-expanded', 'false');
      if (menu.parentNode === document.body) {
        wrapper.appendChild(menu);
        menu.classList.remove('is-portal');
        menu.style.left = '';
        menu.style.top = '';
        menu.style.minWidth = '';
      }
      menu.querySelectorAll('.is-highlighted').forEach(function (item) {
        item.classList.remove('is-highlighted');
      });
    }

    function positionPortalMenu() {
      if (menu.parentNode !== document.body) return;
      const bounds = trigger.getBoundingClientRect();
      menu.style.left = Math.max(8, bounds.left) + 'px';
      menu.style.top = bounds.bottom + 'px';
      menu.style.minWidth = bounds.width + 'px';
    }

    function open() {
      if (select.disabled) return;
      document.querySelectorAll('.ui-select.is-open, .custom-select-wrapper.open').forEach(function (other) {
        if (other !== wrapper && other.uiSelectClose) other.uiSelectClose();
      });
      wrapper.classList.add('is-open');
      wrapper.classList.add('open');
      trigger.setAttribute('aria-expanded', 'true');
      document.body.appendChild(menu);
      menu.classList.add('is-portal');
      positionPortalMenu();
    }

    wrapper.uiSelectOpen = open;
    wrapper.uiSelectClose = close;

    function sync() {
      const selected = select.options[select.selectedIndex];
      label.textContent = selected ? selected.textContent.trim() : '';
      menu.querySelectorAll('.ui-select-option').forEach(function (item) {
        const isSelected = Number(item.dataset.index) === select.selectedIndex;
        item.classList.toggle('is-selected', isSelected);
        item.setAttribute('aria-selected', isSelected ? 'true' : 'false');
      });
    }

    getOptions(select).forEach(function (option) {
      const item = document.createElement('li');
      item.className = 'ui-select-option' + (option.disabled ? ' is-disabled' : '');
      item.dataset.index = String(option.index);
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', 'false');
      item.textContent = option.text;
      item.addEventListener('mousedown', function (event) { event.preventDefault(); });
      item.addEventListener('click', function (event) {
        event.stopPropagation();
        if (option.disabled) return;
        select.value = option.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        select.dispatchEvent(new Event('input', { bubbles: true }));
        sync();
        close();
        trigger.focus();
      });
      menu.appendChild(item);
    });

    trigger.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { close(); return; }
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        wrapper.classList.contains('is-open') ? close() : open();
      }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (!wrapper.classList.contains('is-open')) open();
        const direction = event.key === 'ArrowDown' ? 1 : -1;
        let index = select.selectedIndex;
        do { index = (index + direction + select.options.length) % select.options.length; } while (select.options[index].disabled && index !== select.selectedIndex);
        select.value = select.options[index].value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        select.dispatchEvent(new Event('input', { bubbles: true }));
        sync();
      }
    });
    select.addEventListener('change', sync);
    window.addEventListener('resize', positionPortalMenu);
    window.addEventListener('scroll', positionPortalMenu, true);
    if (select.form) {
      select.form.addEventListener('reset', function () {
        window.setTimeout(sync, 0);
      });
    }
    sync();
  }

  document.addEventListener('DOMContentLoaded', function () {
    function scanForSelects(root) {
      if (root.matches && root.matches('select:not([data-native-select="true"])')) initializeSelect(root);
      if (root.querySelectorAll) root.querySelectorAll('select:not([data-native-select="true"])').forEach(initializeSelect);
    }

    scanForSelects(document);
    const observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        mutation.addedNodes.forEach(scanForSelects);
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });

    document.addEventListener('click', function (event) {
      const trigger = event.target.closest('.ui-select-trigger');
      if (trigger) {
        event.preventDefault();
        event.stopPropagation();
        const wrapper = trigger.closest('.ui-select, .custom-select-wrapper');
        if (wrapper && wrapper.uiSelectClose) {
          wrapper.classList.contains('is-open') ? wrapper.uiSelectClose() : wrapper.uiSelectOpen();
        }
        return;
      }

      if (!event.target.closest('.ui-select, .custom-select-wrapper, .custom-options-menu')) document.querySelectorAll('.ui-select.is-open, .custom-select-wrapper.open').forEach(function (wrapper) {
        if (wrapper.uiSelectClose) wrapper.uiSelectClose();
      });
    }, true);
  });
})();
