/**
 * assets/js/map.js: WebGIS Galian C Kota Sorong (T-13/T-14/T-15, FR-17/18/19/20)
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
 *
 * Pencarian & filter (T-15) seluruhnya dibangun sebagai L.Control dinamis
 * (bukan markup tambahan di peta.php) -- kontrol legenda (T-13) dan filter
 * status DIGABUNG jadi satu widget supaya keduanya TIDAK PERNAH tidak
 * sinkron (spesifikasi T-15: "tersinkron dengan legenda"); tidak ada dua
 * sumber kebenaran status yang bisa berbeda.
 *
 * Instrumentasi performa (T-16, PRD bagian 11): console.log('[perf] ...')
 * dicetak SELALU (bukan di belakang flag debug) -- biaya satu console.log
 * per kejadian (muat awal, tiap render marker/filter) diabaikan dibanding
 * manfaatnya sebagai bukti terus-menerus, dan tidak pernah tampil ke
 * pengguna biasa (DevTools tertutup). Lihat database/seed_perf.sql untuk
 * data sintetis dan docs/benchmark_peta.md untuk hasil ukur sebelum/sesudah.
 */
(function (global) {
  'use strict';

  var DEFAULT_CENTER = [-0.876, 131.255]; // PRD bagian 5, FR-17
  var DEFAULT_ZOOM = 12;
  var MAX_ZOOM = 19;
  var SIDEBAR_TRANSITION_MS = 300; // > --transition-base (220ms, style.css) + jeda aman
  var DETAIL_CACHE_TTL_MS = 60000; // spesifikasi T-14: cache per id 60 detik
  var DEEP_LINK_ZOOM = 16; // spesifikasi T-14, dipakai ulang untuk "pilih hasil cari" (T-15)
  var SEARCH_DEBOUNCE_MS = 250; // spesifikasi T-15
  var SEARCH_MIN_CHARS = 2; // spesifikasi T-15
  // Edge case wajib T-25: "popup Leaflet melebihi layar kecil". Leaflet TIDAK
  // punya maxHeight default -- popup berisi beberapa aktivitas + thumbnail
  // bisa lebih tinggi dari layar ponsel. maxHeight memaksa Leaflet menambah
  // scroll internal (.leaflet-popup-content) begitu konten melebihi tinggi
  // ini, bukan meluber keluar viewport. 70% tinggi jendela (bukan angka
  // tetap) supaya tetap proporsional baik di ponsel pendek maupun tablet.
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

  // --- Lightbox foto aktivitas: "lihat detail serta di slide" (UI). SATU
  // instans per peta, dibuat sekali & dipakai ulang lintas popup -- bukan
  // dibangun ulang tiap kali popup dibuka, karena ia hidup di document.body
  // (top layer <dialog>), bukan di dalam popup Leaflet yang bisa dibongkar
  // kapan saja. Gambar pakai ukuran "full" (bukan thumb) -- thumbnail di
  // popup cukup kecil, lightbox butuh resolusi penuh (media.php?size=full).
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

    // Klik di luar .map-lightbox__figure (area kosong dialog sendiri) menutup
    // juga -- setara klik backdrop, tanpa perlu elemen overlay terpisah.
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

  // Konten detail penuh. SETIAP teks dari server masuk lewat textContent --
  // edge case wajib T-14: nama lokasi berisi "<img src=x onerror=alert(1)>"
  // atau "<script>" harus tampil sebagai TEKS APA ADANYA, tidak pernah
  // dieksekusi, karena tidak ada satu pun innerHTML yang menerima data ini.
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
      // Daftar gabungan SEMUA foto lintas aktivitas (bukan dibatasi 3/aktivitas
      // seperti thumbnail popup) -- lightbox slide jalan terus lintas
      // aktivitas, bukan berhenti di batas satu baris (UI: "di setiap titik").
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
              return; // thumbnail popup tetap ringkas (maks 3/aktivitas); foto ke-4+ tetap masuk slide lightbox
            }

            // Thumbnail lazy (atribut bawaan browser, bukan IntersectionObserver
            // kustom) + fallback teks bila gagal dimuat (edge case wajib T-14).
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

  // renderer EKSPLISIT (bukan hanya opsi "pane") -- Leaflet BISA membuat
  // renderer SVG per-pane secara otomatis/lazy, tapi menetapkannya langsung
  // di sini menghilangkan kemungkinan layer gagal dirender (menumpuk di
  // pane yang benar tapi tanpa elemen SVG apa pun) pada kombinasi urutan
  // inisialisasi/versi Leaflet tertentu -- satu renderer dipakai BERSAMA oleh
  // semua fitur geoJSON yang sama, dibuat sekali per pane, bukan per fitur.
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

  // idIndex SELALU objek yang SAMA (dimutasi di tempat, tidak pernah diganti
  // referensinya) -- T-15 menambahkan render ulang berkali-kali (tiap filter
  // berubah), dan setiap closure lain (kontrol cari, deep-link) yang sudah
  // memegang referensi idIndex harus otomatis melihat isi terbaru tanpa perlu
  // diberi tahu ulang.
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

  // --- Filter status (T-15, FR-20): state di query string ?status=a,b ---

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
      // Semua status aktif = kondisi default -- tidak perlu ditulis di URL,
      // tautan yang dibagikan tetap bersih kalau filter tidak dipakai.
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

    // T-16: ditandai dari sini -- titik paling awal yang wajar (Leaflet
    // sudah pasti termuat, container sudah pasti ada) -- sampai render
    // pertama selesai, dicetak di bagian bawah fungsi ini.
    var perfLoadStart = performance.now();

    // Mode 'preview' (T-20, FR-05): pratinjau peta kecil di dashboard.php.
    // "Kontrol minimal" -> legenda/filter, layer control, dan kotak cari
    // mati secara default (tetap bisa dipaksa nyala lewat opts.showXxx
    // eksplisit). Klik marker TIDAK membuka popup detail lazy-load seperti
    // T-14 -- langsung navigasi ke peta.php?lokasi=ID (lihat openDetailPopup
    // di bawah), dan interaksi peta (drag/scroll/dbltap zoom) dimatikan
    // supaya peta kecil ini tidak "menyita" scroll halaman dashboard.
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

    // Pane kustom untuk batas kota & area tambang, keduanya di BAWAH
    // markerPane bawaan Leaflet (z=600, tidak diubah) -- menjamin klik selalu
    // mengenai marker dulu, bukan poligon besar di baliknya (edge case wajib
    // T-13), lewat urutan z-index yang eksplisit, bukan kebetulan default.
    map.createPane('wgBoundaryPane');
    map.getPane('wgBoundaryPane').style.zIndex = 405;
    map.createPane('wgAreaPane');
    map.getPane('wgAreaPane').style.zIndex = 410;

    // Dua basemap: peta jalan (OSM, default) & satelit. Tile satelit lewat
    // endpoint Google TANPA kunci API resmi (lihat catatan CSP di
    // includes/auth.php) -- cukup untuk pratinjau visual, TIDAK ada garansi
    // ketersediaan/SLA; produksi yang butuh itu sebaiknya pindah ke Google
    // Maps Platform (kunci API + billing) di endpoint ini.
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

    // --- State bersama T-14 (popup detail) & T-15 (cari/filter). Semua
    // dideklarasikan di sini (bukan modul-level) karena setiap instance peta
    // (kalau kelak dipakai juga di pratinjau dashboard) butuh state sendiri. ---
    var detailCache = {};
    var requestSeq = 0;
    var activeDetailAbort = null;
    var idIndex = {};
    var allFeatures = [];
    var activeStatuses = parseActiveStatusesFromUrl();
    var filterCheckboxes = {};
    var filterCountEl = null;
    // Lightbox foto: tidak dibuat di mode pratinjau (dashboard) -- klik marker
    // di sana langsung navigasi ke peta.php (lihat openDetailPopup), popup
    // detail/lightbox tidak pernah terbuka di mode itu.
    var lightbox = isPreview ? null : buildLightbox();

    function openDetailPopup(marker, id) {
      if (id === undefined || id === null) {
        return;
      }

      if (isPreview) {
        // Spesifikasi T-20: "klik membuka peta.php?lokasi=ID" -- navigasi
        // langsung, bukan fetch+popup di tempat (itu urusan peta.php sendiri
        // lewat T-14 setelah berpindah halaman).
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
            return; // ada klik lain setelah ini -- hasil sudah basi, buang
          }
          detailCache[id] = { data: data, at: Date.now() };
          popup.setContent(buildDetailContent(data, lightbox));
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

    function syncFilterCheckboxUI() {
      STATUS_ORDER.forEach(function (key) {
        if (filterCheckboxes[key]) {
          filterCheckboxes[key].checked = activeStatuses.has(key);
        }
      });
    }

    // Render ulang marker dari allFeatures (SUDAH di memori, T-15: "bekerja
    // di sisi klien", nol request baru) sesuai activeStatuses saat ini.
    // markerLayer.clearLayers() otomatis menutup popup manapun yang masih
    // terbuka pada marker yang dihapus -- ini SENGAJA jadi cara kita
    // menangani edge case wajib T-15 "filter menyembunyikan lokasi yang
    // sedang popup terbuka": popupnya ikut hilang, bukan jadi floating/basi.
    //
    // T-16: satu titik ukur ini menutupi DUA metrik sekaligus -- panggilan
    // PERTAMA (dari Promise.allSettled di bawah) adalah "render marker awal",
    // panggilan berikutnya (dari checkbox filter) adalah "respons filter" --
    // labelnya dibedakan lewat hasRenderedOnce, tanpa menduplikasi kode ukur.
    var hasRenderedOnce = false;
    function renderFiltered(shouldFitBounds) {
      var perfStart = performance.now();

      var filtered = allFeatures.filter(function (f) {
        return activeStatuses.has(f.properties.status_izin);
      });

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

    // Kontrol gabungan legenda + filter (T-13 + T-15) -- SATU widget supaya
    // tidak ada dua tempat yang bisa menunjukkan status aktif berbeda.
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
              renderFiltered(false); // toggle filter TIDAK memindah/zoom ulang peta
            });
            filterCheckboxes[key] = checkbox;
            label.appendChild(checkbox);

            var icon = document.createElement('span');
            icon.className = 'map-legend__icon';
            icon.innerHTML = shapeMarkup(cfg.shape, cfg.color, 1); // markup sendiri, bukan data API
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

    // Kotak pencarian autocomplete (T-15, FR-20): skeleton pola ARIA
    // combobox/listbox, debounce, AbortController + token urutan (idiom yang
    // sama dengan openDetailPopup di atas) supaya respons lama tidak pernah
    // menimpa respons baru.
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
          input.maxLength = 150; // samakan dengan nama_lokasi varchar(150), database/schema.sql
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
              li.textContent = item.nama_lokasi; // textContent -- aman dari XSS walau nama berisi markup
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
            // Kombinasi cari + filter wajib tetap bekerja (spesifikasi T-15):
            // hasil cari yang statusnya sedang difilter OFF tetap harus BISA
            // dituju -- aktifkan kembali filter status itu dulu.
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
                  return; // ada pengetikan lebih baru -- buang hasil basi (edge case wajib T-15)
                }
                results = Array.isArray(data) ? data : [];
                renderResults();
                statusEl.textContent = results.length > 0
                  ? (results.length + ' hasil ditemukan untuk "' + query + '".')
                  : ('Tidak ada hasil untuk "' + query + '".'); // edge case wajib T-15: hasil kosong
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
              // Edge case wajib T-15: input pendek/dikosongkan -- jangan fetch
              // sama sekali, bukan hanya menunda.
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
      // Edge case wajib T-14: id tidak ada / bukan angka -- diam saja (tautan
      // lama/salah ketik), bukan error yang mengganggu pengguna.
      var raw = new URLSearchParams(window.location.search).get('lokasi');
      if (!raw || !/^[1-9][0-9]*$/.test(raw)) {
        return;
      }
      var id = parseInt(raw, 10);

      var feature = allFeatures.filter(function (f) { return f.properties && f.properties.id === id; })[0];
      if (feature) {
        // Sama seperti hasil cari (T-15): deep-link ke lokasi yang statusnya
        // sedang difilter OFF tetap harus membukanya, bukan diam-diam gagal.
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

      // id berformat valid tapi tidak ada di antara data yang sudah termuat
      // sama sekali -- coba ambil langsung; kalau API juga bilang tidak ada,
      // beri tahu lewat toast (bukan membiarkan tautan terlihat tidak berefek).
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
        // Edge case wajib T-13: nol lokasi -- peta tetap tampil, bukan halaman kosong.
        createOverlay(container, 'Belum ada data lokasi tambang untuk ditampilkan.', 'empty');
      }

      allFeatures = features;
      renderFiltered(true); // render pertama -- fitBounds ke hasil (ter-filter sesuai URL) boleh; mencetak log [perf] sendiri

      // T-16: "waktu muat awal" (PRD bagian 11, target < 2 detik) -- dari titik
      // paling awal yang wajar di initWebGISMap sampai render marker PERTAMA
      // selesai (bukan menunggu tile raster yang async & di luar kendali app).
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
        // T-25 (edge case "zoom 200% / layar 360px"): collapsed:false (T-13,
        // demi keterbacaan langsung tanpa klik) + kotak cari 220px (topleft)
        // bisa tumpang tindih di kanvas peta yang menyempit -- kolapskan
        // kontrol ini otomatis di bawah 768px/efektif sempit, cukup ikon
        // kecil yang masih memenuhi 44px (lihat .leaflet-control-layers-toggle
        // di style.css), diperluas lewat klik/tap seperti biasa. baseLayers
        // (toggle peta jalan/satelit) selalu ada di sini -- kontrol ini
        // sekarang TIDAK lagi digerbangi jumlah overlay opsional (area/batas).
        var layerControlCollapsed = window.matchMedia('(max-width: 767px)').matches;
        L.control.layers(baseLayers, overlays, { position: 'topright', collapsed: layerControlCollapsed }).addTo(map);
      }

      // Deep-link ?lokasi=ID (T-14) -- dijalankan TERAKHIR supaya setView
      // zoom 16 di dalamnya menang atas fitBounds dari renderFiltered di atas.
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
