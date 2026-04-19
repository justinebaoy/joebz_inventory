<script>
(function () {
  const sidebar = document.getElementById('sidebar');
  const sidebarOverlay = document.getElementById('sidebar-overlay');
  const openSidebarBtn = document.getElementById('open-sidebar');
  const desktopOpenBtn = document.getElementById('sidebar-desktop-open');
  const collapseToggle = document.getElementById('sidebar-collapse-toggle');
  const mainContent = document.getElementById('app-main');
  const storageKey = 'joebz_sidebar_open';

  if (!sidebar) return;

  function setDesktopSidebarState(isOpen) {
    sidebar.classList.toggle('md:-translate-x-full', !isOpen);
    sidebar.classList.toggle('md:translate-x-0', isOpen);

    desktopOpenBtn?.classList.toggle('md:flex', !isOpen);
    desktopOpenBtn?.classList.toggle('hidden', isOpen);

    if (mainContent) {
      mainContent.classList.toggle('md:ml-64', isOpen);
    }

    localStorage.setItem(storageKey, isOpen ? '1' : '0');
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

  const isDesktopOpen = localStorage.getItem(storageKey) !== '0';
  setDesktopSidebarState(isDesktopOpen);

  openSidebarBtn?.addEventListener('click', openSidebar);
  desktopOpenBtn?.addEventListener('click', () => setDesktopSidebarState(true));
  sidebarOverlay?.addEventListener('click', closeSidebar);
  collapseToggle?.addEventListener('click', () => setDesktopSidebarState(false));

  window.addEventListener('resize', () => {
    if (window.innerWidth >= 768) {
      setDesktopSidebarState(localStorage.getItem(storageKey) !== '0');
      sidebarOverlay?.classList.add('hidden');
    } else {
      sidebar.classList.add('-translate-x-full');
      desktopOpenBtn?.classList.add('hidden');
      desktopOpenBtn?.classList.remove('md:flex');
    }
  });
})();
</script>
