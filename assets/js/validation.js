/**
 * assets/js/validation.js
 * Client-side validation only. The server re-validates everything —
 * never trust the browser alone for data that ends up in the database.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const loginForm = document.getElementById('loginForm');
    const isLoginPage = loginForm !== null;

    // ---- Bootstrap's standard "needs-validation" pattern ---------------
    document.querySelectorAll('.needs-validation').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        const isValid = form.checkValidity();
        if (!isValid) {
          event.preventDefault();
          event.stopPropagation();
        }
        if (!isLoginPage || form !== loginForm || !isValid) {
          form.classList.add('was-validated');
        }
      }, false);
    });

    // ---- Philippine mobile numbers: 10 digits, grouped 3-3-4 ----------
    function phDigits(value) {
      let digits = String(value == null ? '' : value).replace(/\D+/g, '');
      digits = digits.replace(/^0+/, '');
      if (digits.indexOf('63') === 0) digits = digits.slice(2);
      return digits.slice(0, 10);
    }

    function phFormat(digits) {
      if (digits.length <= 3) return digits;
      if (digits.length <= 6) return digits.slice(0, 3) + ' ' + digits.slice(3);
      return digits.slice(0, 3) + ' ' + digits.slice(3, 6) + ' ' + digits.slice(6);
    }

    function phValidity(input, digits) {
      if (digits === '') {
        input.setCustomValidity(input.required ? 'Please enter a contact number.' : '');
      } else if (digits.charAt(0) !== '9') {
        input.setCustomValidity('A Philippine mobile number starts with 9, e.g. 912 123 1234.');
      } else if (digits.length < 10) {
        input.setCustomValidity('Enter all 10 digits, e.g. 912 123 1234.');
      } else {
        input.setCustomValidity('');
      }
    }

    /** Re-render the field from its digits, keeping the caret in place. */
    function phRender(input, keepCaret) {
      const raw = input.value;
      const digits = phDigits(raw);
      const formatted = phFormat(digits);

      if (keepCaret) {
        const at = input.selectionStart === null ? raw.length : input.selectionStart;
        const before = phDigits(raw.slice(0, at)).length;
        if (formatted !== raw) {
          input.value = formatted;
        }
        const separators = (before > 3 ? 1 : 0) + (before > 6 ? 1 : 0);
        const caret = Math.min(before + separators, formatted.length);
        try { input.setSelectionRange(caret, caret); } catch (e) { /* not a text input */ }
      } else if (formatted !== raw) {
        input.value = formatted;
      }

      phValidity(input, digits);
    }

    function phSetup(input) {
      if (input.dataset.phMaskReady) {
        return;
      }
      input.dataset.phMaskReady = '1';
      input.setAttribute('inputmode', 'tel');
      input.setAttribute('autocomplete', 'tel');
      // Always show the 10-digit example; "+63" lives in a fixed prefix
      // so it never disappears once the user starts typing.
      input.setAttribute('placeholder', '912 123 1234');
      input.removeAttribute('pattern');
      input.setAttribute('maxlength', '12'); // 10 digits + 2 spaces

      if (!input.parentElement.classList.contains('ph-prefix-group')) {
        const group = document.createElement('div');
        group.className = 'input-group ph-prefix-group';
        const prefix = document.createElement('span');
        prefix.className = 'input-group-text ph-prefix';
        prefix.textContent = '+63';
        input.parentNode.insertBefore(group, input);
        group.appendChild(prefix);
        group.appendChild(input);
      }

      phRender(input, false);

      input.addEventListener('input', function () { phRender(input, true); });
      input.addEventListener('blur', function () { phRender(input, false); });
    }

    // Anything explicitly marked, plus any phone/contact-number field —
    // so a form added later is covered without having to remember this.
    document.querySelectorAll(
      'input[data-ph-mobile], input[type="tel"], input[name*="phone"], input[name*="contact_number"], input[name*="contact_no"]'
    ).forEach(function (input) {
      if (input.tagName === 'INPUT' && !input.disabled) {
        phSetup(input);
      }
    });

    // Fields inside a modal are filled in from a button's data-* just
    // before it opens, which writes straight past the mask.
    document.addEventListener('shown.bs.modal', function (e) {
      e.target.querySelectorAll('input[data-ph-mobile], input[name="phone"], input[name*="contact_number"]').forEach(function (input) {
        phSetup(input);
        phRender(input, false);
      });
    });

    // ---- Password confirmation must match, everywhere both fields exist
    const pw = document.getElementById('regPassword') || document.getElementById('userPassword') || document.getElementById('password');
    const confirm = document.getElementById('regConfirmPassword') || document.getElementById('userConfirmPassword') || document.getElementById('confirm_password');
    if (pw && confirm) {
      const checkMatch = function () {
        confirm.setCustomValidity(confirm.value !== pw.value ? 'Passwords do not match.' : '');
      };
      pw.addEventListener('input', checkMatch);
      confirm.addEventListener('input', checkMatch);
    }
  });
})();
