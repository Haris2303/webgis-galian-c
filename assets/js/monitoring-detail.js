/**
 * assets/js/monitoring-detail.js — panel "Lihat" di monitoring.php: detail
 * satu aktivitas (lokasi, kontraktor, material, pencatat, foto, peta kecil)
 * diambil lazy lewat api/aktivitas_detail.php saat tombol diklik, SELALU
 * dirender lewat DOM API (createElement + textContent), TIDAK PERNAH
 * innerHTML dari data server -- pola yang sama dengan assets/js/map.js (T-14).
 *
 * Peta kecil pakai instans Leaflet SENDIRI (bukan assets/js/map.js, yang
 * dirancang untuk peta penuh dengan filter/cari/lightbox) -- hanya satu
 * marker statis, dibuat sekali & dipakai ulang (setView + pindah marker)
 * setiap panel dibuka untuk aktivitas yang berbeda.
 */
(function () {
  'use strict';

  var STATUS_AKTIVITAS_CONFIG = {
    aktif: { cls: 'ds-badge--berizin', label: 'Aktif' },
    selesai: { cls: 'ds-badge--proses', label: 'Selesai' },
    berhenti_sementara: { cls: 'ds-badge--tidak-berizin', label: 'Berhenti Sementara' },
  };

  var STATUS_IZIN_CONFIG = {
    berizin: { cls: 'ds-badge--berizin', label: 'Berizin' },
    tidak_berizin: { cls: 'ds-badge--tidak-berizin', label: 'Tidak Berizin' },
    proses: { cls: 'ds-badge--proses', label: 'Proses' },
  };

  function buildBadge(configMap, key) {
    var cfg = configMap[key] || { cls: 'ds-badge--tidak-diketahui', label: 'Tidak diketahui' };
    var span = document.createElement('span');
    span.className = 'ds-badge ' + cfg.cls;
    span.textContent = cfg.label;
    return span;
  }

  function buildMeta(text) {
    var p = document.createElement('p');
    p.className = 'ds-field__hint';
    p.textContent = text;
    return p;
  }

  function buildDetailBody(data) {
    var wrap = document.createElement('div');

    var title = document.createElement('p');
    title.className = 'detail-aktivitas-title';
    title.textContent = data.lokasi.nama_lokasi || '(tanpa nama)';
    wrap.appendChild(title);

    var badgeRow = document.createElement('p');
    badgeRow.appendChild(buildBadge(STATUS_IZIN_CONFIG, data.lokasi.status_izin));
    badgeRow.appendChild(document.createTextNode(' '));
    badgeRow.appendChild(buildBadge(STATUS_AKTIVITAS_CONFIG, data.status_aktivitas));
    wrap.appendChild(badgeRow);

    var metaParts = [data.lokasi.kontraktor, data.lokasi.material].filter(function (v) { return !!v; });
    if (metaParts.length > 0) {
      wrap.appendChild(buildMeta(metaParts.join(' / ')));
    }

    wrap.appendChild(buildMeta('Tanggal: ' + data.tanggal_aktivitas));
    wrap.appendChild(buildMeta('Jenis kegiatan: ' + data.jenis_kegiatan));
    wrap.appendChild(buildMeta('Alat berat: ' + data.jumlah_alat_berat + ' unit, Pekerja: ' + data.jumlah_pekerja + ' orang'));
    if (data.deskripsi) {
      wrap.appendChild(buildMeta('Keterangan: ' + data.deskripsi));
    }
    if (data.user_nama) {
      wrap.appendChild(buildMeta('Dicatat oleh: ' + data.user_nama));
    }

    var fotoList = Array.isArray(data.foto) ? data.foto : [];
    var fotoLabel = document.createElement('p');
    fotoLabel.className = 'ds-field__label';
    fotoLabel.textContent = 'Foto Dokumentasi (' + fotoList.length + ')';
    wrap.appendChild(fotoLabel);

    if (fotoList.length === 0) {
      wrap.appendChild(buildMeta('Belum ada foto untuk aktivitas ini.'));
    } else {
      var grid = document.createElement('ul');
      grid.className = 'ds-photo-grid';
      fotoList.forEach(function (foto) {
        var li = document.createElement('li');
        li.className = 'ds-photo-grid__item';
        var link = document.createElement('a');
        link.href = foto.full;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        var img = document.createElement('img');
        img.className = 'ds-photo-grid__thumb';
        img.loading = 'lazy';
        img.alt = 'Foto dokumentasi aktivitas';
        img.src = foto.thumb;
        link.appendChild(img);
        li.appendChild(link);
        grid.appendChild(li);
      });
      wrap.appendChild(grid);
    }

    var petaLink = document.createElement('a');
    petaLink.className = 'ds-link-arrow';
    petaLink.href = 'peta.php?lokasi=' + encodeURIComponent(String(data.lokasi.id));
    petaLink.textContent = 'Buka di peta penuh →';
    wrap.appendChild(petaLink);

    return wrap;
  }

  document.addEventListener('DOMContentLoaded', function () {
    var dialog = document.getElementById('dialog-detail-aktivitas');
    var content = document.getElementById('detail-aktivitas-content');
    var mapEl = document.getElementById('detail-aktivitas-map');
    var ubahLink = document.getElementById('detail-aktivitas-ubah');
    var hapusIdInput = document.getElementById('detail-aktivitas-hapus-id');

    if (!dialog || !content || !mapEl) {
      return;
    }

    var miniMap = null;
    var miniMarker = null;

    function showMiniMap(lat, lng) {
      mapEl.hidden = false;
      if (typeof L === 'undefined') {
        return; // Leaflet gagal dimuat dari CDN -- panel teks tetap berfungsi tanpa peta
      }
      if (!miniMap) {
        miniMap = L.map(mapEl, {
          zoomControl: false,
          dragging: false,
          scrollWheelZoom: false,
          doubleClickZoom: false,
          boxZoom: false,
          keyboard: false,
          touchZoom: false,
          tap: false,
        }).setView([lat, lng], 15);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
          maxZoom: 19,
        }).addTo(miniMap);
        miniMarker = L.marker([lat, lng]).addTo(miniMap);
      } else {
        miniMap.setView([lat, lng], 15);
        miniMarker.setLatLng([lat, lng]);
      }
      // Dialog baru showModal() -- kontainer peta baru punya ukuran NYATA
      // setelah ini, bukan sebelumnya (edge case yang sama dengan
      // assets/js/map.js: "peta di dalam elemen tersembunyi tampil abu-abu").
      window.requestAnimationFrame(function () {
        miniMap.invalidateSize();
      });
    }

    function openDetail(id) {
      content.textContent = '';
      content.appendChild(buildMeta('Memuat...'));
      mapEl.hidden = true;
      ubahLink.setAttribute('href', '#');
      hapusIdInput.value = '';
      dialog.showModal();

      fetch('api/aktivitas_detail.php?id=' + encodeURIComponent(id), { headers: { Accept: 'application/json' } })
        .then(function (res) {
          if (!res.ok) {
            return res.json().catch(function () { return null; }).then(function (body) {
              throw new Error(body && body.error ? body.error : ('Gagal memuat (HTTP ' + res.status + ').'));
            });
          }
          return res.json();
        })
        .then(function (data) {
          content.textContent = '';
          content.appendChild(buildDetailBody(data));
          ubahLink.setAttribute('href', 'aktivitas/form.php?id=' + encodeURIComponent(String(data.id)));
          hapusIdInput.value = String(data.id);
          if (data.lokasi && data.lokasi.koordinat) {
            showMiniMap(data.lokasi.koordinat.lat, data.lokasi.koordinat.lng);
          }
        })
        .catch(function (err) {
          content.textContent = '';
          content.appendChild(buildMeta(err && err.message ? err.message : 'Gagal memuat detail aktivitas.'));
        });
    }

    document.querySelectorAll('[data-aktivitas-detail]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openDetail(btn.getAttribute('data-aktivitas-detail'));
      });
    });
  });
})();
