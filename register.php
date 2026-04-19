<?php
session_start();
require_once 'config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $username   = trim($_POST['username']);
    $email      = trim($_POST['email']);
    $password   = $_POST['password'];
    $confirm    = $_POST['confirm_password'];
    $role       = $_POST['role'];

    $role = isset($_POST['role']) ? $_POST['role'] : 'cashier';

// Validate role
    $allowed_roles = ['admin', 'cashier'];
    if (!in_array($role, $allowed_roles)) {
        $role = 'cashier';
}

    if (empty($first_name) || empty($last_name) ||
        empty($username)   || empty($email)     ||
        empty($password)   || empty($role)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($username) < 3) {
        $error = "Username must be at least 3 characters.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match. Please try again.";
    } else {
        // Check if username already exists
        $check_user = $conn->prepare(
            "SELECT user_id FROM users WHERE username = ?"
        );
        $check_user->bind_param("s", $username);
        $check_user->execute();
        $check_user->store_result();

        if ($check_user->num_rows > 0) {
            $error = "This username has already been taken.";
        } else {
            // Check if email already exists
            $check_email = $conn->prepare(
                "SELECT user_id FROM users WHERE email = ?"
            );
            $check_email->bind_param("s", $email);
            $check_email->execute();
            $check_email->store_result();

            if ($check_email->num_rows > 0) {
                $error = "This email has already been taken.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    "INSERT INTO users
                     (username, email, password_hash, role, first_name, last_name)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param(
                    "ssssss",
                    $username, $email, $hash,
                    $role, $first_name, $last_name
                );
                if ($stmt->execute()) {
                    $success = "Account created successfully! You can now log in.";
                } else {
                    $error = "Something went wrong. Please try again.";
                }
                $stmt->close();
            }
            $check_email->close();
        }
        $check_user->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account — JOEBZ Inventory</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen flex items-center justify-center px-4 py-8">

  <div class="w-full max-w-md">

    <!-- Header -->
    <div class="text-center mb-8">
      <div class="inline-flex items-center justify-center w-16 h-16
                  bg-blue-600 rounded-2xl mb-4">
        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
        </svg>
      </div>
      <h1 class="text-2xl font-bold text-slate-100">Create Account</h1>
      <p class="text-slate-400 text-sm mt-1">Register to access the POINT-OF-SALE SYSTEM</p>
    </div>

    <!-- Card -->
    <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl p-8">

      <!-- Success -->
      <?php if ($success): ?>
        <div class="flex items-center gap-3 bg-emerald-900/40 border border-emerald-700
                    text-emerald-200 rounded-xl px-4 py-3 mb-6 text-sm">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <div>
            <?= htmlspecialchars($success) ?>
            <a href="index.php" class="underline font-medium ml-1">Sign in here</a>
          </div>
        </div>
      <?php endif; ?>

      <!-- Error -->
      <?php if ($error): ?>
        <div class="flex items-center gap-3 bg-red-900/40 border border-red-700
                    text-red-200 rounded-xl px-4 py-3 mb-6 text-sm">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if (!$success): ?>
      <form method="POST" action="" id="registerForm" onsubmit="return validateForm()">

       <!-- Name Row -->
<div class="grid grid-cols-2 gap-4 mb-4">
  <div>
    <label class="block text-sm font-medium text-blue-300 mb-2 flex items-center gap-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      First Name
    </label>
    <input type="text" name="first_name"
           placeholder="Juan"
           value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"
           class="w-full px-4 py-3 border-2 border-slate-700 bg-slate-950/50 text-slate-100 rounded-xl text-sm
                  focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-500 transition
                  hover:border-blue-600 hover:bg-slate-950"
           required>
  </div>
  <div>
    <label class="block text-sm font-medium text-purple-300 mb-2 flex items-center gap-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
      </svg>
      Last Name
    </label>
    <input type="text" name="last_name"
           placeholder="Dela Cruz"
           value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"
           class="w-full px-4 py-3 border-2 border-slate-700 bg-slate-950/50 text-slate-100 rounded-xl text-sm
                  focus:outline-none focus:ring-2 focus:ring-purple-400 focus:border-purple-500 transition
                  hover:border-purple-600 hover:bg-slate-950"
           required>
  </div>
</div>

<!-- Middle Name -->
<div class="mb-4">
  <label class="block text-sm font-medium text-emerald-300 mb-2 flex items-center gap-2">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
    </svg>
    Middle Name
    <span class="text-slate-400 font-normal text-xs">(optional)</span>
  </label>
  <input type="text" name="middle_name"
         placeholder="Santos"
         value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>"
         class="w-full px-4 py-3 border-2 border-slate-700 bg-slate-950/50 text-slate-100 rounded-xl text-sm
                focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-emerald-500 transition
                hover:border-emerald-600 hover:bg-slate-950">
</div>

        <!-- Username -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-cyan-300 mb-2 flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
            Username
          </label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M13 10V3L4 14h7v7l9-11h-7z"/>
              </svg>
            </div>
            <input type="text" name="username"
                   placeholder="e.g. juan123"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                   class="w-full pl-10 pr-4 py-3 border-2 border-slate-700 bg-slate-950/50 text-slate-100 rounded-xl text-sm
                          focus:outline-none focus:ring-2 focus:ring-cyan-400 focus:border-cyan-500 transition
                          hover:border-cyan-600 hover:bg-slate-950"
                   required minlength="3">
          </div>
          <p class="text-xs text-cyan-400 mt-1">Minimum 3 characters</p>
        </div>

        <!-- Email -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-orange-300 mb-2 flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
            Email Address
          </label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
              </svg>
            </div>
            <input type="email" name="email"
                   placeholder="juan@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   class="w-full pl-10 pr-4 py-3 border-2 border-slate-700 bg-slate-950/50 text-slate-100 rounded-xl text-sm
                          focus:outline-none focus:ring-2 focus:ring-orange-400 focus:border-orange-500 transition
                          hover:border-orange-600 hover:bg-slate-950"
                   required>
          </div>
        </div>

        <!-- Role Selection -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-slate-200 mb-2">Account Role</label>
          <div class="grid grid-cols-2 gap-3">

            <!-- Cashier -->
            <label id="label_cashier"
                   class="role-card flex flex-col items-center gap-2 p-4 border-2
                          border-slate-700 bg-slate-800 rounded-xl cursor-pointer transition">
              <input type="radio" name="role" value="cashier"
                     class="hidden" checked onchange="selectRole('cashier')">
              <div id="icon_cashier"
                   class="w-10 h-10 bg-blue-600 rounded-xl flex items-center
                          justify-center transition">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01
                       M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5
                       a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
              </div>
              <div class="text-center">
                <p id="title_cashier" class="text-sm font-semibold text-slate-100">Cashier</p>
                <p class="text-xs text-slate-400 mt-0.5">Process sales & POS</p>
              </div>
              <!-- Check badge -->
              <div id="check_cashier"
                   class="w-5 h-5 bg-blue-600 rounded-full flex items-center
                          justify-center transition">
                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                </svg>
              </div>
            </label>

            <!-- Admin -->
            <label id="label_admin"
                   class="role-card flex flex-col items-center gap-2 p-4 border-2
                          border-slate-700 bg-slate-950 rounded-xl cursor-pointer transition">
              <input type="radio" name="role" value="admin"
                     class="hidden" onchange="selectRole('admin')">
              <div id="icon_admin"
                   class="w-10 h-10 bg-gray-200 rounded-xl flex items-center
                          justify-center transition">
                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944
                       a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9
                       c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03
                       9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
              </div>
              <div class="text-center">
                <p id="title_admin" class="text-sm font-semibold text-slate-200">Admin</p>
                <p class="text-xs text-slate-400 mt-0.5">Full system access</p>
              </div>
              <!-- Check badge hidden by default -->
              <div id="check_admin"
                   class="w-5 h-5 bg-slate-700 rounded-full flex items-center
                          justify-center transition opacity-0">
                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                </svg>
              </div>
            </label>

          </div>
        </div>

        <!-- Password -->
        <div class="mb-4">
          <label class="block text-sm font-medium text-rose-300 mb-2 flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            Password
          </label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="w-5 h-5 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
              </svg>
            </div>
            <input type="password" name="password" id="passwordInput"
                   placeholder="Minimum 6 characters"
                   oninput="checkMatch()"
                   class="w-full pl-10 pr-12 py-3 border-2 border-slate-700 bg-slate-950/50 text-slate-100 rounded-xl text-sm
                          focus:outline-none focus:ring-2 focus:ring-rose-400 focus:border-rose-500 transition
                          hover:border-rose-600 hover:bg-slate-950"
                   required minlength="6">
            <button type="button" onclick="togglePass('passwordInput','eyeIcon1')"
                    class="absolute inset-y-0 right-0 pr-3 flex items-center
                           text-rose-300 hover:text-rose-100">
              <svg id="eyeIcon1" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
              </svg>
            </button>
          </div>
          <p class="text-xs text-rose-400 mt-1">Minimum 6 characters</p>
        </div>

        <!-- Confirm Password -->
        <div class="mb-2">
          <label class="block text-sm font-medium text-pink-300 mb-2 flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            Confirm Password
          </label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="w-5 h-5 text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
            </div>
            <input type="password" name="confirm_password" id="confirmInput"
                   placeholder="Re-enter your password"
                   oninput="checkMatch()"
                   class="w-full pl-10 pr-12 py-3 border-2 border-slate-700 bg-slate-950/50 text-slate-100 rounded-xl text-sm
                          focus:outline-none focus:ring-2 focus:ring-pink-400 focus:border-pink-500 transition
                          hover:border-pink-600 hover:bg-slate-950"
                   required>
            <button type="button" onclick="togglePass('confirmInput','eyeIcon2')"
                    class="absolute inset-y-0 right-0 pr-3 flex items-center
                           text-pink-300 hover:text-pink-100">
              <svg id="eyeIcon2" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
              </svg>
            </button>
          </div>
        </div>

        <!-- Live Password Match Indicator -->
        <div id="matchIndicator" class="hidden mb-5">
          <div id="matchBox"
               class="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium">
            <svg id="matchIcon" class="w-4 h-4 flex-shrink-0" fill="none"
                 stroke="currentColor" viewBox="0 0 24 24"></svg>
            <span id="matchText"></span>
          </div>
        </div>

        <!-- Submit -->
        <button type="submit" id="submitBtn"
                class="w-full bg-blue-600 hover:bg-blue-700 active:bg-blue-800
                       text-white font-medium py-3 rounded-xl text-sm mt-2
                       transition duration-200 flex items-center justify-center gap-2
                       disabled:opacity-50 disabled:cursor-not-allowed">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0
                 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
          </svg>
          Create Account
        </button>

      </form>
      <?php endif; ?>
    </div>

    <!-- Back to login -->
    <p class="text-center text-sm text-slate-400 mt-6">
      Already have an account?
      <a href="index.php" class="text-blue-300 hover:underline font-medium">
        Sign in here
      </a>
    </p>
    <p class="text-center text-xs text-slate-500 mt-2">
      &copy; <?= date('Y') ?> Inventory_Joebz. All rights reserved.
    </p>

  </div>

  <script>
    // ── Role card toggle ──────────────────────────────
    function selectRole(selected) {
      const roles = ['cashier', 'admin'];
      roles.forEach(role => {
        const isSelected = role === selected;
        const label = document.getElementById('label_' + role);
        const icon  = document.getElementById('icon_'  + role);
        const title = document.getElementById('title_' + role);
        const check = document.getElementById('check_' + role);

        if (isSelected) {
          label.classList.replace('border-slate-700', 'border-blue-500');
          label.classList.replace('bg-slate-950',      'bg-blue-700');
          icon.classList.replace('bg-slate-700',  'bg-blue-600');
          title.classList.replace('text-slate-400', 'text-blue-300');
          check.classList.replace('bg-slate-700',  'bg-blue-600');
          check.classList.remove('opacity-0');
          // Fix icon color inside
          icon.querySelector('svg').classList.replace('text-slate-400', 'text-white');
        } else {
          label.classList.replace('border-blue-500', 'border-slate-700');
          label.classList.replace('bg-blue-700',      'bg-slate-950');
          icon.classList.replace('bg-blue-600',  'bg-slate-700');
          title.classList.replace('text-blue-300', 'text-slate-400');
          check.classList.replace('bg-blue-600',  'bg-slate-700');
          check.classList.add('opacity-0');
          icon.querySelector('svg').classList.replace('text-white', 'text-slate-400');
        }
      });
    }

    // ── Live password match checker ───────────────────
    function checkMatch() {
      const pass    = document.getElementById('passwordInput').value;
      const confirm = document.getElementById('confirmInput').value;
      const box     = document.getElementById('matchIndicator');
      const matchBox  = document.getElementById('matchBox');
      const matchIcon = document.getElementById('matchIcon');
      const matchText = document.getElementById('matchText');
      const submitBtn = document.getElementById('submitBtn');

      if (confirm.length === 0) {
        box.classList.add('hidden');
        submitBtn.disabled = false;
        return;
      }

      box.classList.remove('hidden');

      if (pass === confirm) {
        // Match — green
        matchBox.className = 'flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium bg-emerald-900/40 text-emerald-200 border border-emerald-700';
        matchIcon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round"
          stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>`;
        matchText.textContent = 'Passwords match!';
        submitBtn.disabled = false;
      } else {
        // No match — red
        matchBox.className = 'flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium bg-red-900/40 text-red-200 border border-red-700';
        matchIcon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round"
          stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>`;
        matchText.textContent = 'Passwords do not match!';
        submitBtn.disabled = true;
      }
    }

    // ── Final form validation before submit ───────────
    function validateForm() {
      const pass    = document.getElementById('passwordInput').value;
      const confirm = document.getElementById('confirmInput').value;
      if (pass !== confirm) {
        alert('Passwords do not match. Please fix before submitting.');
        return false;
      }
      if (pass.length < 6) {
        alert('Password must be at least 6 characters.');
        return false;
      }
      return true;
    }

    // ── Show/hide password toggle ─────────────────────
    function togglePass(inputId, iconId) {
      const input = document.getElementById(inputId);
      const icon  = document.getElementById(iconId);
      if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = `
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7
               a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243
               M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532
               l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5
               c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0
               01-4.132 5.411m0 0L21 21"/>`;
      } else {
        input.type = 'password';
        icon.innerHTML = `
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943
               9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>`;
      }
    }
  </script>

</body>
</html>