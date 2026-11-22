/**
 * assets/js/dashboard.js — dashboard.php (T-20, FR-05).
 * initWebGISMap() (map.js, T-13) butuh kontainer yang SUDAH punya ukuran
 * nyata (edge case wajib T-20: "peta pratinjau terhitung ukuran nol karena
 * berada di kontainer yang belum terlihat") -- di dashboard.php, pratinjau
 * peta ada di kolom kanan grid-2, bisa saja berada di bawah lipatan (fold).
 * IntersectionObserver menunda initWebGISMap() sampai kontainer benar-benar
 * masuk viewport, lalu observer berhenti mengamati (peta hanya perlu
 * di-init SEKALI).
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('dashboard-peta-preview');
    if (!container) {
      return;
    }

    function initPreview() {
      window.initWebGISMap('dashboard-peta-preview', {
        mode: 'preview',
        lokasiUrl: container.dataset.lokasiUrl,
        previewUrl: container.dataset.previewUrl,
        zoom: 11,
      });
    }

    if (typeof IntersectionObserver === 'undefined') {
      // Fallback untuk browser tanpa dukungan (jarang) -- init langsung,
      // lebih baik ukuran nol sesekali daripada peta tidak pernah tampil.
      initPreview();
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          observer.disconnect();
          initPreview();
        }
      });
    });
    observer.observe(container);
  });
})();
