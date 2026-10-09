/* Password validation for non-recovery account forms. */
(function (window, document) {
  'use strict';

  function score(value) {
    return [value.length >= 8, /[a-z]/.test(value), /[A-Z]/.test(value), /[0-9]/.test(value), /[@!#$%^&*(),.?":{}|<>_]/.test(value)].filter(Boolean).length;
  }

  function updatePasswordUi(input) {
    const value = input.value;
    const checks = {
      length: value.length >= 8,
      lower: /[a-z]/.test(value),
      upper: /[A-Z]/.test(value),
      number: /[0-9]/.test(value),
      special: /[@!#$%^&*(),.?":{}|<>_]/.test(value)
    };
    const passed = Object.keys(checks).filter(function (key) { return checks[key]; }).length;

    Object.keys(checks).forEach(function (key) {
      const item = document.getElementById('req-' + key);
      if (!item) return;
      item.classList.toggle('passed', checks[key]);
      const icon = item.querySelector('.req-icon');
      if (icon) {
        icon.classList.remove('bi-circle', 'bi-check-circle-fill');
        icon.classList.add(checks[key] ? 'bi-check-circle-fill' : 'bi-circle');
        icon.classList.toggle('text-success', checks[key]);
      }
    });

    const counter = document.getElementById('requirements-counter');
    if (counter) counter.textContent = passed + '/5 requirements met';

    ['seg1', 'seg2', 'seg3', 'seg4', 'seg5'].forEach(function (id, index) {
      const segment = document.getElementById(id);
      if (segment) segment.style.backgroundColor = index < passed ? '#700000' : '#e2e8f0';
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#password, #regPassword, #userPassword, #new_password, #newPassword, .js-password-strength').forEach(function (input) {
      if (input.dataset.passwordValidationBound === 'true') return;
      input.dataset.passwordValidationBound = 'true';
      input.addEventListener('input', function () {
        updatePasswordUi(input);
      });
      updatePasswordUi(input);
    });
  });

  window.App = window.App || {};
  window.App.Validation = window.App.Validation || {};
  window.App.Validation.passwordScore = score;
})(window, document);
