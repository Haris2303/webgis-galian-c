/**
 * assets/js/map-picker.js — WebGIS Galian C Kota Sorong (T-10, FR-08/FR-09)
 * Peta pemilih titik + penggambar poligon area untuk lokasi/form.php.
 *
 * Murni UX: server (includes/validator.php, T-07) tetap satu-satunya otoritas
 * validasi koordinat & GeoJSON. Berkas ini TIDAK PERNAH menggantikannya --
 * kalau Leaflet/Leaflet.draw gagal dimuat dari CDN, input lat/lng/area_geojson
 * di form tetap input biasa yang bisa diisi manual (edge case wajib T-10).
 */
(function () {
  'use strict';

  var MAX_DECIMALS = 7; // samakan dengan DECIMAL(10,7) di database/schema.sql

  function toFixedNum(value) {
    return Math.round(value * Math.pow(10, MAX_DECIMALS)) / Math.pow(10, MAX_DECIMALS);
  }

  function initPicker(container) {
    // Edge case T-10: Leaflet.draw (atau Leaflet sendiri) gagal dimuat dari
    // CDN -- jangan pecah, cukup diam dan biarkan input manual di bawah tetap
    // berfungsi seperti form biasa tanpa JS.
    if (typeof L === 'undefined') {
      var fallback = document.getElementById('peta-picker-fallback');
      if (fallback) {
        fallback.hidden = false;
      }
      return;
    }

    var latInput = document.getElementById(container.dataset.latInput);
    var lngInput = document.getElementById(container.dataset.lngInput);
    var geojsonInput = document.getElementById(container.dataset.geojsonInput);
    var luasInput = document.getElementById(container.dataset.luasInput);
    var warningEl = document.getElementById(container.dataset.warningEl);

    var sorong = {
      latMin: parseFloat(container.dataset.sorongLatMin),
      latMax: parseFloat(container.dataset.sorongLatMax),
      lngMin: parseFloat(container.dataset.sorongLngMin),
      lngMax: parseFloat(container.dataset.sorongLngMax),
    };

    var initialLat = parseFloat(container.dataset.initialLat);
    var initialLng = parseFloat(container.dataset.initialLng);
    var hasInitialPoint = !isNaN(initialLat) && !isNaN(initialLng);

    // Pusat default: Kota Sorong (PRD FR-17, -0.876/131.255, zoom 12), atau
    // titik eksisting pada mode ubah.
    var startLat = hasInitialPoint ? initialLat : -0.876;
    var startLng = hasInitialPoint ? initialLng : 131.255;

    var map = L.map(container, { tap: true }).setView([startLat, startLng], hasInitialPoint ? 15 : 12);

    // touch-action: none -- biarkan Leaflet menangani SEMUA gestur sentuh di
    // dalam area peta sendiri, supaya drag marker/poligon di layar sentuh
    // tidak direbut browser sebagai scroll halaman (edge case wajib T-10).
    container.style.touchAction = 'none';

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19,
    }).addTo(map);

    // Batas administrasi Kota Sorong sebagai referensi visual SAJA, "bila
    // tersedia" (PRD/T-10) -- berkasnya baru disiapkan T-11, jadi 404 di sini
    // adalah hal normal untuk saat ini, bukan error yang perlu ditangani keras.
    var boundaryUrl = container.dataset.boundaryUrl;
    if (boundaryUrl) {
      fetch(boundaryUrl)
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (geojson) {
          if (geojson) {
            L.geoJSON(geojson, { style: { color: '#1E3A5F', weight: 2, fill: false, dashArray: '4 4' }, interactive: false }).addTo(map);
          }
        })
        .catch(function () { /* diam -- lihat komentar di atas */ });
    }

    var marker = null;

    function showCoordWarning(lat, lng) {
      if (!warningEl) {
        return;
      }
      var diLuarSorong = lat < sorong.latMin || lat > sorong.latMax || lng < sorong.lngMin || lng > sorong.lngMax;
      warningEl.hidden = !diLuarSorong;
    }

    function setLatLngInputs(lat, lng) {
      latInput.value = toFixedNum(lat);
      lngInput.value = toFixedNum(lng);
      showCoordWarning(lat, lng);
    }

    function placeMarker(lat, lng) {
      if (marker) {
        marker.setLatLng([lat, lng]);
      } else {
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', function () {
          var pos = marker.getLatLng();
          setLatLngInputs(pos.lat, pos.lng);
        });
      }
      setLatLngInputs(lat, lng);
    }

    map.on('click', function (event) {
      placeMarker(event.latlng.lat, event.latlng.lng);
    });

    // Sinkron DUA ARAH: input manual lat/lng -> pindahkan/pasang marker,
    // dipicu saat field kehilangan fokus (change) ATAU Enter ditekan di
    // dalamnya (spesifikasi T-10), bukan di setiap ketikan (hindari lompat
    // marker saat angka belum lengkap diketik).
    function syncFromInputs() {
      var lat = parseFloat(latInput.value.replace(',', '.'));
      var lng = parseFloat(lngInput.value.replace(',', '.'));
      if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
        placeMarker(lat, lng);
        map.panTo([lat, lng]);
      }
    }

    [latInput, lngInput].forEach(function (input) {
      input.addEventListener('change', syncFromInputs);
      input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          event.preventDefault(); // cegah submit form tak sengaja saat hanya ingin memindah titik
          syncFromInputs();
        }
      });
    });

    if (hasInitialPoint) {
      placeMarker(initialLat, initialLng);
    }

    // --- Poligon (Leaflet.draw), hanya mode polygon (FR-09) ---
    var drawnItems = new L.FeatureGroup().addTo(map);

    if (typeof L.Control.Draw === 'undefined') {
      // Leaflet inti berhasil dimuat tapi plugin draw gagal -- titik/marker di
      // atas tetap berfungsi penuh, hanya area poligon yang tidak tersedia.
      finishInit();
      return;
    }

    var drawControl = new L.Control.Draw({
      draw: {
        polygon: {
          allowIntersection: false,
          showArea: false, // readableArea plugin draw tidak selalu akurat untuk hektare; kita hitung sendiri lewat GeometryUtil
          shapeOptions: { color: '#1E3A5F' },
        },
        polyline: false,
        circle: false,
        circlemarker: false,
        rectangle: false,
        marker: false,
      },
      edit: { featureGroup: drawnItems, remove: true },
    });
    map.addControl(drawControl);

    function updateGeojsonFromDrawnItems() {
      var layers = drawnItems.getLayers();
      if (layers.length === 0) {
        // Hapus poligon lalu simpan = area_geojson NULL (spesifikasi T-10).
        geojsonInput.value = '';
        return;
      }

      var layer = layers[0];
      // layer.toGeoJSON() bawaan Leaflet SUDAH menghasilkan urutan [lng,lat]
      // yang benar sesuai spesifikasi GeoJSON -- sengaja dipakai apa adanya,
      // BUKAN menyusun manual dari getLatLngs(), supaya tidak ada titik di
      // kode ini yang bisa menukar urutan lat/lng (edge case paling umum, T-10).
      var feature = layer.toGeoJSON();
      geojsonInput.value = JSON.stringify(feature);

      if (luasInput && typeof L.GeometryUtil !== 'undefined' && typeof L.GeometryUtil.geodesicArea === 'function') {
        var meterPersegi = L.GeometryUtil.geodesicArea(layer.getLatLngs()[0]);
        luasInput.value = (meterPersegi / 10000).toFixed(2); // m^2 -> ha; tetap boleh diubah pengguna
      }
    }

    function enforceSinglePolygon(newLayer) {
      // Maksimal SATU poligon (spesifikasi T-10): buang yang lama sebelum
      // menambah yang baru, bukan membiarkan dua poligon menumpuk.
      drawnItems.eachLayer(function (existing) {
        drawnItems.removeLayer(existing);
      });
      drawnItems.addLayer(newLayer);
    }

    map.on(L.Draw.Event.CREATED, function (event) {
      var layer = event.layer;
      var rings = layer.toGeoJSON().geometry.coordinates;

      // Edge case T-10: poligon berlubang (lebih dari satu cincin) ditolak di
      // sisi klien juga, bukan hanya server -- lebih cepat diberi tahu ke
      // pengguna. Alat gambar standar Leaflet.draw tidak membuat lubang lewat
      // klik biasa, jadi ini jaring pengaman, bukan jalur yang sering terpakai.
      if (rings.length > 1) {
        window.alert('Poligon dengan lubang tidak didukung. Gambar ulang sebagai satu area tertutup saja.');
        return;
      }

      enforceSinglePolygon(layer);
      updateGeojsonFromDrawnItems();
    });

    map.on(L.Draw.Event.EDITED, updateGeojsonFromDrawnItems);
    map.on(L.Draw.Event.DELETED, updateGeojsonFromDrawnItems);

    // Mode ubah: muat poligon eksisting ke dalam layer yang BISA diedit/dihapus
    // lewat toolbar draw, bukan sekadar digambar ulang sebagai referensi statis.
    var initialGeojsonRaw = container.dataset.initialGeojson;
    var fitBoundsTarget = null;
    if (initialGeojsonRaw) {
      try {
        var initialGeojson = JSON.parse(initialGeojsonRaw);
        var existingLayer = L.geoJSON(initialGeojson, { style: { color: '#1E3A5F' } });
        existingLayer.eachLayer(function (l) {
          drawnItems.addLayer(l);
          fitBoundsTarget = l.getBounds();
        });
        updateGeojsonFromDrawnItems();
      } catch (e) {
        // GeoJSON tersimpan tidak terbaca -- biarkan form tetap bisa dipakai,
        // pengguna cukup menggambar ulang poligonnya.
      }
    }

    finishInit();

    function finishInit() {
      // Mode ubah: perlihatkan marker + poligon eksisting sekaligus (fitBounds,
      // spesifikasi T-10), bukan hanya pusat kota default.
      if (hasInitialPoint || fitBoundsTarget) {
        var bounds = fitBoundsTarget ? L.latLngBounds(fitBoundsTarget) : L.latLngBounds();
        if (hasInitialPoint) {
          bounds.extend([initialLat, initialLng]);
        }
        if (bounds.isValid()) {
          map.fitBounds(bounds, { padding: [24, 24], maxZoom: 16 });
        }
      }
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('peta-picker');
    if (container) {
      initPicker(container);
    }
  });
})();
