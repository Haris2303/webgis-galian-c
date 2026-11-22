// assets/js/laporan.js — tombol cetak ditaruh di sini, bukan inline, karena CSP melarang script inline.
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
