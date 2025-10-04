// js/script.js — Persistencia + sin buscador
(function () {
  // Asegurar ejecución post-DOM (por si el script está en <head>)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    const sidebar  = document.querySelector('.sidebar');
    const closeBtn = document.querySelector('.sidebar .logo-details #btn'); // selector específico

    if (!sidebar || !closeBtn) return;

    // 1) Leer estado persistido
    try {
      const saved = localStorage.getItem('sidebarOpen');
      if (saved === 'true') {
        sidebar.classList.add('open');
      } else if (saved === 'false') {
        sidebar.classList.remove('open');
      }
    } catch (e) { /* no-op */ }

    // 2) Actualizar icono según estado actual
    function updateMenuIcon() {
      const isOpen = sidebar.classList.contains('open');
      if (isOpen) {
        if (closeBtn.classList.contains('bx-menu')) {
          closeBtn.classList.replace('bx-menu', 'bx-menu-alt-right');
        }
      } else {
        if (closeBtn.classList.contains('bx-menu-alt-right')) {
          closeBtn.classList.replace('bx-menu-alt-right', 'bx-menu');
        }
      }
    }
    updateMenuIcon();

    // 3) Guardar estado
    function persist() {
      try { localStorage.setItem('sidebarOpen', String(sidebar.classList.contains('open'))); } catch (e) {}
    }

    // 4) Toggle handlers
    function toggleSidebar() {
      sidebar.classList.toggle('open');
      updateMenuIcon();
      persist();
    }

    closeBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      toggleSidebar();
    });

    // Accesibilidad por teclado
    closeBtn.setAttribute('tabindex', '0');
    closeBtn.setAttribute('role', 'button');
    closeBtn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        toggleSidebar();
      }
    });
  }
})();
