/**
 * assets/js/foto-preview.js — pratinjau multi-file SEBELUM unggah (T-18,
 * FR-14). Murni kenyamanan klien -- validasi SUNGGUHAN (kode error $_FILES,
 * ukuran, jumlah, finfo MIME, getimagesize, dimensi) tetap 100% di server
 * (includes/upload_foto.php). Pratinjau ini hanya mengurangi kejutan sebelum
 * submit (lihat foto mana yang terpilih, batalkan satu tanpa mengulang semua),
 * BUKAN lapis otorisasi/keamanan apa pun.
 *
 * Berfungsi sebagai <input type="file" multiple> biasa bila JS gagal/nonaktif
 * -- form tetap terkirim dan tervalidasi penuh di server (PRD bagian 3:
 * "tampilan dinamis tanpa reload" adalah peningkatan, bukan prasyarat fungsi).
 */
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

    var selected = []; // State kita sendiri -- input.files bawaan readonly.

    function render() {
      preview.textContent = '';

      selected.forEach(function (file, idx) {
        var item = document.createElement('li');
        item.className = 'photo-grid__item';

        var img = document.createElement('img');
        img.className = 'photo-grid__thumb';
        img.alt = file.name;
        var url = URL.createObjectURL(file);
        img.src = url;
        img.addEventListener('load', function () {
          URL.revokeObjectURL(url);
        });
        item.appendChild(img);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn--danger btn--sm';
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
        countEl.classList.toggle('field__error', total > maxFiles);
      }
    }

    function syncInput() {
      // input.files bersifat read-only -- satu-satunya cara "membatalkan"
      // satu berkas dari pilihan adalah membangun ulang lewat DataTransfer
      // lalu menimpa input.files dengan hasilnya, supaya submit form
      // sungguhan hanya mengirim berkas yang masih ada di `selected`.
      if (typeof DataTransfer === 'undefined') {
        return; // browser sangat lama -- batalkan satu per satu tidak tersedia, submit tetap aman (server tetap validasi semua)
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

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-foto-preview]').forEach(initPreview);
  });
})();
