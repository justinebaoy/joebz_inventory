<script>
(function () {
  const sidebar = document.getElementById('sidebar');
  const sidebarOverlay = document.getElementById('sidebar-overlay');
  const openSidebarBtn = document.getElementById('open-sidebar');
  const collapseToggle = document.getElementById('sidebar-collapse-toggle');
  const mainContent = document.getElementById('app-main');
  const storageKey = 'joebz_sidebar_collapsed';

  if (!sidebar) return;

  function setCollapsedState(collapsed) {
    sidebar.classList.toggle('md:w-20', collapsed);
    sidebar.classList.toggle('md:w-64', !collapsed);
    sidebar.querySelectorAll('.sidebar-label, .sidebar-label-wrap').forEach(el => {
      el.classList.toggle('hidden', collapsed);
    });
    sidebar.querySelectorAll('.sidebar-link').forEach(el => {
      el.classList.toggle('md:justify-center', collapsed);
    });
    if (mainContent) {
      mainContent.classList.toggle('md:ml-20', collapsed);
      mainContent.classList.toggle('md:ml-64', !collapsed);
    }
    const icon = document.getElementById('collapse-icon');
    if (icon) {
      icon.style.transform = collapsed ? 'rotate(180deg)' : 'rotate(0deg)';
    }
    localStorage.setItem(storageKey, collapsed ? '1' : '0');
  }

  function openSidebar() {
    if (window.innerWidth >= 768) return;
    sidebar.classList.remove('-translate-x-full');
    sidebarOverlay?.classList.remove('hidden');
  }

  function closeSidebar() {
    if (window.innerWidth >= 768) return;
    sidebar.classList.add('-translate-x-full');
    sidebarOverlay?.classList.add('hidden');
  }

  const collapsed = localStorage.getItem(storageKey) === '1';
  setCollapsedState(collapsed);

  openSidebarBtn?.addEventListener('click', openSidebar);
  sidebarOverlay?.addEventListener('click', closeSidebar);
  collapseToggle?.addEventListener('click', () => {
    setCollapsedState(!sidebar.classList.contains('md:w-20'));
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth >= 768) {
      sidebar.classList.remove('-translate-x-full');
      sidebarOverlay?.classList.add('hidden');
    } else {
      sidebar.classList.add('-translate-x-full');
    }
  });
})();
</script>
