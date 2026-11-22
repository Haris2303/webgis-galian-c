/**
 * assets/js/ui.js — WebGIS Galian C Kota Sorong (T-04)
 * Vanilla JS murni (PRD bagian 3 mengizinkan untuk "tampilan dinamis tanpa
 * reload"). Dua hal di sini TIDAK BISA CSS-only sambil tetap aksesibel:
 * - Toggle sidebar off-canvas < 768px butuh aria-expanded yang disinkronkan.
 * - <dialog> butuh showModal()/close() lewat JS; tidak ada cara deklaratif
 *   lintas-browser untuk ini (berbeda dari Popover API).
 * Berkas ini tidak bergantung pustaka apa pun.
 */
(function () {
  'use strict';

  function initSidebarToggle() {
    // .ds-sidebar (migrasi UI DESIGN.md, app-shell/sidebar global) menggantikan
    // .app-sidebar lama -- dicek DUA kelas supaya toggle ini tidak putus pada
    // halaman mana pun yang belum/sudah dimigrasi di masa transisi.
    var toggleBtn = document.querySelector('[data-sidebar-toggle]');
    var sidebar = document.querySelector('.ds-sidebar, .app-sidebar');
    var backdrop = document.querySelector('.ds-sidebar-backdrop, .app-sidebar-backdrop');

    if (!toggleBtn || !sidebar) {
      return;
    }

    function close() {
      sidebar.classList.remove('is-open');
      toggleBtn.setAttribute('aria-expanded', 'false');
      if (backdrop) {
        backdrop.hidden = true;
      }
    }

    function open() {
      sidebar.classList.add('is-open');
      toggleBtn.setAttribute('aria-expanded', 'true');
      if (backdrop) {
        backdrop.hidden = false;
      }
      // Pindahkan fokus ke link pertama sidebar supaya pengguna keyboard
      // langsung masuk ke menu, bukan tertinggal di tombol yang baru ditekan.
      var firstLink = sidebar.querySelector('a, button');
      if (firstLink) {
        firstLink.focus();
      }
    }

    toggleBtn.addEventListener('click', function () {
      var isOpen = sidebar.classList.contains('is-open');
      if (isOpen) {
        close();
      } else {
        open();
      }
    });

    if (backdrop) {
      backdrop.addEventListener('click', close);
    }

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
        close();
        toggleBtn.focus();
      }
    });
  }

  function initDialogTriggers() {
    document.querySelectorAll('[data-dialog-open]').forEach(function (trigger) {
      var dialog = document.getElementById(trigger.getAttribute('data-dialog-open'));
      if (!dialog || typeof dialog.showModal !== 'function') {
        return;
      }
      trigger.addEventListener('click', function () {
        dialog.showModal();
      });
    });
  }

  /**
   * Animasi hitung-naik angka statistik dashboard (.ds-stat-col__value) dari
   * 0 ke nilai aslinya (sudah di-render server-side, JS hanya "memutar
   * ulang" secara visual) -- dimatikan total di prefers-reduced-motion,
   * angka langsung tampil final tanpa animasi apa pun.
   */
  function initStatCountUp() {
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) {
      return;
    }

    document.querySelectorAll('.ds-stat-col__value').forEach(function (el) {
      var target = parseInt(el.textContent, 10);
      if (isNaN(target)) {
        return;
      }

      var duration = 900;
      var start = null;

      function step(timestamp) {
        if (start === null) {
          start = timestamp;
        }
        var progress = Math.min((timestamp - start) / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = String(Math.round(eased * target));
        if (progress < 1) {
          requestAnimationFrame(step);
        } else {
          el.textContent = String(target);
        }
      }

      el.textContent = '0';
      requestAnimationFrame(step);
    });
  }

  function initFlashDismiss() {
    document.querySelectorAll('.flash__close').forEach(function (button) {
      button.addEventListener('click', function () {
        var flash = button.closest('.flash');
        if (flash) {
          flash.remove();
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initSidebarToggle();
    initDialogTriggers();
    initStatCountUp();
    initFlashDismiss();
  });
})();
