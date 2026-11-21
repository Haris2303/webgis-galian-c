/**
 * assets/js/map.js — WebGIS Galian C Kota Sorong (T-13/T-14, FR-17/18/19)
 * Modul peta inti: initWebGISMap(containerId, options), dipakai peta.php dan
 * (nanti) pratinjau dashboard. Semua konfigurasi masuk lewat objek options
 * (dibaca pemanggil dari data-* attribute, bukan skrip inline -- CSP proyek
 * ini (includes/auth.php) tidak mengizinkan 'unsafe-inline' pada script-src).
 *
 * Popup detail (T-14) diambil lazy saat marker diklik lewat
 * api/lokasi_detail.php, SELALU dirender lewat DOM API (createElement +
 * textContent), TIDAK PERNAH innerHTML dari data server -- satu-satunya
 * innerHTML di berkas ini adalah markup SVG yang kita bangun sendiri
 * (shapeMarkup), bukan nilai dari API.
 */
(function (global) {
  'use strict';

  var DEFAULT_CENTER = [-0.876, 131.255]; // PRD bagian 5, FR-17
  var DEFAULT_ZOOM = 12;
  var MAX_ZOOM = 19;
  var SIDEBAR_TRANSITION_MS = 300; // > --transition-base (220ms, style.css) + jeda aman
  var DETAIL_CACHE_TTL_MS = 60000; // spesifikasi T-14: cache per id 60 detik
  var DEEP_LINK_ZOOM = 16; // spesifikasi T-14

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

  // --- Popup T-14: skeleton -> fetch api/lokasi_detail.php -> konten aman. ---

  function buildSkeleton() {
    var wrap = document.createElement('div');
    wrap.className = 'map-popup';
    for (var i = 0; i < 3; i++) {
      var line = document.createElement('div');
      line.className = 'skeleton skeleton-text';
      wrap.appendChild(line);
    }
    return wrap;
  }

  function buildDetailError(message) {
    var wrap = document.createElement('div');
    wrap.className = 'map-popup map-popup--error';
    var text = document.createElement('p');
    text.textContent = message || 'Gagal memuat detail lokasi.';
    wrap.appendChild(text);
    return wrap;
  }

  // Marker yang menumpuk (>1 lokasi di koordinat sama) menampilkan daftar
  // pilihan dulu -- memakai properti RINGAN yang sudah ada di memori (dari
  // api/lokasi.php), TANPA fetch tambahan -- baru memanggil api/lokasi_detail.php
  // setelah pengguna memilih salah satu (lewat onPick).
  function buildPickerContent(items, onPick) {
    var wrap = document.createElement('div');
    wrap.className = 'map-popup';

    var count = document.createElement('p');
    count.className = 'map-popup__count';
    count.textContent = items.length + ' lokasi di titik ini. Pilih salah satu:';
    wrap.appendChild(count);

    var list = document.createElement('ul');
    list.className = 'map-popup__list';
    items.forEach(function (item) {
      var li = document.createElement('li');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'map-popup__pick-btn';
      btn.textContent = item.nama_lokasi || '(tanpa nama)';
      btn.addEventListener('click', function () {
        onPick(item.id);
      });
      li.appendChild(btn);
      list.appendChild(li);
    });
    wrap.appendChild(list);

    return wrap;
  }

  function buildThumbFallback() {
    var span = document.createElement('span');
    span.className = 'map-popup__thumb map-popup__thumb--fallback';
    span.textContent = 'Foto tidak tersedia';
    return span;
  }

  function copyToClipboard(text, onDone) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(
        function () { onDone(true); },
        function () { onDone(false); }
      );
      return;
    }
    // Fallback untuk konteks non-secure (http://*.test dev lokal) tempat
    // navigator.clipboard tidak tersedia -- document.execCommand memang usang,
    // tapi inilah satu-satunya jalan copy-to-clipboard yang bekerja di sana.
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      onDone(ok);
    } catch (e) {
      onDone(false);
    }
  }

  // Konten detail penuh. SETIAP teks dari server masuk lewat textContent --
  // edge case wajib T-14: nama lokasi berisi "<img src=x onerror=alert(1)>"
  // atau "<script>" harus tampil sebagai TEKS APA ADANYA, tidak pernah
  // dieksekusi, karena tidak ada satu pun innerHTML yang menerima data ini.
  function buildDetailContent(data) {
    var wrap = document.createElement('div');
    wrap.className = 'map-popup';

    var title = document.createElement('p');
    title.className = 'map-popup__title';
    title.textContent = data.nama_lokasi || '(tanpa nama)';
    wrap.appendChild(title);

    var cfg = statusConfig(data.status_izin);
    var badge = document.createElement('span');
    badge.className = 'badge ' + cfg.badgeClass;
    badge.textContent = cfg.label;
    wrap.appendChild(badge);

    var metaParts = [data.kontraktor, data.material].filter(function (v) { return !!v; });
    if (metaParts.length > 0) {
      var meta = document.createElement('p');
      meta.className = 'map-popup__meta';
      meta.textContent = metaParts.join(' • ');
      wrap.appendChild(meta);
    }

    var alamatParts = [data.alamat, data.kelurahan, data.distrik].filter(function (v) { return !!v; });
    if (alamatParts.length > 0) {
      var alamat = document.createElement('p');
      alamat.className = 'map-popup__meta';
      alamat.textContent = alamatParts.join(', ');
      wrap.appendChild(alamat);
    }

    if (data.luas_ha !== null && data.luas_ha !== undefined) {
      var luas = document.createElement('p');
      luas.className = 'map-popup__meta';
      luas.textContent = 'Luas: ' + data.luas_ha + ' ha';
      wrap.appendChild(luas);
    }

    if (data.koordinat) {
      var koordWrap = document.createElement('div');
      koordWrap.className = 'map-popup__koordinat';

      var koordText = document.createElement('span');
      koordText.textContent = data.koordinat.lat.toFixed(6) + ', ' + data.koordinat.lng.toFixed(6);
      koordWrap.appendChild(koordText);

      var copyBtn = document.createElement('button');
      copyBtn.type = 'button';
      copyBtn.className = 'btn-link map-popup__copy-btn';
      copyBtn.textContent = 'Salin koordinat';
      copyBtn.addEventListener('click', function () {
        var original = copyBtn.textContent;
        copyToClipboard(data.koordinat.lat + ', ' + data.koordinat.lng, function (ok) {
          copyBtn.textContent = ok ? 'Tersalin!' : 'Gagal menyalin';
          window.setTimeout(function () { copyBtn.textContent = original; }, 2000);
        });
      });
      koordWrap.appendChild(copyBtn);

      wrap.appendChild(koordWrap);
    }

    // Keputusan T-12 (lihat laporan T-12 & komentar api/lokasi_detail.php):
    // FR-19 vs PRD bagian 10 diambil yang PALING KETAT -- aktivitas_dibatasi
    // ditegakkan server, di sini HANYA ditampilkan apa adanya, bukan diakali.
    if (data.aktivitas_dibatasi) {
      var dibatasi = document.createElement('p');
      dibatasi.className = 'map-popup__activity map-popup__activity--muted';
      dibatasi.textContent = 'Riwayat aktivitas lokasi ini dibatasi untuk kontraktor lain.';
      wrap.appendChild(dibatasi);
    } else if (Array.isArray(data.aktivitas_terakhir) && data.aktivitas_terakhir.length > 0) {
      var aktivitasTitle = document.createElement('p');
      aktivitasTitle.className = 'map-popup__meta';
      aktivitasTitle.textContent = 'Aktivitas terakhir:';
      wrap.appendChild(aktivitasTitle);

      var list = document.createElement('ul');
      list.className = 'map-popup__activity-list';
      data.aktivitas_terakhir.slice(0, 3).forEach(function (akt) {
        var li = document.createElement('li');
        var line = document.createElement('span');
        line.textContent = akt.tanggal + ' — ' + akt.jenis_kegiatan;
        li.appendChild(line);

        if (Array.isArray(akt.foto) && akt.foto.length > 0) {
          var thumbWrap = document.createElement('div');
          thumbWrap.className = 'map-popup__thumbs';
          akt.foto.slice(0, 3).forEach(function (fotoUrl) {
            // Thumbnail lazy (atribut bawaan browser, bukan IntersectionObserver
            // kustom) + fallback teks bila gagal dimuat (edge case wajib T-14).
            var img = document.createElement('img');
            img.className = 'map-popup__thumb';
            img.loading = 'lazy';
            img.alt = 'Foto aktivitas';
            img.src = fotoUrl;
            img.addEventListener('error', function () {
              img.replaceWith(buildThumbFallback());
            });
            thumbWrap.appendChild(img);
          });
          li.appendChild(thumbWrap);
        }

        list.appendChild(li);
      });
      wrap.appendChild(list);
    } else {
      var noAktivitas = document.createElement('p');
      noAktivitas.className = 'map-popup__activity map-popup__activity--muted';
      noAktivitas.textContent = 'Belum ada aktivitas tercatat.';
      wrap.appendChild(noAktivitas);
    }

    return wrap;
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

  // Sidebar off-canvas (<768px, lihat style.css #17 & assets/js/ui.js) adalah
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

  function fetchJson(url, signal) {
    return fetch(url, { headers: { Accept: 'application/json' }, signal: signal }).then(function (res) {
      if (res.ok) {
        return res.json();
      }
      // Dibungkus sebagai Error dengan properti .status -- pemanggil (termasuk
      // alur 401 T-14) membaca status itu sebelum memutuskan pesan ke pengguna.
      return res.json().catch(function () { return null; }).then(function (body) {
        var message = body && typeof body.error === 'string' ? body.error : ('Permintaan gagal (HTTP ' + res.status + ').');
        var err = new Error(message);
        err.status = res.status;
        throw err;
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

  function renderMarkers(markerLayer, groups, handlers) {
    var idIndex = {};
    groups.forEach(function (group) {
      var firstItem = group.items[0] || {};
      var icon = buildDivIcon(firstItem.status_izin, group.items.length);
      var marker = L.marker([group.lat, group.lng], { icon: icon });
      marker.addTo(markerLayer);

      group.items.forEach(function (item) {
        if (item.id !== undefined && item.id !== null) {
          idIndex[item.id] = { marker: marker, lat: group.lat, lng: group.lng };
        }
      });

      marker.on('click', function () {
        if (group.items.length > 1) {
          marker.unbindPopup();
          marker.bindPopup(buildPickerContent(group.items, function (id) {
            handlers.onOpenDetail(marker, id);
          })).openPopup();
        } else {
          handlers.onOpenDetail(marker, firstItem.id);
        }
      });
    });
    return idIndex;
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

    // --- State popup detail (T-14): cache per id (TTL 60dtk) + AbortController
    // supaya klik marker beruntun tidak pernah menampilkan data lokasi yang
    // salah (kriteria selesai T-14 eksplisit). requestSeq adalah jaring
    // pengaman KEDUA di atas AbortController: walau sebuah fetch lama
    // SEMPAT selesai sebelum abort-nya diproses browser, hasilnya tetap
    // dibuang kalau sudah ada klik yang lebih baru. ---
    var detailCache = {};
    var requestSeq = 0;
    var activeAbort = null;

    function openDetailPopup(marker, id) {
      if (id === undefined || id === null) {
        return;
      }

      marker.unbindPopup();
      var popup = L.popup().setContent(buildSkeleton());
      marker.bindPopup(popup).openPopup();

      if (activeAbort) {
        activeAbort.abort();
      }

      var cached = detailCache[id];
      if (cached && (Date.now() - cached.at) < DETAIL_CACHE_TTL_MS) {
        popup.setContent(buildDetailContent(cached.data));
        return;
      }

      var mySeq = ++requestSeq;
      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      activeAbort = controller;

      fetchJson(opts.detailUrl + '?id=' + encodeURIComponent(String(id)), controller ? controller.signal : undefined)
        .then(function (data) {
          if (mySeq !== requestSeq) {
            return; // ada klik lain setelah ini -- hasil sudah basi, buang
          }
          detailCache[id] = { data: data, at: Date.now() };
          popup.setContent(buildDetailContent(data));
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') {
            return; // dibatalkan sendiri karena ada klik baru -- bukan error nyata
          }
          if (mySeq !== requestSeq) {
            return;
          }
          if (err && err.status === 401) {
            // Edge case wajib T-14: sesi habis -- arahkan ke login, JANGAN
            // menampilkan JSON mentah {"error":"..."} di dalam popup.
            window.location.href = opts.loginUrl || 'login.php';
            return;
          }
          popup.setContent(buildDetailError(err && err.message));
        });
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
      var idIndex = renderMarkers(markerLayer, groups, { onOpenDetail: openDetailPopup });

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

      // Deep-link ?lokasi=ID (spesifikasi T-14) -- dijalankan TERAKHIR supaya
      // setView zoom 16 di bawah ini menang atas fitBounds di atas.
      if (opts.detailUrl) {
        applyDeepLink(map, idIndex, openDetailPopup, opts.detailUrl, showToast);
      }
    });

    return { map: map };
  }

  // Edge case wajib T-14: id tidak ada / bukan angka -- diam saja (tautan
  // lama/salah ketik), bukan error yang mengganggu pengguna.
  function applyDeepLink(map, idIndex, openDetailPopup, detailUrl, showToast) {
    var raw = new URLSearchParams(window.location.search).get('lokasi');
    if (!raw || !/^[1-9][0-9]*$/.test(raw)) {
      return;
    }
    var id = parseInt(raw, 10);

    var entry = idIndex[id];
    if (entry) {
      map.setView([entry.lat, entry.lng], DEEP_LINK_ZOOM);
      openDetailPopup(entry.marker, id);
      return;
    }

    // id berformat valid tapi tidak ada di antara marker yang termuat saat
    // ini -- coba ambil langsung; kalau API juga bilang tidak ada, beri tahu
    // lewat toast (bukan membiarkan tautan terlihat seperti tidak berefek).
    fetchJson(detailUrl + '?id=' + id).then(function (data) {
      if (!data || !data.koordinat) {
        return;
      }
      map.setView([data.koordinat.lat, data.koordinat.lng], DEEP_LINK_ZOOM);
      L.popup([data.koordinat.lat, data.koordinat.lng]).setContent(buildDetailContent(data)).openOn(map);
    }).catch(function () {
      showToast('Lokasi pada tautan (?lokasi=' + id + ') tidak ditemukan.');
    });
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
      detailUrl: container.dataset.detailUrl || null,
      loginUrl: container.dataset.loginUrl || null,
    });
  });
})(window);
