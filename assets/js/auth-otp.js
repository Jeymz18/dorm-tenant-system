/* Strict two-step password recovery controller. */
(function (window, document) {
  'use strict';

  function jsonRequest(endpoint, payload) {
    return fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(payload)
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data.success) throw new Error(data.message || 'Request failed.');
        return data;
      });
    });
  }

  function showError(id, message) {
    const element = document.getElementById(id);
    if (!element) return;
    element.className = 'alert alert-danger';
    element.textContent = message;
  }

  function syncOtp() {
    const boxes = Array.from(document.querySelectorAll('.otp-box, .otp-input, .otp-input-box, input[name^="otp_digit"], input[name="otp"]'));
    const hidden = document.getElementById('otp_code') || document.getElementById('otp');
    const value = boxes.map(function (box) { return box.value; }).join('');
    if (hidden) hidden.value = value;
    const count = document.getElementById('otpCountText');
    const progress = document.getElementById('otpProgressBar');
    if (count) count.textContent = value.length + '/6';
    if (progress) progress.style.width = (value.length / 6 * 100) + '%';
    document.querySelectorAll('.otp-dots-grid .dot-indicator, .dot-indicator').forEach(function (dot, index) {
      dot.classList.toggle('filled', index < value.length);
      dot.classList.toggle('active', index === value.length && value.length < 6);
    });
    updatePasswordUi();
    const submit = document.getElementById('resetSubmitBtn');
    const password = document.getElementById('newPassword');
    const confirmation = document.getElementById('confirmPassword');
    if (!submit || !hidden || !password || !confirmation) return;
    const validPassword = password.value.length >= 8 && /[A-Z]/.test(password.value) && /[a-z]/.test(password.value) && /\d/.test(password.value) && /[^A-Za-z0-9]/.test(password.value);
    submit.disabled = hidden.value.length !== 6 || !validPassword || password.value !== confirmation.value;
  }

  function updatePasswordUi() {
    const password = document.getElementById('newPassword');
    const confirmation = document.getElementById('confirmPassword');
    if (!password || !confirmation) return;
    const checks = {
      length: password.value.length >= 8,
      lower: /[a-z]/.test(password.value),
      upper: /[A-Z]/.test(password.value),
      number: /\d/.test(password.value),
      special: /[^A-Za-z0-9]/.test(password.value)
    };
    const score = Object.values(checks).filter(Boolean).length;
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
    if (counter) counter.textContent = score + '/5 requirements met';
    ['seg1', 'seg2', 'seg3', 'seg4', 'seg5'].forEach(function (id, index) {
      const segment = document.getElementById(id);
      if (segment) segment.style.backgroundColor = index < score ? '#700000' : '#e2e8f0';
    });
    const otp = document.getElementById('otp_code') || document.getElementById('otp');
    const submit = document.getElementById('resetSubmitBtn');
    if (submit && otp) submit.disabled = otp.value.length !== 6 || score !== 5 || password.value !== confirmation.value;
  }

  function fillOtp(code) {
    const digits = String(code || '').replace(/\D/g, '').slice(0, 6);
    const boxes = Array.from(document.querySelectorAll('.otp-box, .otp-input, .otp-input-box, input[name^="otp_digit"], input[name="otp"]')).slice(0, 6);
    boxes.forEach(function (box, index) {
      box.value = digits[index] || '';
      box.dispatchEvent(new Event('input', { bubbles: true }));
      box.dispatchEvent(new Event('change', { bubbles: true }));
    });
    syncOtp();
  }

  function startResendTimer() {
    const timer = document.getElementById('resendTimer') || document.querySelector('#resend-timer-text, #resend-timer, .timer-text');
    const seconds = document.getElementById('resendSeconds');
    const resendButton = document.getElementById('resendBtn') || document.querySelector('#resend-button, .resend-button');
    const requestButton = document.getElementById('resendCodeBtn') || document.getElementById('requestNewCodeBtn') || document.querySelector('.request-new-code-btn');
    if (!timer) return;

    if (window.__otpResendTimer) clearInterval(window.__otpResendTimer);

    let remaining = 60;
    const setWaitingState = function () {
      timer.style.display = '';
      if (seconds) seconds.textContent = remaining;
      else timer.textContent = 'Resend code in ' + remaining + 's';
      if (resendButton) {
        resendButton.disabled = true;
        resendButton.style.display = 'none';
      }
      if (requestButton) {
        requestButton.disabled = true;
        requestButton.setAttribute('aria-disabled', 'true');
      }
    };

    setWaitingState();
    window.__otpResendTimer = window.setInterval(function () {
      remaining -= 1;
      if (remaining <= 0) {
        window.clearInterval(window.__otpResendTimer);
        window.__otpResendTimer = null;
        timer.textContent = 'Resend code now';
        if (resendButton) {
          resendButton.disabled = false;
          resendButton.style.display = '';
        }
        if (requestButton) {
          requestButton.disabled = false;
          requestButton.removeAttribute('aria-disabled');
          requestButton.textContent = 'Resend code now';
        }
        return;
      }
      if (seconds) seconds.textContent = remaining;
      else timer.textContent = 'Resend code in ' + remaining + 's';
    }, 1000);
  }

  function bindResendRequest() {
    const requestButton = document.getElementById('resendCodeBtn') || document.getElementById('requestNewCodeBtn') || document.querySelector('.request-new-code-btn');
    if (!requestButton) return;

    requestButton.addEventListener('click', function (event) {
      event.preventDefault();
      if (requestButton.disabled || requestButton.getAttribute('aria-disabled') === 'true') return;

      const email = requestButton.dataset.email || '';
      const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
      if (!email) return;

      requestButton.disabled = true;
      const basePath = window.location.pathname.split('/auth/')[0] || '';
      fetch(basePath + '/auth/resend_otp.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({ action: 'resend_otp', email: email, csrf_token: csrfToken }).toString()
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || data.success === false) throw new Error(data.message || 'Unable to resend the code.');
          return data;
        });
      }).then(function (data) {
        startResendTimer();
        window.alert(data.message || 'A new verification code has been sent.');
      }).catch(function (error) {
        requestButton.disabled = false;
        window.alert(error.message || 'Unable to resend the code.');
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    const forgotForm = document.getElementById('forgotPasswordForm');
    if (forgotForm && forgotForm.dataset.endpoint && forgotForm.dataset.redirect) {
      forgotForm.addEventListener('submit', function (event) {
        event.preventDefault();
        const emailInput = forgotForm.querySelector('[name="email"]');
        const email = (emailInput?.value || '').trim().toLowerCase();
        if (!emailInput || !emailInput.checkValidity()) return;
        const button = forgotForm.querySelector('[type="submit"]');
        if (button) {
          button.disabled = true;
          button.textContent = 'Sending...';
        }
        sessionStorage.setItem('reset_email', email);
        jsonRequest(forgotForm.dataset.endpoint || forgotForm.action, { email: email, csrf_token: forgotForm.querySelector('[name="csrf_token"]')?.value || '' })
          .then(function () {
            window.location.assign(forgotForm.dataset.redirect + '?email=' + encodeURIComponent(email));
          })
          .catch(function (error) {
            if (button) {
              button.disabled = false;
              button.textContent = 'Send Verification Code';
            }
            showError('resetRequestStatus', error.message);
          });
      });
    }

    const enterVerificationButton = document.getElementById('enterVerificationCodeBtn');
    if (enterVerificationButton) {
      enterVerificationButton.addEventListener('click', function () {
        const target = new URL(enterVerificationButton.href, window.location.href);
        target.searchParams.set('step', '2');
        enterVerificationButton.href = target.toString();
      });
    }

    const resetForm = document.getElementById('resetForm');
    if (!resetForm) return;
    const resetEmailInput = resetForm.querySelector('[name="email"]');
    const storedResetEmail = sessionStorage.getItem('reset_email');
    if (!storedResetEmail && !resetEmailInput?.value) {
      const successUrl = resetForm.dataset.successUrl || window.location.pathname.replace(/reset_password\.php$/, 'forgot_password.php');
      window.location.replace(successUrl.replace(/\/auth\/login\.php$/, '/auth/forgot_password.php'));
      return;
    }
    if (!storedResetEmail && resetEmailInput?.value) {
      sessionStorage.setItem('reset_email', resetEmailInput.value);
    }
    document.querySelectorAll('.otp-box, .otp-input, .otp-input-box, input[name^="otp_digit"], input[name="otp"]').forEach(function (box, index, boxes) {
      box.addEventListener('input', function () {
        box.value = box.value.replace(/\D/g, '').slice(0, 1);
        boxes.forEach(function (item) { item.classList.remove('is-invalid'); });
        if (box.value && boxes[index + 1]) boxes[index + 1].focus();
        syncOtp();
      });
      box.addEventListener('keydown', function (event) {
        if (event.key === 'Backspace' && !box.value && boxes[index - 1]) boxes[index - 1].focus();
      });
      box.addEventListener('paste', function (event) {
        const digits = (event.clipboardData.getData('text').match(/\d/g) || []).slice(0, boxes.length);
        if (!digits.length) return;
        event.preventDefault();
        fillOtp(digits.join(''));
      });
    });
    document.querySelectorAll('#newPassword, #confirmPassword').forEach(function (input) { input.addEventListener('input', syncOtp); });
    startResendTimer();
    bindResendRequest();
    document.querySelectorAll('.toggle-password, .toggle-password-btn, #eye-icon, .btn-toggle-eye').forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.preventDefault();
        const targetId = button.dataset.target || button.getAttribute('aria-controls');
        const input = targetId ? document.getElementById(targetId) : button.parentElement?.querySelector('input');
        const icon = button.querySelector('i, svg');
        if (!input || !icon) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        if (icon.classList) {
          icon.classList.remove('bi-eye', 'bi-eye-slash');
          icon.classList.add(input.type === 'password' ? 'bi-eye' : 'bi-eye-slash');
        }
      });
    });
    if (!resetForm.dataset.endpoint) return;
    resetForm.addEventListener('submit', function (event) {
      event.preventDefault();
      const payload = {
        email: sessionStorage.getItem('reset_email'),
        otp_code: resetForm.querySelector('[name="otp_code"], [name="otp"]')?.value || '',
        new_password: resetForm.querySelector('[name="new_password"]')?.value || '',
        confirm_password: resetForm.querySelector('[name="confirm_password"]')?.value || '',
        csrf_token: resetForm.querySelector('[name="csrf_token"]')?.value || ''
      };
      jsonRequest(resetForm.dataset.endpoint || resetForm.action, payload).then(function () {
        sessionStorage.removeItem('reset_email');
        if (resetForm.dataset.successUrl) window.location.assign(resetForm.dataset.successUrl);
      }).catch(function (error) {
        document.querySelectorAll('.otp-box, .otp-input, .otp-input-box').forEach(function (box) { box.classList.add('is-invalid'); });
        showError('resetStatus', error.message);
      });
    });
  });
})(window, document);
