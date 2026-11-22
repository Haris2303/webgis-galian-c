// assets/js/foto-preview.js — hanya pratinjau di klien; validasi sungguhan tetap di server (includes/upload_foto.php).
(function () {
  'use strict';

  function initPreview(input) {
    var maxFiles = parseInt(input.dataset.maxFiles || '5', 10);
    var existingCount = parseInt(input.dataset.existingCount || '0', 10);
    var preview = document.getElementById(input.dataset.previewTarget || '');
    var countEl = document.getElementById(input.dataset.countTarget || '');

    if (!preview) {
      return;
    }

    var selected = []; // input.files bersifat read-only, jadi seleksi dilacak terpisah di sini

    function render() {
      preview.textContent = '';

      selected.forEach(function (file, idx) {
        var item = document.createElement('li');
        item.className = 'ds-photo-grid__item';

        var img = document.createElement('img');
        img.className = 'ds-photo-grid__thumb';
        img.alt = file.name;
        var url = URL.createObjectURL(file);
        img.src = url;
        img.addEventListener('load', function () {
          URL.revokeObjectURL(url);
        });
        item.appendChild(img);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ds-btn ds-btn--danger ds-btn--sm';
        btn.textContent = 'Batalkan';
        btn.setAttribute('aria-label', 'Batalkan ' + file.name + ' dari daftar unggah');
        btn.addEventListener('click', function () {
          selected.splice(idx, 1);
          syncInput();
          render();
        });
        item.appendChild(btn);

        preview.appendChild(item);
      });

      if (countEl) {
        var total = existingCount + selected.length;
        countEl.textContent = total + ' dari ' + maxFiles + ' foto (' + existingCount + ' tersimpan + ' + selected.length + ' baru dipilih).';
        countEl.classList.toggle('ds-field__error', total > maxFiles);
      }
    }

    function syncInput() {
      // input.files read-only; dibangun ulang lewat DataTransfer untuk menerapkan penghapusan
      if (typeof DataTransfer === 'undefined') {
        return;
      }
      var dt = new DataTransfer();
      selected.forEach(function (file) {
        dt.items.add(file);
      });
      input.files = dt.files;
    }

    input.addEventListener('change', function () {
      selected = Array.prototype.slice.call(input.files);
      render();
    });
  }

  // Drag-and-drop bersifat tambahan; input tetap terlihat dan bisa dioperasikan lewat keyboard
  function initDropzone(input) {
    var zone = input.closest('.ds-dropzone');
    if (!zone) {
      return;
    }

    ['dragenter', 'dragover'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) {
        e.preventDefault();
        zone.classList.add('is-dragover');
      });
    });

    ['dragleave', 'dragend', 'drop'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) {
        e.preventDefault();
        zone.classList.remove('is-dragover');
      });
    });

    zone.addEventListener('drop', function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (files && files.length) {
        input.files = files;
        input.dispatchEvent(new Event('change'));
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-foto-preview]').forEach(function (input) {
      initPreview(input);
      initDropzone(input);
    });
  });
})();
