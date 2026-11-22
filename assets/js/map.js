// assets/js/map.js — initWebGISMap(containerId, options); options datang lewat atribut data-* karena CSP melarang script inline.
(function (global) {
  'use strict';

  var DEFAULT_CENTER = [-0.876, 131.255];
  var DEFAULT_ZOOM = 12;
  var MAX_ZOOM = 19;
  var SIDEBAR_TRANSITION_MS = 300; // harus lebih besar dari --transition-base style.css (220ms)
  var DETAIL_CACHE_TTL_MS = 60000;
  var DEEP_LINK_ZOOM = 16;
  var SEARCH_DEBOUNCE_MS = 250;
  var SEARCH_MIN_CHARS = 2;
  // Leaflet tidak punya tinggi maksimum popup default; dibatasi agar konten panjang scroll, bukan meluber keluar viewport
  var POPUP_MAX_HEIGHT = function () {
    return Math.round(window.innerHeight * 0.7);
  };

  function cssVar(name, fallback) {
    try {
      var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
      return value || fallback;
    } catch (e) {
      return fallback;
    }
  }

  // warna dibaca dari custom property CSS, bukan di-hardcode, agar design token tetap sinkron dengan style.css
  var STATUS_CONFIG = {
    berizin: { label: 'Berizin', shape: 'circle', badgeClass: 'badge--berizin', color: cssVar('--status-berizin', '#2E9E4F') },
    tidak_berizin: { label: 'Tidak Berizin', shape: 'square', badgeClass: 'badge--tidak-berizin', color: cssVar('--status-tidak-berizin', '#D64545') },
    proses: { label: 'Proses', shape: 'triangle', badgeClass: 'badge--proses', color: cssVar('--status-proses', '#E5A823') },
  };
  var STATUS_ORDER = ['berizin', 'tidak_berizin', 'proses'];
  var FALLBACK_STATUS = 'proses'; // status tak dikenal dari API jatuh ke tampilan netral

  function statusConfig(status) {
    return STATUS_CONFIG[status] || STATUS_CONFIG[FALLBACK_STATUS];
  }

  // bentuk berbeda per status (bukan hanya warna) untuk aksesibilitas buta warna; badge menampilkan jumlah saat marker tumpang tindih
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

  // marker dengan koordinat sama dikelompokkan jadi satu ikon dengan badge jumlah, bukan ikon bertumpuk
  function groupByCoordinate(features) {
    var groups = {};
    var order = [];
    features.forEach(function (feature) {
      var coords = feature.geometry && feature.geometry.coordinates;
      if (!Array.isArray(coords) || coords.length < 2) {
        return; // lewati geometri tidak valid daripada menggagalkan seluruh peta
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

  // alur popup: skeleton -> fetch detail -> render konten aman

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

  // daftar picker memakai ulang data yang sudah di memori; endpoint detail baru di-fetch setelah pilihan dibuat
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
    // fallback execCommand untuk konteks non-secure (dev http lokal) saat navigator.clipboard tidak tersedia
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

  // satu instance lightbox dipakai ulang lintas popup: hidup di document.body, bukan di dalam popup Leaflet yang sekali pakai
  function buildLightbox() {
    var dialog = document.createElement('dialog');
    dialog.className = 'map-lightbox';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'map-lightbox__close';
    closeBtn.setAttribute('aria-label', 'Tutup pratinjau foto');
    closeBtn.textContent = '×';

    var prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'map-lightbox__nav map-lightbox__nav--prev';
    prevBtn.setAttribute('aria-label', 'Foto sebelumnya');
    prevBtn.textContent = '‹';

    var nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'map-lightbox__nav map-lightbox__nav--next';
    nextBtn.setAttribute('aria-label', 'Foto berikutnya');
    nextBtn.textContent = '›';

    var figure = document.createElement('div');
    figure.className = 'map-lightbox__figure';

    var img = document.createElement('img');
    img.className = 'map-lightbox__image';
    figure.appendChild(img);

    var caption = document.createElement('p');
    caption.className = 'map-lightbox__caption';
    figure.appendChild(caption);

    var counter = document.createElement('p');
    counter.className = 'map-lightbox__counter';
    figure.appendChild(counter);

    dialog.appendChild(closeBtn);
    dialog.appendChild(prevBtn);
    dialog.appendChild(figure);
    dialog.appendChild(nextBtn);
    document.body.appendChild(dialog);

    var photos = [];
    var index = 0;

    function render() {
      var item = photos[index];
      if (!item) {
        return;
      }
      img.src = item.full;
      img.alt = item.caption || 'Foto dokumentasi aktivitas';
      caption.textContent = item.caption || '';
      counter.textContent = (index + 1) + ' dari ' + photos.length;
      var multi = photos.length > 1;
      prevBtn.hidden = !multi;
      nextBtn.hidden = !multi;
    }

    function goTo(newIndex) {
      index = (newIndex + photos.length) % photos.length;
      render();
    }

    prevBtn.addEventListener('click', function () { goTo(index - 1); });
    nextBtn.addEventListener('click', function () { goTo(index + 1); });
    closeBtn.addEventListener('click', function () { dialog.close(); });

    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') {
        goTo(index - 1);
      } else if (event.key === 'ArrowRight') {
        goTo(index + 1);
      }
    });

    // klik di luar figure berperilaku seperti klik backdrop
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        dialog.close();
      }
    });

    return {
      show: function (list, startIndex) {
        photos = Array.isArray(list) ? list : [];
        index = startIndex || 0;
        render();
        if (typeof dialog.showModal === 'function') {
          dialog.showModal();
        }
      },
    };
  }

  // semua teks dari server lewat textContent, tidak pernah innerHTML, agar markup yang disisipkan tidak pernah dieksekusi
  function buildDetailContent(data, lightbox) {
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

    // server adalah otoritas untuk aktivitas_dibatasi; ini hanya merefleksikannya, tidak pernah mengakalinya
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
      // daftar foto flat mencakup semua aktivitas (bukan hanya 3 yang ditampilkan per aktivitas) agar lightbox bisa menggeser semuanya
      var allPhotosFlat = [];
      data.aktivitas_terakhir.slice(0, 3).forEach(function (akt) {
        var li = document.createElement('li');
        var line = document.createElement('span');
        line.textContent = akt.tanggal + ': ' + akt.jenis_kegiatan;
        li.appendChild(line);

        if (Array.isArray(akt.foto) && akt.foto.length > 0) {
          var thumbWrap = document.createElement('div');
          thumbWrap.className = 'map-popup__thumbs';
          akt.foto.forEach(function (fotoItem, fotoIdx) {
            var flatIndex = allPhotosFlat.length;
            allPhotosFlat.push({ full: fotoItem.full, caption: akt.tanggal + ': ' + akt.jenis_kegiatan });

            if (fotoIdx >= 3) {
              return; // thumbnail dibatasi 3/aktivitas; foto selebihnya tetap bisa dijangkau lewat lightbox
            }

            // lazy-loading native, bukan observer custom; jatuh ke teks bila gambar gagal
            var img = document.createElement('img');
            img.className = 'map-popup__thumb';
            img.loading = 'lazy';
            img.alt = 'Foto aktivitas ' + akt.jenis_kegiatan + ' -- klik untuk lihat detail';
            img.src = fotoItem.thumb;
            img.addEventListener('error', function () {
              img.replaceWith(buildThumbFallback());
            });
            if (lightbox) {
              img.tabIndex = 0;
              img.setAttribute('role', 'button');
              img.classList.add('map-popup__thumb--clickable');
              img.addEventListener('click', function () {
                lightbox.show(allPhotosFlat, flatIndex);
              });
              img.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                  event.preventDefault();
                  lightbox.show(allPhotosFlat, flatIndex);
                }
              });
            }
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

  // renderer SVG per-pane eksplisit menghindari Leaflet diam-diam gagal merender layer pada kombinasi init/versi tertentu
  function buildAreaLayer(geojsonData, pane) {
    return L.geoJSON(geojsonData, {
      pane: pane,
      renderer: L.svg({ pane: pane }),
      style: function (feature) {
        var cfg = statusConfig(feature.properties && feature.properties.status_izin);
        return { color: cfg.color, weight: 2, fillColor: cfg.color, fillOpacity: 0.25 };
      },
    });
  }

  function buildBoundaryLayer(geojsonData, pane) {
    return L.geoJSON(geojsonData, {
      pane: pane,
      renderer: L.svg({ pane: pane }),
      interactive: false, // hanya garis referensi; tidak boleh mencuri klik dari marker/area
      style: { color: cssVar('--color-primary', '#1E3A5F'), weight: 2, fill: false, dashArray: '4 4' },
    });
  }

  // jalankan invalidateSize lagi saat resize, dan saat kontainer terlihat kembali setelah tersembunyi (Leaflet merender kosong bila tidak)
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

  // overlay sidebar off-canvas tidak mengubah ukuran .map-shell, jadi ResizeObserver di atas tidak terpicu; invalidate setelah transisi CSS-nya
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
      // dibungkus sebagai Error dengan properti .status agar pemanggil bisa bercabang berdasarkan itu (mis. 401)
      return res.json().catch(function () { return null; }).then(function (body) {
        var message = body && typeof body.error === 'string' ? body.error : ('Permintaan gagal (HTTP ' + res.status + ').');
        var err = new Error(message);
        err.status = res.status;
        throw err;
      });
    });
  }

  function fetchStaticGeojson(url) {
    // berkas statis, bukan endpoint API wajib; gagal diam-diam bila 404
    return fetch(url)
      .then(function (res) { return res.ok ? res.json() : null; })
      .catch(function () { return null; });
  }

  // idIndex diubah di tempat, tidak pernah diganti objeknya, agar closure lain yang sudah memegang referensinya selalu melihat data terkini
  function renderMarkers(markerLayer, groups, handlers, idIndex) {
    Object.keys(idIndex).forEach(function (key) { delete idIndex[key]; });

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
          }), { maxHeight: POPUP_MAX_HEIGHT() }).openPopup();
        } else {
          handlers.onOpenDetail(marker, firstItem.id);
        }
      });
    });
  }

  function parseActiveStatusesFromUrl() {
    var raw = new URLSearchParams(window.location.search).get('status');
    if (!raw) {
      return new Set(STATUS_ORDER);
    }
    var parsed = raw.split(',')
      .map(function (s) { return s.trim(); })
      .filter(function (s) { return STATUS_ORDER.indexOf(s) !== -1; });
    return parsed.length > 0 ? new Set(parsed) : new Set(STATUS_ORDER);
  }

  function syncStatusQueryString(activeStatuses) {
    var params = new URLSearchParams(window.location.search);
    if (activeStatuses.size === STATUS_ORDER.length) {
      // semua status aktif adalah default, jadi dilewati penulisannya agar tautan yang dibagikan tetap bersih
      params.delete('status');
    } else {
      params.set('status', STATUS_ORDER.filter(function (s) { return activeStatuses.has(s); }).join(','));
    }
    var query = params.toString();
    var newUrl = window.location.pathname + (query ? '?' + query : '') + window.location.hash;
    window.history.replaceState(null, '', newUrl);
  }

  function initWebGISMap(containerId, options) {
    var container = document.getElementById(containerId);
    if (!container) {
      return null;
    }

    if (typeof L === 'undefined') {
      // Leaflet gagal dimuat dari CDN; tampilkan pesan daripada membiarkan area kosong
      container.textContent = 'Peta tidak dapat dimuat (Leaflet gagal dimuat dari CDN). Muat ulang halaman atau periksa koneksi internet.';
      container.classList.add('map-shell--fallback');
      return null;
    }

    var opts = options || {};
    if (!opts.lokasiUrl) {
      throw new Error('initWebGISMap: opts.lokasiUrl wajib diisi.');
    }

    // awal pengukuran waktu muat awal yang dicetak di akhir fungsi ini
    var perfLoadStart = performance.now();

    // mode preview: kontrol minimal, dan klik marker menavigasi ke peta penuh daripada membuka popup
    var isPreview = opts.mode === 'preview';

    var center = opts.center || DEFAULT_CENTER;
    var zoom = opts.zoom || DEFAULT_ZOOM;
    var showLegend = opts.showLegend !== undefined ? opts.showLegend : !isPreview;
    var showLayerControl = opts.showLayerControl !== undefined ? opts.showLayerControl : !isPreview;
    var showSearch = opts.showSearch !== undefined ? opts.showSearch : !isPreview;

    var mapOptions = { tap: true };
    if (isPreview) {
      mapOptions.zoomControl = false;
      mapOptions.dragging = false;
      mapOptions.scrollWheelZoom = false;
      mapOptions.doubleClickZoom = false;
      mapOptions.boxZoom = false;
      mapOptions.keyboard = false;
      mapOptions.touchZoom = false;
    }
    var map = L.map(container, mapOptions).setView(center, zoom);

    // pane boundary/area ditaruh di bawah pane marker Leaflet (z=600) agar klik selalu kena marker dulu
    map.createPane('wgBoundaryPane');
    map.getPane('wgBoundaryPane').style.zIndex = 405;
    map.createPane('wgAreaPane');
    map.getPane('wgAreaPane').style.zIndex = 410;

    // satellite tiles use Google's endpoint without an official API key; fine for preview, not for production SLA
    var baseJalan = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      maxZoom: MAX_ZOOM,
    });
    var baseSatelit = L.tileLayer('https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', {
      subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
      attribution: '&copy; Google',
      maxZoom: MAX_ZOOM,
    });
    baseJalan.addTo(map);
    var baseLayers = isPreview ? null : { 'Peta Jalan': baseJalan, 'Satelit (Google)': baseSatelit };

    var markerLayer = L.layerGroup().addTo(map);
    var showToast = createToast(container);
    var loadingOverlay = createOverlay(container, 'Memuat data peta...', 'loading');

    observeResize(map, container);
    watchSidebarToggle(map);

    var detailCache = {};
    var requestSeq = 0;
    var activeDetailAbort = null;
    var idIndex = {};
    var allFeatures = [];
    var activeStatuses = parseActiveStatusesFromUrl();
    var filterCheckboxes = {};
    var filterCountEl = null;
    var lightbox = isPreview ? null : buildLightbox();

    function openDetailPopup(marker, id) {
      if (id === undefined || id === null) {
        return;
      }

      if (isPreview) {
        // preview mode navigates directly instead of fetching a popup here
        window.location.href = (opts.previewUrl || 'peta.php') + '?lokasi=' + encodeURIComponent(String(id));
        return;
      }

      marker.unbindPopup();
      var popup = L.popup({ maxHeight: POPUP_MAX_HEIGHT() }).setContent(buildSkeleton());
      marker.bindPopup(popup).openPopup();

      if (activeDetailAbort) {
        activeDetailAbort.abort();
      }

      var cached = detailCache[id];
      if (cached && (Date.now() - cached.at) < DETAIL_CACHE_TTL_MS) {
        popup.setContent(buildDetailContent(cached.data, lightbox));
        return;
      }

      var mySeq = ++requestSeq;
      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      activeDetailAbort = controller;

      fetchJson(opts.detailUrl + '?id=' + encodeURIComponent(String(id)), controller ? controller.signal : undefined)
        .then(function (data) {
          if (mySeq !== requestSeq) {
            return; // a newer click happened; discard this stale result
          }
          detailCache[id] = { data: data, at: Date.now() };
          popup.setContent(buildDetailContent(data, lightbox));
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') {
            return; // self-aborted by a newer click, not a real error
          }
          if (mySeq !== requestSeq) {
            return;
          }
          if (err && err.status === 401) {
            // session expired: redirect to login instead of showing a raw JSON error in the popup
            window.location.href = opts.loginUrl || 'login.php';
            return;
          }
          popup.setContent(buildDetailError(err && err.message));
        });
    }

    function syncFilterCheckboxUI() {
      STATUS_ORDER.forEach(function (key) {
        if (filterCheckboxes[key]) {
          filterCheckboxes[key].checked = activeStatuses.has(key);
        }
      });
    }

    // first call measures the initial render, later calls (from filter toggles) measure filter response
    var hasRenderedOnce = false;
    function renderFiltered(shouldFitBounds) {
      var perfStart = performance.now();

      var filtered = allFeatures.filter(function (f) {
        return activeStatuses.has(f.properties.status_izin);
      });

      // clearLayers also closes any open popup on a removed marker, which is how a filtered-out popup gets dismissed
      markerLayer.clearLayers();
      var groups = groupByCoordinate(filtered);
      renderMarkers(markerLayer, groups, { onOpenDetail: openDetailPopup }, idIndex);

      if (filterCountEl) {
        filterCountEl.textContent = filtered.length + ' lokasi tampil.';
      }

      syncStatusQueryString(activeStatuses);

      if (shouldFitBounds && groups.length > 0) {
        var bounds = L.latLngBounds(groups.map(function (g) { return [g.lat, g.lng]; }));
        map.fitBounds(bounds, { padding: [32, 32], maxZoom: 16 });
      }

      var perfLabel = hasRenderedOnce ? 'respons filter' : 'render marker awal';
      console.log('[perf] ' + perfLabel + ' (' + filtered.length + ' lokasi, ' + groups.length + ' marker): '
        + (performance.now() - perfStart).toFixed(1) + ' ms');
      hasRenderedOnce = true;
    }

    // legend and filter share one widget so the two can never show different active statuses
    function buildLegendFilterControl() {
      var LegendFilterControl = L.Control.extend({
        options: { position: 'bottomright' },
        onAdd: function () {
          var container = L.DomUtil.create('div', 'map-legend');
          var title = L.DomUtil.create('strong', 'map-legend__title', container);
          title.textContent = 'Status Izin';

          var list = L.DomUtil.create('ul', 'map-legend__list', container);
          STATUS_ORDER.forEach(function (key) {
            var cfg = STATUS_CONFIG[key];
            var item = L.DomUtil.create('li', 'map-legend__item', list);

            var label = document.createElement('label');
            label.className = 'map-legend__checkbox-label';

            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'map-legend__checkbox';
            checkbox.checked = activeStatuses.has(key);
            checkbox.addEventListener('change', function () {
              if (checkbox.checked) {
                activeStatuses.add(key);
              } else {
                activeStatuses.delete(key);
              }
              renderFiltered(false); // toggling a filter doesn't pan/zoom the map
            });
            filterCheckboxes[key] = checkbox;
            label.appendChild(checkbox);

            var icon = document.createElement('span');
            icon.className = 'map-legend__icon';
            icon.innerHTML = shapeMarkup(cfg.shape, cfg.color, 1); // self-built markup, not API data
            label.appendChild(icon);

            var text = document.createElement('span');
            text.className = 'map-legend__label';
            text.textContent = cfg.label;
            label.appendChild(text);

            item.appendChild(label);
          });

          filterCountEl = L.DomUtil.create('p', 'map-legend__count', container);

          L.DomEvent.disableClickPropagation(container);
          return container;
        },
      });
      return new LegendFilterControl();
    }

    // combobox/listbox pattern with debounce + abort so a stale response can never overwrite a newer one
    function buildSearchControl() {
      var SearchControl = L.Control.extend({
        options: { position: 'topleft' },
        onAdd: function () {
          var container = L.DomUtil.create('div', 'map-search leaflet-bar');
          container.setAttribute('role', 'search');

          var input = document.createElement('input');
          input.type = 'text';
          input.className = 'map-search__input';
          input.placeholder = 'Cari nama lokasi...';
          input.autocomplete = 'off';
          input.maxLength = 150; // matches nama_lokasi varchar(150) in database/schema.sql
          input.setAttribute('role', 'combobox');
          input.setAttribute('aria-autocomplete', 'list');
          input.setAttribute('aria-expanded', 'false');
          input.setAttribute('aria-haspopup', 'listbox');
          var listboxId = 'map-search-listbox-' + Math.random().toString(36).slice(2, 8);
          input.setAttribute('aria-controls', listboxId);
          container.appendChild(input);

          var listbox = document.createElement('ul');
          listbox.id = listboxId;
          listbox.className = 'map-search__listbox';
          listbox.setAttribute('role', 'listbox');
          listbox.hidden = true;
          container.appendChild(listbox);

          var statusEl = document.createElement('p');
          statusEl.className = 'sr-only';
          statusEl.setAttribute('aria-live', 'polite');
          container.appendChild(statusEl);

          var debounceTimer = null;
          var searchSeq = 0;
          var searchAbort = null;
          var results = [];
          var highlighted = -1;

          function closeListbox() {
            listbox.textContent = '';
            listbox.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            highlighted = -1;
          }

          function setHighlighted(idx) {
            var options = listbox.querySelectorAll('.map-search__option');
            options.forEach(function (opt) {
              opt.classList.remove('is-highlighted');
              opt.setAttribute('aria-selected', 'false');
            });
            if (idx >= 0 && idx < options.length) {
              highlighted = idx;
              options[idx].classList.add('is-highlighted');
              options[idx].setAttribute('aria-selected', 'true');
              input.setAttribute('aria-activedescendant', options[idx].id);
              options[idx].scrollIntoView({ block: 'nearest' });
            } else {
              highlighted = -1;
              input.removeAttribute('aria-activedescendant');
            }
          }

          function renderResults() {
            listbox.textContent = '';
            if (results.length === 0) {
              listbox.hidden = true;
              input.setAttribute('aria-expanded', 'false');
              return;
            }
            results.forEach(function (item, idx) {
              var li = document.createElement('li');
              li.id = listboxId + '-opt-' + idx;
              li.setAttribute('role', 'option');
              li.className = 'map-search__option';
              li.textContent = item.nama_lokasi; // textContent avoids XSS from the name field
              li.addEventListener('click', function () {
                pickResult(idx);
              });
              listbox.appendChild(li);
            });
            listbox.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            setHighlighted(0);
          }

          function flyToLokasi(item) {
            // re-enable the status filter if the result is currently filtered out
            if (!activeStatuses.has(item.status_izin)) {
              activeStatuses.add(item.status_izin);
              syncFilterCheckboxUI();
              renderFiltered(false);
            }
            var targetZoom = Math.max(map.getZoom(), DEEP_LINK_ZOOM);
            map.flyTo([item.lat, item.lng], targetZoom);
            var entry = idIndex[item.id];
            if (entry) {
              openDetailPopup(entry.marker, item.id);
            }
          }

          function pickResult(idx) {
            var item = results[idx];
            if (!item) {
              return;
            }
            input.value = item.nama_lokasi;
            closeListbox();
            flyToLokasi(item);
          }

          function runSearch(query) {
            if (searchAbort) {
              searchAbort.abort();
            }
            var mySeq = ++searchSeq;
            var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            searchAbort = controller;

            fetchJson(opts.cariUrl + '?q=' + encodeURIComponent(query), controller ? controller.signal : undefined)
              .then(function (data) {
                if (mySeq !== searchSeq) {
                  return; // a newer keystroke happened; discard this stale result
                }
                results = Array.isArray(data) ? data : [];
                renderResults();
                statusEl.textContent = results.length > 0
                  ? (results.length + ' hasil ditemukan untuk "' + query + '".')
                  : ('Tidak ada hasil untuk "' + query + '".');
              })
              .catch(function (err) {
                if (err && err.name === 'AbortError') {
                  return;
                }
                if (mySeq !== searchSeq) {
                  return;
                }
                results = [];
                renderResults();
                statusEl.textContent = 'Gagal memuat hasil pencarian.';
              });
          }

          input.addEventListener('input', function () {
            var query = input.value.trim();
            if (debounceTimer) {
              window.clearTimeout(debounceTimer);
            }
            if (query.length < SEARCH_MIN_CHARS) {
              // below the minimum length: skip the fetch entirely, don't just delay it
              if (searchAbort) {
                searchAbort.abort();
              }
              results = [];
              closeListbox();
              statusEl.textContent = '';
              return;
            }
            debounceTimer = window.setTimeout(function () {
              runSearch(query);
            }, SEARCH_DEBOUNCE_MS);
          });

          input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
              if (!listbox.hidden) {
                event.preventDefault();
                setHighlighted(Math.min(highlighted + 1, results.length - 1));
              }
            } else if (event.key === 'ArrowUp') {
              if (!listbox.hidden) {
                event.preventDefault();
                setHighlighted(Math.max(highlighted - 1, 0));
              }
            } else if (event.key === 'Enter') {
              if (!listbox.hidden && highlighted >= 0) {
                event.preventDefault();
                pickResult(highlighted);
              }
            } else if (event.key === 'Escape') {
              if (!listbox.hidden) {
                event.preventDefault();
                closeListbox();
              }
            }
          });

          L.DomEvent.disableClickPropagation(container);
          L.DomEvent.disableScrollPropagation(container);

          return container;
        },
      });
      return new SearchControl();
    }

    if (showLegend) {
      buildLegendFilterControl().addTo(map);
    }
    if (showSearch && opts.cariUrl) {
      buildSearchControl().addTo(map);
    }

    function applyDeepLink() {
      // missing/non-numeric id: ignore silently (stale or mistyped link) rather than erroring
      var raw = new URLSearchParams(window.location.search).get('lokasi');
      if (!raw || !/^[1-9][0-9]*$/.test(raw)) {
        return;
      }
      var id = parseInt(raw, 10);

      var feature = allFeatures.filter(function (f) { return f.properties && f.properties.id === id; })[0];
      if (feature) {
        // re-enable the filter if the deep-linked location is currently hidden by it
        if (!activeStatuses.has(feature.properties.status_izin)) {
          activeStatuses.add(feature.properties.status_izin);
          syncFilterCheckboxUI();
          renderFiltered(false);
        }
        var entry = idIndex[id];
        if (entry) {
          map.setView([entry.lat, entry.lng], DEEP_LINK_ZOOM);
          openDetailPopup(entry.marker, id);
        }
        return;
      }

      // id is valid but not among the loaded data; fetch it directly and toast if it truly doesn't exist
      fetchJson(opts.detailUrl + '?id=' + id).then(function (data) {
        if (!data || !data.koordinat) {
          return;
        }
        map.setView([data.koordinat.lat, data.koordinat.lng], DEEP_LINK_ZOOM);
        L.popup([data.koordinat.lat, data.koordinat.lng], { maxHeight: POPUP_MAX_HEIGHT() }).setContent(buildDetailContent(data, lightbox)).openOn(map);
      }).catch(function () {
        showToast('Lokasi pada tautan (?lokasi=' + id + ') tidak ditemukan.');
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
        createOverlay(container, 'Belum ada data lokasi tambang untuk ditampilkan.', 'empty');
      }

      allFeatures = features;
      renderFiltered(true);

      console.log('[perf] waktu muat awal peta (fetch data + render pertama): '
        + (performance.now() - perfLoadStart).toFixed(1) + ' ms');

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

      if (showLayerControl && baseLayers) {
        // collapse the control under 768px so it doesn't overlap the search box on narrow screens
        var layerControlCollapsed = window.matchMedia('(max-width: 767px)').matches;
        L.control.layers(baseLayers, overlays, { position: 'topright', collapsed: layerControlCollapsed }).addTo(map);
      }

      // run last so its setView wins over the fitBounds from renderFiltered above
      if (opts.detailUrl) {
        applyDeepLink();
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
      detailUrl: container.dataset.detailUrl || null,
      loginUrl: container.dataset.loginUrl || null,
      cariUrl: container.dataset.cariUrl || null,
    });
  });
})(window);
