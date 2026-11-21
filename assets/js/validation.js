/**
 * assets/js/validation.js — WebGIS Galian C Kota Sorong (T-07)
 * HANYA untuk UX (pesan inline instan dari Constraint Validation API bawaan
 * browser). TIDAK PERNAH menggantikan includes/validator.php -- form yang
 * "lolos" di sini tetap divalidasi ulang di server, dan form tetap bisa
 * di-submit (lalu ditolak server) kalau berkas ini gagal dimuat sama sekali.
 * Aktif hanya pada <form data-validate> (opt-in per form).
 */
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
  }

  // Terjemahan pesan ValidityState bawaan browser ke Bahasa Indonesia --
  // menerjemahkan constraint HTML5 yang SUDAH ada di markup (required, min,
  // max, pattern, type=email, dst.), bukan aturan validasi baru.
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
    var fields = form.querySelectorAll('input, select, textarea');

    fields.forEach(function (input) {
      input.addEventListener('blur', function () {
        validateField(input);
      });

      // Saat pengguna mengetik perbaikan, hapus error begitu valid -- tidak
      // menampilkan error baru di sini supaya tidak mengganggu saat mengetik.
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
      // Valid di klien TIDAK berarti valid di server -- submit yang lolos di
      // sini tetap berjalan ke includes/validator.php sebagai otoritas akhir.
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-validate]').forEach(initForm);
  });
})();
