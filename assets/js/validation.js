// assets/js/validation.js — hanya UX di sisi klien; includes/validator.php tetap otoritas di server.
(function () {
  'use strict';

  function fieldWrapper(input) {
    return input.closest('.field');
  }

  function labelFor(input) {
    var label = input.labels && input.labels[0] ? input.labels[0].textContent : 'Kolom ini';
    return label.replace('*', '').trim();
  }

  function showError(input, message) {
    var wrapper = fieldWrapper(input);
    if (!wrapper) {
      return;
    }
    wrapper.classList.add('field--invalid');

    var errorEl = wrapper.querySelector('.field__error');
    if (!errorEl) {
      errorEl = document.createElement('p');
      errorEl.className = 'field__error';
      wrapper.appendChild(errorEl);
    }
    if (input.id) {
      errorEl.id = input.id + '-error';
      input.setAttribute('aria-describedby', errorEl.id);
    }
    errorEl.textContent = message;
    input.setAttribute('aria-invalid', 'true');
  }

  function clearError(input) {
    var wrapper = fieldWrapper(input);
    if (!wrapper) {
      return;
    }
    wrapper.classList.remove('field--invalid');
    var errorEl = wrapper.querySelector('.field__error');
    if (errorEl) {
      errorEl.remove();
    }
    input.removeAttribute('aria-invalid');
    // hindari aria-describedby menunjuk ke id yang baru saja dihapus di atas
    input.removeAttribute('aria-describedby');
  }

  function messageFor(input) {
    var v = input.validity;
    var label = labelFor(input);

    if (v.valueMissing) return label + ' wajib diisi.';
    if (v.typeMismatch && input.type === 'email') return 'Format email tidak valid.';
    if (v.rangeUnderflow) return label + ' tidak boleh kurang dari ' + input.min + '.';
    if (v.rangeOverflow) return label + ' tidak boleh lebih dari ' + input.max + '.';
    if (v.tooShort) return label + ' minimal ' + input.minLength + ' karakter.';
    if (v.tooLong) return label + ' maksimal ' + input.maxLength + ' karakter.';
    if (v.patternMismatch) return 'Format ' + label.toLowerCase() + ' tidak sesuai.';
    if (v.stepMismatch) return label + ' harus kelipatan ' + input.step + '.';
    return label + ' tidak valid.';
  }

  function validateField(input) {
    if (input.checkValidity()) {
      clearError(input);
      return true;
    }
    showError(input, messageFor(input));
    return false;
  }

  function initForm(form) {
    // input file dikecualikan: clearError() saat blur akan menghapus aria-describedby yang diset foto-preview.js
    var fields = form.querySelectorAll('input:not([type="file"]), select, textarea');

    fields.forEach(function (input) {
      input.addEventListener('blur', function () {
        validateField(input);
      });

      input.addEventListener('input', function () {
        var wrapper = fieldWrapper(input);
        if (wrapper && wrapper.classList.contains('field--invalid') && input.checkValidity()) {
          clearError(input);
        }
      });
    });

    form.addEventListener('submit', function (event) {
      var formValid = true;
      fields.forEach(function (input) {
        if (!validateField(input)) {
          formValid = false;
        }
      });

      if (!formValid) {
        event.preventDefault();
        var firstInvalid = form.querySelector('.field--invalid input, .field--invalid select, .field--invalid textarea');
        if (firstInvalid) {
          firstInvalid.focus();
        }
      }
    });
  }

  // setelah render ulang akibat validasi server gagal, browser tidak mengembalikan fokus; pindahkan ke field invalid pertama
  function focusFirstServerError() {
    var firstInvalid = document.querySelector('.field--invalid input, .field--invalid select, .field--invalid textarea');
    if (firstInvalid) {
      firstInvalid.focus();
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-validate]').forEach(initForm);
    focusFirstServerError();
  });
})();
