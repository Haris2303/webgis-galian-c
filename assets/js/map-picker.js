// assets/js/map-picker.js — picker titik/poligon untuk lokasi/form.php; jatuh ke input biasa bila Leaflet gagal dimuat.
(function () {
  'use strict';

  var MAX_DECIMALS = 7; // sesuai DECIMAL(10,7) di database/schema.sql

  function toFixedNum(value) {
    return Math.round(value * Math.pow(10, MAX_DECIMALS)) / Math.pow(10, MAX_DECIMALS);
  }

  function initPicker(container) {
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

    // titik tengah default: Kota Sorong
    var startLat = hasInitialPoint ? initialLat : -0.876;
    var startLng = hasInitialPoint ? initialLng : 131.255;

    var map = L.map(container, { tap: true }).setView([startLat, startLng], hasInitialPoint ? 15 : 12);

    // cegah gestur sentuh di dalam peta dibajak jadi scroll halaman
    container.style.touchAction = 'none';

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19,
    }).addTo(map);

    // batas administratif hanya referensi visual opsional; 404 tidak masalah
    var boundaryUrl = container.dataset.boundaryUrl;
    if (boundaryUrl) {
      fetch(boundaryUrl)
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (geojson) {
          if (geojson) {
            L.geoJSON(geojson, { style: { color: '#1E3A5F', weight: 2, fill: false, dashArray: '4 4' }, interactive: false }).addTo(map);
          }
        })
        .catch(function () {});
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

    // sinkron hanya saat blur/Enter, bukan tiap ketikan, agar marker tidak melompat saat mengetik
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
          event.preventDefault(); // cegah submit form tidak sengaja
          syncFromInputs();
        }
      });
    });

    if (hasInitialPoint) {
      placeMarker(initialLat, initialLng);
    }

    // gambar poligon (Leaflet.draw), hanya dipakai di mode poligon
    var drawnItems = new L.FeatureGroup().addTo(map);

    if (typeof L.Control.Draw === 'undefined') {
      // plugin draw tidak ada: pemilihan titik tetap jalan, hanya area poligon tidak tersedia
      finishInit();
      return;
    }

    var drawControl = new L.Control.Draw({
      draw: {
        polygon: {
          allowIntersection: false,
          showArea: false, // readableArea tidak akurat untuk hektar; dihitung manual di bawah
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
        geojsonInput.value = ''; // tidak ada poligon berarti area_geojson dikosongkan
        return;
      }

      var layer = layers[0];
      // toGeoJSON() sudah mengurutkan koordinat sebagai [lng,lat]; dipakai apa adanya, bukan dibangun ulang dari getLatLngs()
      var feature = layer.toGeoJSON();
      geojsonInput.value = JSON.stringify(feature);

      if (luasInput && typeof L.GeometryUtil !== 'undefined' && typeof L.GeometryUtil.geodesicArea === 'function') {
        var meterPersegi = L.GeometryUtil.geodesicArea(layer.getLatLngs()[0]);
        luasInput.value = (meterPersegi / 10000).toFixed(2); // m^2 -> ha
      }
    }

    function enforceSinglePolygon(newLayer) {
      // hanya satu poligon diizinkan: buang yang lama sebelum menambah yang baru
      drawnItems.eachLayer(function (existing) {
        drawnItems.removeLayer(existing);
      });
      drawnItems.addLayer(newLayer);
    }

    map.on(L.Draw.Event.CREATED, function (event) {
      var layer = event.layer;
      var rings = layer.toGeoJSON().geometry.coordinates;

      if (rings.length > 1) {
        // tolak poligon berlubang (lebih dari satu cincin)
        window.alert('Poligon dengan lubang tidak didukung. Gambar ulang sebagai satu area tertutup saja.');
        return;
      }

      enforceSinglePolygon(layer);
      updateGeojsonFromDrawnItems();
    });

    map.on(L.Draw.Event.EDITED, updateGeojsonFromDrawnItems);
    map.on(L.Draw.Event.DELETED, updateGeojsonFromDrawnItems);

    // mode ubah: muat poligon yang ada sebagai layer yang bisa diedit, bukan referensi statis
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
        // geojson tersimpan tidak terbaca; pengguna bisa gambar ulang saja
      }
    }

    finishInit();

    function finishInit() {
      // mode ubah: fit bounds supaya marker + poligon terlihat bersamaan, bukan hanya tampilan kota default
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
