/**
 * assets/js/main.js
 * Small, framework-free interactivity shared by every page.
 */
function formatRelativeTime(dateString) {
  if (!dateString) return 'Just now';

  const past = new Date(dateString).getTime();
  if (Number.isNaN(past)) return 'Just now';

  const diffInSeconds = Math.max(0, Math.floor((Date.now() - past) / 1000));
  if (diffInSeconds < 30) return 'Just now';
  if (diffInSeconds < 60) return diffInSeconds + 's ago';

  const diffInMinutes = Math.floor(diffInSeconds / 60);
  if (diffInMinutes < 60) return diffInMinutes + 'm ago';

  const diffInHours = Math.floor(diffInMinutes / 60);
  if (diffInHours < 24) return diffInHours + 'h ago';

  const diffInDays = Math.floor(diffInHours / 24);
  return diffInDays + 'd ago';
}

function updateRelativeTimes() {
  document.querySelectorAll('.js-relative-time[data-relative-time]').forEach(function (element) {
    element.textContent = formatRelativeTime(element.dataset.relativeTime);
  });
}

document.addEventListener('DOMContentLoaded', function () {
  updateRelativeTimes();
  window.setInterval(updateRelativeTimes, 10000);

  const authCanvas = document.getElementById('auth-bg-canvas');
  if (authCanvas && authCanvas.closest('.auth-page')) {
    const context = authCanvas.getContext('2d');
    let width = authCanvas.width = window.innerWidth;
    let height = authCanvas.height = window.innerHeight;
    const particles = Array.from({ length: 30 }, function () {
      return {
        x: Math.random() * width,
        y: Math.random() * height,
        radius: Math.random() * 3 + 1,
        vx: (Math.random() - .5) * .5,
        vy: (Math.random() - .5) * .5,
        alpha: Math.random() * .5 + .2
      };
    });
    window.addEventListener('resize', function () {
      width = authCanvas.width = window.innerWidth;
      height = authCanvas.height = window.innerHeight;
    });
    function animateAuthBackground() {
      context.clearRect(0, 0, width, height);
      particles.forEach(function (particle) {
        particle.x += particle.vx;
        particle.y += particle.vy;
        if (particle.x < 0) particle.x = width;
        if (particle.x > width) particle.x = 0;
        if (particle.y < 0) particle.y = height;
        if (particle.y > height) particle.y = 0;
        context.beginPath();
        context.arc(particle.x, particle.y, particle.radius, 0, Math.PI * 2);
        context.fillStyle = 'rgba(255, 255, 255, ' + particle.alpha + ')';
        context.fill();
      });
      window.requestAnimationFrame(animateAuthBackground);
    }
    animateAuthBackground();
  }

  function updateChecklistItem(element, isValid) {
    if (!element) return;
    element.classList.toggle('is-met', isValid);
    element.classList.toggle('text-slate-400', !isValid);
    element.classList.toggle('text-emerald-600', isValid);
    element.classList.toggle('fw-semibold', isValid);
    const checkmark = element.querySelector('.checkmark');
    if (checkmark) {
      checkmark.textContent = isValid ? '✓' : '•';
    }
  }

  function setupPasswordStrengthMeter(input) {
    const targetName = input.getAttribute('data-strength-for') || input.id || input.name;
    const container = document.querySelector('[data-strength-for="' + targetName + '"]');
    if (!container) return;

    const value = input.value;
    const hasLength = value.length >= 8;
    const hasUpper = /[A-Z]/.test(value);
    const hasLower = /[a-z]/.test(value);
    const hasNumber = /[0-9]/.test(value);
    const hasSpecial = /[^A-Za-z0-9]/.test(value);
    const score = [hasLength, hasUpper, hasLower, hasNumber, hasSpecial].filter(Boolean).length;

    const levels = [
      { label: 'Very Weak', color: '#ef4444' },
      { label: 'Weak', color: '#f97316' },
      { label: 'Fair', color: '#eab308' },
      { label: 'Good', color: '#3b82f6' },
      { label: 'Very Strong', color: '#10b981' }
    ];

    updateChecklistItem(document.getElementById('req-length'), hasLength);
    updateChecklistItem(document.getElementById('req-upper'), hasUpper);
    updateChecklistItem(document.getElementById('req-lower'), hasLower);
    updateChecklistItem(document.getElementById('req-number'), hasNumber);
    updateChecklistItem(document.getElementById('req-special'), hasSpecial);

    const bars = container.querySelectorAll('.strength-bar');
    const strengthText = container.querySelector('#strength-label, .strength-label');
    const counterText = container.querySelector('#requirements-counter, .strength-count');
    const activeLevel = value.length === 0 ? levels[0] : levels[Math.max(score - 1, 0)] || levels[0];

    if (strengthText) {
      strengthText.textContent = value.length === 0 ? '' : activeLevel.label;
      strengthText.style.color = value.length === 0 ? '' : activeLevel.color;
    }

    if (counterText) {
      counterText.textContent = value.length === 0 ? '0/5 requirements met' : score + '/5 requirements met';
      counterText.style.color = value.length === 0 ? '#64748b' : (score >= 4 ? '#1e8a4c' : '#64748b');
    }

    bars.forEach(function (bar, index) {
      if (value.length === 0) {
        bar.style.backgroundColor = '#e2e8f0';
        bar.style.borderColor = 'rgba(148, 163, 184, 0.15)';
        bar.style.opacity = '0.8';
        return;
      }

      const isActive = index < score;
      bar.style.backgroundColor = isActive ? activeLevel.color : '#e2e8f0';
      bar.style.borderColor = isActive ? activeLevel.color : 'rgba(148, 163, 184, 0.15)';
      bar.style.opacity = isActive ? '1' : '0.8';
    });
  }

  document.querySelectorAll('.js-password-strength').forEach(function (input) {
    setupPasswordStrengthMeter(input);
    input.addEventListener('input', function () {
      setupPasswordStrengthMeter(input);
    });
  });

  const forms = [document.getElementById('registerForm'), document.getElementById('addUserForm')].filter(Boolean);
  forms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      const requiredInputs = form.querySelectorAll('[required]');
      let isValid = true;
      requiredInputs.forEach(function (input) {
        const filled = input.value.trim() !== '' && input.checkValidity();
        input.classList.toggle('is-invalid', !filled);
        if (!filled) isValid = false;
      });

      const passwordInput = document.getElementById('password') || document.getElementById('regPassword') || document.getElementById('userPassword');
      const confirmInput = document.getElementById('confirm_password') || document.getElementById('regConfirmPassword') || document.getElementById('userConfirmPassword');
      if (passwordInput && confirmInput) {
        const value = passwordInput.value;
        const hasLength = value.length >= 8;
        const hasUpper = /[A-Z]/.test(value);
        const hasLower = /[a-z]/.test(value);
        const hasNumber = /[0-9]/.test(value);
        const hasSpecial = /[^A-Za-z0-9]/.test(value);
        const meetsAllRules = hasLength && hasUpper && hasLower && hasNumber && hasSpecial;
        if (!meetsAllRules || value !== confirmInput.value) {
          isValid = false;
        }
      }

      if (!isValid) {
        event.preventDefault();
        const notice = document.getElementById('formErrorNotice');
        if (notice) {
          notice.textContent = 'Please fill in all required details correctly before submitting.';
          notice.classList.remove('d-none');
        }
      }
    });
  });

  document.querySelectorAll('.js-toggle-pwd').forEach(function (button) {
    button.addEventListener('click', function () {
      const input = document.getElementById(button.dataset.target);
      const icon = button.querySelector('i');
      if (!input) return;
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      if (icon) {
        icon.classList.remove('bi-eye', 'bi-eye-slash');
        icon.classList.add(isPassword ? 'bi-eye-slash' : 'bi-eye');
      }
      button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    });
  });

  // ---- Mobile sidebar toggle -----------------------------------------
  const menuToggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');

  if (menuToggle && sidebar && backdrop) {
    menuToggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
      backdrop.classList.toggle('show');
    });
    backdrop.addEventListener('click', function () {
      sidebar.classList.remove('open');
      backdrop.classList.remove('show');
    });
  }

  // ---- Auto-dismiss flash alerts after a few seconds -----------------
  document.querySelectorAll('.alert').forEach(function (alertEl) {
    setTimeout(function () {
      const alert = bootstrap.Alert.getOrCreateInstance(alertEl);
      alert.close();
    }, 6000);
  });

  // ---- Show/hide toggle on every password field ------------------------
  document.querySelectorAll('input[type="password"]').forEach(function (input) {
    if (input.closest('.input-field-wrapper')) return;
    if (input.closest('.input-group') && input.parentElement.querySelector('.toggle-password, .btn-toggle-eye, .pw-toggle, .js-toggle-pwd, .js-toggle-password-btn')) return;
    if (input.parentElement && input.parentElement.querySelector('.toggle-password, .btn-toggle-eye, .pw-toggle, .js-toggle-pwd, .js-toggle-password-btn')) return;
    let wrapper = input.parentElement;
    if (!wrapper.classList.contains('input-icon')) {
      wrapper = document.createElement('div');
      wrapper.className = 'input-icon';
      input.parentNode.insertBefore(wrapper, input);
      wrapper.appendChild(input);
    }

    input.classList.add('has-pw-toggle');

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'pw-toggle js-toggle-password-btn';
    toggle.setAttribute('aria-label', 'Show password');
    const icon = document.createElement('i');
    icon.className = 'bi bi-eye-slash js-password-icon';
    toggle.appendChild(icon);
    toggle.addEventListener('click', function () {
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      icon.classList.remove('bi-eye', 'bi-eye-slash');
      icon.classList.add(showing ? 'bi-eye-slash' : 'bi-eye');
      toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
    wrapper.appendChild(toggle);
  });

  // ---- Demo login quick-fill (login page only) ------------------------
  document.querySelectorAll('.demo-login-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const emailField = document.getElementById('loginEmail');
      const passField = document.getElementById('loginPassword');
      if (emailField && passField) {
        emailField.value = btn.dataset.email;
        passField.value = btn.dataset.password;
      }
    });
  });

  document.querySelectorAll('.module-tabs [data-scroll-target]').forEach(function (tab) {
    tab.addEventListener('click', function (event) {
      const target = document.getElementById(tab.dataset.scrollTarget);
      if (!target) return;

      event.preventDefault();
      const currentTab = document.querySelector('.module-tabs .module-tab.active');
      if (currentTab) currentTab.classList.remove('active');
      tab.classList.add('active');
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });

      const previousTarget = document.querySelector('.anchor-target.anchor-highlight');
      if (previousTarget) {
        previousTarget.classList.remove('anchor-highlight');
        const previousState = previousTarget.querySelector('.selection-state');
        if (previousState) previousState.hidden = true;
      }
      target.classList.add('anchor-highlight');
      const state = target.querySelector('.selection-state');
      if (state) state.hidden = false;
      if (tab.dataset.selectValue) {
        const option = target.querySelector('input[value="' + tab.dataset.selectValue + '"]');
        if (option) option.closest('.type-option').click();
      }
      window.history.replaceState(null, '', tab.getAttribute('href'));
    });
  });

  const initialHash = window.location.hash.slice(1);
  if (initialHash) {
    const initialTab = document.querySelector('.module-tabs [data-scroll-target="' + initialHash + '"]');
    if (initialTab) {
      const currentTab = document.querySelector('.module-tabs .module-tab.active');
      if (currentTab) currentTab.classList.remove('active');
      initialTab.classList.add('active');
    }
  }
});
