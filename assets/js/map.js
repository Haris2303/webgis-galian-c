/**
 * assets/js/map.js — WebGIS Galian C Kota Sorong (T-13, FR-17/FR-18)
 * Modul peta inti: initWebGISMap(containerId, options), dipakai peta.php dan
 * (nanti) pratinjau dashboard. Semua konfigurasi masuk lewat objek options
 * (dibaca pemanggil dari data-* attribute, bukan skrip inline -- CSP proyek
 * ini (includes/auth.php) tidak mengizinkan 'unsafe-inline' pada script-src).
 *
 * Popup di sini SENGAJA ringan (properti dari api/lokasi.php saja, bukan
 * api/lokasi_detail.php) -- deep-load detail aktivitas + XSS-hardening penuh
 * per FR-19 adalah scope T-14, bukan T-13 (lihat docs/todolist.md). Popup di
 * bawah tetap dibangun lewat DOM API (textContent), bukan innerHTML dari data
 * server, supaya tidak membuka lubang XSS baru sambil menunggu T-14.
 */
(function (global) {
  'use strict';

  var DEFAULT_CENTER = [-0.876, 131.255]; // PRD bagian 5, FR-17
  var DEFAULT_ZOOM = 12;
  var MAX_ZOOM = 19;
  var SIDEBAR_TRANSITION_MS = 300; // > --transition-base (220ms, style.css) + jeda aman

  function cssVar(name, fallback) {
    try {
      var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
      return value || fallback;
    } catch (e) {
      return fallback;
    }
  }

  // Satu sumber kebenaran warna status: token CSS (assets/css/style.css, T-04)
  // dibaca lewat getComputedStyle, BUKAN hex diduplikasi manual -- kalau
  // token desain berubah, peta ikut berubah tanpa menyentuh berkas ini. Nilai
  // literal di bawah hanya fallback kalau stylesheet belum/tidak termuat.
  var STATUS_CONFIG = {
    berizin: { label: 'Berizin', shape: 'circle', badgeClass: 'badge--berizin', color: cssVar('--status-berizin', '#2E9E4F') },
    tidak_berizin: { label: 'Tidak Berizin', shape: 'square', badgeClass: 'badge--tidak-berizin', color: cssVar('--status-tidak-berizin', '#D64545') },
    proses: { label: 'Proses', shape: 'triangle', badgeClass: 'badge--proses', color: cssVar('--status-proses', '#E5A823') },
  };
  var STATUS_ORDER = ['berizin', 'tidak_berizin', 'proses'];
  var FALLBACK_STATUS = 'proses'; // status tak dikenal dari API -> tampil netral, bukan error keras

  function statusConfig(status) {
    return STATUS_CONFIG[status] || STATUS_CONFIG[FALLBACK_STATUS];
  }

  // --- Ikon SVG per status: bentuk berbeda, bukan cuma warna (aksesibilitas
  // buta warna, spesifikasi T-13 eksplisit). Badge angka bila >1 lokasi
  // menumpuk di koordinat yang sama persis (edge case wajib T-13). ---
  function shapeMarkup(shape, color, count) {
    var badge = count > 1
      ? '<text x="13" y="17" text-anchor="middle" font-size="10" font-weight="700" fill="#fff">' + (count > 9 ? '9+' : count) + '</text>'
      : '';
    var body;
    switch (shape) {
      case 'square':
        body = '<rect x="4" y="4" width="18" height="18" rx="3" fill="' + color + '" stroke="#fff" stroke-width="2"/>';
        break;
      case 'triangle':
        body = '<polygon points="13,3 24,22 2,22" fill="' + color + '" stroke="#fff" stroke-width="2"/>';
        break;
      default:
        body = '<circle cx="13" cy="13" r="10" fill="' + color + '" stroke="#fff" stroke-width="2"/>';
    }
    return '<svg width="26" height="26" viewBox="0 0 26 26" aria-hidden="true" focusable="false">' + body + badge + '</svg>';
  }

  function buildDivIcon(status, count) {
    var cfg = statusConfig(status);
    return L.divIcon({
      className: 'map-marker-icon map-marker-icon--' + status,
      html: shapeMarkup(cfg.shape, cfg.color, count || 1),
      iconSize: [26, 26],
      iconAnchor: [13, 13],
      popupAnchor: [0, -14],
    });
  }

  function buildLegendControl() {
    var LegendControl = L.Control.extend({
      options: { position: 'bottomright' },
      onAdd: function () {
        var container = L.DomUtil.create('div', 'map-legend');
        var title = L.DomUtil.create('strong', 'map-legend__title', container);
        title.textContent = 'Status Izin';
        var list = L.DomUtil.create('ul', 'map-legend__list', container);
        STATUS_ORDER.forEach(function (key) {
          var cfg = STATUS_CONFIG[key];
          var item = L.DomUtil.create('li', 'map-legend__item', list);
          var icon = L.DomUtil.create('span', 'map-legend__icon', item);
          icon.innerHTML = shapeMarkup(cfg.shape, cfg.color, 1);
          var label = L.DomUtil.create('span', 'map-legend__label', item);
          label.textContent = cfg.label;
        });
        L.DomEvent.disableClickPropagation(container);
        return container;
      },
    });
    return new LegendControl();
  }

  function createToast(afterElement) {
    var region = document.createElement('div');
    region.className = 'map-toast-region';
    region.setAttribute('aria-live', 'assertive');
    afterElement.insertAdjacentElement('afterend', region);

    return function showToast(message) {
      var toast = document.createElement('div');
      toast.className = 'map-toast map-toast--error';
      toast.textContent = message;
      region.appendChild(toast);
      window.setTimeout(function () {
        toast.remove();
      }, 6000);
    };
  }

  function createOverlay(container, text, variant) {
    var overlay = document.createElement('div');
    overlay.className = 'map-overlay map-overlay--' + variant;
    var textEl = document.createElement('p');
    textEl.className = 'map-overlay__text';
    textEl.textContent = text;
    overlay.appendChild(textEl);
    container.appendChild(overlay);
    return overlay;
  }

  // Dua marker boleh berbagi koordinat PERSIS SAMA (edge case wajib T-13) --
  // dikelompokkan jadi satu marker dengan badge jumlah, bukan dua ikon yang
  // saling menutupi tanpa penjelasan.
  function groupByCoordinate(features) {
    var groups = {};
    var order = [];
    features.forEach(function (feature) {
      var coords = feature.geometry && feature.geometry.coordinates;
      if (!Array.isArray(coords) || coords.length < 2) {
        return; // geometri tidak valid -- lewati satu fitur, jangan jatuhkan seluruh peta
      }
      var lng = coords[0];
      var lat = coords[1];
      var key = lat.toFixed(6) + ',' + lng.toFixed(6);
      if (!groups[key]) {
        groups[key] = { lat: lat, lng: lng, items: [] };
        order.push(key);
      }
      groups[key].items.push(feature.properties || {});
    });
    return order.map(function (key) { return groups[key]; });
  }

  function buildPopupContent(items) {
    var wrap = document.createElement('div');
    wrap.className = 'map-popup';

    if (items.length > 1) {
      var count = document.createElement('p');
      count.className = 'map-popup__count';
      count.textContent = items.length + ' lokasi di titik ini.';
      wrap.appendChild(count);
    }

    items.forEach(function (p, idx) {
      if (idx > 0) {
        wrap.appendChild(document.createElement('hr')).className = 'map-popup__divider';
      }

      var title = document.createElement('p');
      title.className = 'map-popup__title';
      title.textContent = p.nama_lokasi || '(tanpa nama)';
      wrap.appendChild(title);

      var metaParts = [p.kontraktor, p.material].filter(function (v) { return !!v; });
      if (metaParts.length > 0) {
        var meta = document.createElement('p');
        meta.className = 'map-popup__meta';
        meta.textContent = metaParts.join(' • ');
        wrap.appendChild(meta);
      }

      var cfg = statusConfig(p.status_izin);
      var badge = document.createElement('span');
      badge.className = 'badge ' + cfg.badgeClass;
      badge.textContent = cfg.label;
      wrap.appendChild(badge);

      if (p.aktivitas_terakhir) {
        var tgl = document.createElement('p');
        tgl.className = 'map-popup__activity';
        tgl.textContent = 'Aktivitas terakhir: ' + p.aktivitas_terakhir;
        wrap.appendChild(tgl);
      }
    });

    return wrap;
  }

  function renderMarkers(markerLayer, groups) {
    groups.forEach(function (group) {
      var firstItem = group.items[0] || {};
      var icon = buildDivIcon(firstItem.status_izin, group.items.length);
      var marker = L.marker([group.lat, group.lng], { icon: icon });
      marker.bindPopup(buildPopupContent(group.items));
      marker.addTo(markerLayer);
    });
  }

  function buildAreaLayer(geojsonData, pane) {
    return L.geoJSON(geojsonData, {
      pane: pane,
      style: function (feature) {
        var cfg = statusConfig(feature.properties && feature.properties.status_izin);
        return { color: cfg.color, weight: 2, fillColor: cfg.color, fillOpacity: 0.25 };
      },
    });
  }

  function buildBoundaryLayer(geojsonData, pane) {
    return L.geoJSON(geojsonData, {
      pane: pane,
      interactive: false, // garis referensi saja -- tidak boleh mencuri klik dari marker/area
      style: { color: cssVar('--color-primary', '#1E3A5F'), weight: 2, fill: false, dashArray: '4 4' },
    });
  }

  // invalidateSize wajib dipanggil ulang saat kontainer berganti ukuran --
  // termasuk saat ia baru DITAMPILKAN setelah sebelumnya display:none/hidden
  // (edge case wajib T-13: "peta di dalam elemen tersembunyi tampil abu-abu").
  // ResizeObserver menangkap kedua kasus (resize window & elemen jadi
  // terlihat) tanpa map.js perlu tahu detail implementasi sidebar.
  function observeResize(map, container) {
    var frame = null;
    function invalidate() {
      if (frame) {
        return;
      }
      frame = window.requestAnimationFrame(function () {
        frame = null;
        map.invalidateSize();
      });
    }
    if (typeof ResizeObserver !== 'undefined') {
      new ResizeObserver(invalidate).observe(container);
    } else {
      window.addEventListener('resize', invalidate);
    }
  }

  // Sidebar off-canvas (<768px, lihat style.css #16 & assets/js/ui.js) adalah
  // overlay position:fixed -- TIDAK mengubah ukuran .map-shell, jadi
  // ResizeObserver di atas tidak terpicu olehnya. Spesifikasi T-13 tetap
  // eksplisit meminta invalidateSize saat toggle, jadi didengarkan langsung di
  // sini sebagai jaring pengaman tambahan, ditunda sedikit sampai transisi
  // CSS sidebar selesai supaya ukuran yang dibaca sudah final.
  function watchSidebarToggle(map) {
    var toggleBtn = document.querySelector('[data-sidebar-toggle]');
    if (!toggleBtn) {
      return;
    }
    toggleBtn.addEventListener('click', function () {
      window.setTimeout(function () { map.invalidateSize(); }, SIDEBAR_TRANSITION_MS);
    });
  }

  function fetchJson(url) {
    return fetch(url, { headers: { Accept: 'application/json' } }).then(function (res) {
      if (res.ok) {
        return res.json();
      }
      return res.json().catch(function () { return null; }).then(function (body) {
        var message = body && typeof body.error === 'string' ? body.error : ('Permintaan gagal (HTTP ' + res.status + ').');
        throw new Error(message);
      });
    });
  }

  function fetchStaticGeojson(url) {
    // Berkas statis (bukan endpoint api/), bukan tanggung jawab wajib --
    // gagal/404 diam saja, konsisten dengan assets/js/map-picker.js (T-10).
    return fetch(url)
      .then(function (res) { return res.ok ? res.json() : null; })
      .catch(function () { return null; });
  }

  function initWebGISMap(containerId, options) {
    var container = document.getElementById(containerId);
    if (!container) {
      return null;
    }

    if (typeof L === 'undefined') {
      // Edge case: Leaflet gagal dimuat dari CDN -- konsisten dengan fallback
      // map-picker.js (T-10): jangan biarkan area kosong tanpa penjelasan.
      container.textContent = 'Peta tidak dapat dimuat (Leaflet gagal dimuat dari CDN). Muat ulang halaman atau periksa koneksi internet.';
      container.classList.add('map-shell--fallback');
      return null;
    }

    var opts = options || {};
    if (!opts.lokasiUrl) {
      throw new Error('initWebGISMap: opts.lokasiUrl wajib diisi.');
    }

    var center = opts.center || DEFAULT_CENTER;
    var zoom = opts.zoom || DEFAULT_ZOOM;
    var showLegend = opts.showLegend !== false;
    var showLayerControl = opts.showLayerControl !== false;

    var map = L.map(container, { tap: true }).setView(center, zoom);

    // Pane kustom untuk batas kota & area tambang, keduanya di BAWAH
    // markerPane bawaan Leaflet (z=600, tidak diubah) -- menjamin klik selalu
    // mengenai marker dulu, bukan poligon besar di baliknya (edge case wajib
    // T-13), lewat urutan z-index yang eksplisit, bukan kebetulan default.
    map.createPane('wgBoundaryPane');
    map.getPane('wgBoundaryPane').style.zIndex = 405;
    map.createPane('wgAreaPane');
    map.getPane('wgAreaPane').style.zIndex = 410;

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      maxZoom: MAX_ZOOM,
    }).addTo(map);

    var markerLayer = L.layerGroup().addTo(map);
    var showToast = createToast(container);
    var loadingOverlay = createOverlay(container, 'Memuat data peta...', 'loading');

    observeResize(map, container);
    watchSidebarToggle(map);

    if (showLegend) {
      buildLegendControl().addTo(map);
    }

    var lokasiPromise = fetchJson(opts.lokasiUrl);
    var areaPromise = opts.areaUrl ? fetchJson(opts.areaUrl) : Promise.resolve(null);
    var boundaryPromise = opts.boundaryUrl ? fetchStaticGeojson(opts.boundaryUrl) : Promise.resolve(null);

    Promise.allSettled([lokasiPromise, areaPromise, boundaryPromise]).then(function (results) {
      loadingOverlay.remove();

      var lokasiResult = results[0];
      var areaResult = results[1];
      var boundaryResult = results[2];
      var overlays = {};

      var features = [];
      if (lokasiResult.status === 'fulfilled' && lokasiResult.value && Array.isArray(lokasiResult.value.features)) {
        features = lokasiResult.value.features;
      } else if (lokasiResult.status === 'rejected') {
        showToast('Gagal memuat data lokasi: ' + lokasiResult.reason.message);
      }

      if (lokasiResult.status === 'fulfilled' && features.length === 0) {
        // Edge case wajib T-13: nol lokasi -- peta tetap tampil, bukan halaman kosong.
        createOverlay(container, 'Belum ada data lokasi tambang untuk ditampilkan.', 'empty');
      }

      var groups = groupByCoordinate(features);
      renderMarkers(markerLayer, groups);

      if (opts.areaUrl) {
        if (areaResult.status === 'fulfilled' && areaResult.value) {
          var areaLayer = buildAreaLayer(areaResult.value, 'wgAreaPane').addTo(map);
          overlays['Area Tambang'] = areaLayer;
        } else if (areaResult.status === 'rejected') {
          showToast('Gagal memuat data area tambang: ' + areaResult.reason.message);
        }
      }

      if (opts.boundaryUrl && boundaryResult.status === 'fulfilled' && boundaryResult.value) {
        var boundaryLayer = buildBoundaryLayer(boundaryResult.value, 'wgBoundaryPane').addTo(map);
        overlays['Batas Kota Sorong'] = boundaryLayer;
      }

      if (showLayerControl && Object.keys(overlays).length > 0) {
        L.control.layers(null, overlays, { position: 'topright', collapsed: false }).addTo(map);
      }

      if (groups.length > 0) {
        var bounds = L.latLngBounds(groups.map(function (g) { return [g.lat, g.lng]; }));
        map.fitBounds(bounds, { padding: [32, 32], maxZoom: 16 });
      }
    });

    return { map: map };
  }

  global.initWebGISMap = initWebGISMap;
  global.WEBGIS_STATUS_CONFIG = STATUS_CONFIG;

  document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('peta-mapa');
    if (!container) {
      return;
    }
    initWebGISMap('peta-mapa', {
      lokasiUrl: container.dataset.lokasiUrl,
      areaUrl: container.dataset.areaUrl || null,
      boundaryUrl: container.dataset.boundaryUrl || null,
    });
  });
})(window);
