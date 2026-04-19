<?php
session_start();
require_once 'config/db.php';

// If already logged in, redirect
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare(
            "SELECT user_id, password_hash, role, first_name 
             FROM users WHERE username = ?"
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id']    = $user['user_id'];
                $_SESSION['role']       = $user['role'];
                $_SESSION['first_name'] = $user['first_name'];
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Invalid username or password.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — Inventory System</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 text-slate-100 min-h-screen flex items-center justify-center px-4">

  <div class="w-full max-w-md">

    <!-- Logo / Header -->
    <div class="text-center mb-8">
      <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 rounded-2xl mb-4">
        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/>
        </svg>
      </div>
      <h1 class="text-2xl font-bold text-slate-100">JOEBZ Inventory System</h1>
      <p class="text-slate-400 text-sm mt-1">Sign in to your account</p>
    </div>

    <!-- Card -->
    <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl p-8">

      <!-- Error Message -->
      <?php if ($error): ?>
        <div class="flex items-center gap-3 bg-red-50 border border-red-200 
                    text-red-700 rounded-xl px-4 py-3 mb-6 text-sm">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <!-- Form -->
      <form method="POST" action="">

        <!-- Username -->
        <div class="mb-5">
          <label class="block text-sm font-medium text-slate-200 mb-2">
            Username
          </label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
            </div>
            <input
              type="text"
              name="username"
              placeholder="Enter your username"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              class="w-full pl-10 pr-4 py-3 border border-slate-700 rounded-xl bg-slate-950 text-slate-100 text-sm
                     focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent
                     transition duration-200"
              required
              autocomplete="username"
            >
          </div>
        </div>

        <!-- Password -->
        <div class="mb-6">
          <label class="block text-sm font-medium text-slate-200 mb-2">
            Password
          </label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
              </svg>
            </div>
            <input
              type="password"
              name="password"
              id="passwordInput"
              placeholder="Enter your password"
              class="w-full pl-10 pr-12 py-3 border border-slate-700 rounded-xl bg-slate-950 text-slate-100 text-sm
                     focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent
                     transition duration-200"
              required
              autocomplete="current-password"
            >
            <!-- Show/Hide Password Toggle -->
            <button
              type="button"
              onclick="togglePassword()"
              class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-100"
            >
              <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7
                     -1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
              </svg>
            </button>
          </div>
        </div>

        <!-- Submit Button -->
        <button
          type="submit"
          class="w-full bg-blue-600 hover:bg-blue-700 active:bg-blue-800
                 text-white font-medium py-3 rounded-xl text-sm
                 transition duration-200 flex items-center justify-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
          </svg>
          Log In
        </button>

      </form>
    </div>

    <!-- Footer -->
<div class="text-center mt-6 space-y-2">
  <p class="text-sm text-slate-400">
    Don't have an account?
    <a href="register.php"
       class="text-blue-300 hover:text-blue-200 font-medium hover:underline transition">
      Create Account
    </a>
  </p>
  <p class="text-xs text-slate-500">
    &copy; <?= date('Y') ?> Inventory_Joebz. All rights reserved.
  </p>
</div>

  </div>

  <script>
    function togglePassword() {
      const input = document.getElementById('passwordInput');
      const icon  = document.getElementById('eyeIcon');
      if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = `
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7
               a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243
               M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29
               M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7
               a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>`;
      } else {
        input.type = 'password';
        icon.innerHTML = `
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7
               -1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>`;
      }
    }
  </script>

</body>
</html>