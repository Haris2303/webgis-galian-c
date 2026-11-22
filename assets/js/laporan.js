/**
 * assets/js/laporan.js — laporan/index.php (T-22, FR-22). Satu-satunya
 * tanggung jawab: tombol "Cetak" memanggil window.print() (spesifikasi
 * T-22) -- di luar berkas .js karena CSP proyek ini (includes/auth.php)
 * TIDAK mengizinkan script inline (tanpa 'unsafe-inline'/nonce di script-src).
 * PDF didapat lewat dialog Print -> "Save as PDF" bawaan browser, bukan
 * library PDF (PRD bagian 3).
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var tombolCetak = document.getElementById('btn-cetak');
    if (!tombolCetak) {
      return;
    }
    tombolCetak.addEventListener('click', function () {
      window.print();
    });
  });
})();
