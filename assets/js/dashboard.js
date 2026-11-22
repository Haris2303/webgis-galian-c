// assets/js/dashboard.js — menunda init peta sampai kontainer pratinjau terlihat, karena Leaflet menghitung ukurannya nol bila diinisialisasi saat masih di luar layar.
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
