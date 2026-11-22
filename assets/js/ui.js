// assets/js/ui.js — toggle sidebar dan kontrol <dialog> butuh JS; keduanya tidak bisa CSS-only sambil tetap aksesibel.
(function () {
  'use strict';

  function initSidebarToggle() {
    // cek dua nama kelas supaya tetap jalan di halaman yang belum dimigrasi ke .ds-sidebar
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
      // pindahkan fokus ke dalam sidebar untuk pengguna keyboard
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

  // animasi hitung naik dari nilai yang dirender server; dilewati saat prefers-reduced-motion
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
