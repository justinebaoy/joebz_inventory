<?php
if (!function_exists('renderSidebar')) {
    function renderSidebar(string $activePage): void {
        $items = [
            ['key' => 'dashboard', 'href' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>'],
            ['key' => 'sales', 'href' => 'sales.php', 'label' => 'Sales / POS', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>'],
            ['key' => 'items', 'href' => 'items.php', 'label' => 'Items', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>'],
            ['key' => 'categories', 'href' => 'categories.php', 'label' => 'Categories', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>'],
            ['key' => 'reports', 'href' => 'reports.php', 'label' => 'Reports', 'admin' => true, 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>'],
            ['key' => 'users', 'href' => 'users.php', 'label' => 'Users', 'admin' => true, 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>'],
        ];
        ?>
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 transform bg-slate-950 border-r border-slate-800 flex flex-col transition-all duration-200 ease-out -translate-x-full md:translate-x-0">
    <div class="relative flex items-center gap-3 px-6 py-5 border-b border-slate-800">
      <button type="button" id="sidebar-collapse-toggle" class="hidden md:inline-flex absolute right-3 top-1/2 -translate-y-1/2 items-center justify-center rounded-lg border border-slate-700 bg-slate-900 p-1.5 text-slate-300 hover:text-white hover:bg-slate-800" title="Collapse sidebar">
        <svg id="collapse-icon" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
      </button>
      <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center shrink-0">
        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
      </div>
      <div class="sidebar-label-wrap">
        <p class="text-sm font-bold text-slate-100 sidebar-label">JOEBZ</p>
        <p class="text-xs text-slate-400 sidebar-label">POINT-OF-SALE SYSTEM</p>
      </div>
    </div>

    <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto">
      <?php foreach ($items as $item): ?>
        <?php if (!empty($item['admin']) && ($_SESSION['role'] ?? '') !== 'admin') continue; ?>
        <?php $isActive = $activePage === $item['key']; ?>
        <a href="<?= $item['href'] ?>" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition <?= $isActive ? 'bg-blue-900 text-white font-medium shadow-sm shadow-blue-900/20' : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
          <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $item['icon'] ?></svg>
          <span class="sidebar-label"><?= htmlspecialchars($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="px-4 py-4 border-t border-slate-800">
      <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-slate-900 mb-2">
        <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0"><?= strtoupper(substr($_SESSION['first_name'], 0, 1)) ?></div>
        <div class="sidebar-label-wrap">
          <p class="text-sm font-medium text-slate-100 sidebar-label"><?= htmlspecialchars($_SESSION['first_name']) ?></p>
          <p class="text-xs text-slate-400 capitalize sidebar-label"><?= $_SESSION['role'] ?></p>
        </div>
      </div>
      <a href="logout.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-xl text-red-300 hover:bg-red-800 text-sm transition">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        <span class="sidebar-label">Logout</span>
      </a>
    </div>
  </aside>
  <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-black/60 md:hidden"></div>
<?php
    }
}
