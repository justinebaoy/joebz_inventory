<?php
session_start();
require_once 'config/db.php';

// Only admins can access this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name   = trim($_POST['first_name']);
    $middle_name  = trim($_POST['middle_name'] ?? '');
    $last_name    = trim($_POST['last_name']);
    $username     = trim($_POST['username']);
    $email        = trim($_POST['email']);
    $phone        = trim($_POST['phone'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $birthdate    = $_POST['birthdate'] ?? '';
    $gender       = $_POST['gender'] ?? '';
    $password     = $_POST['password'];
    $confirm      = $_POST['confirm_password'];
    $role         = $_POST['role'] ?? 'staff'; // Admin can assign role
    
    // Prevent privilege escalation — only allow valid roles
    if (!in_array($role, ['staff', 'admin'])) {
        $role = 'staff';
    }

    if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($password)) {
        $error = "All required fields (*) must be filled.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (!empty($phone) && !preg_match('/^[0-9+\-\s()]+$/', $phone)) {
        $error = "Phone number contains invalid characters.";
    } elseif (!empty($birthdate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
        $error = "Invalid birth date format.";
    } elseif (strlen($username) < 3) {
        $error = "Username must be at least 3 characters.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Username or email already exists.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role, first_name, middle_name, last_name, phone, address, birthdate, gender) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssss", $username, $email, $hash, $role, $first_name, $middle_name, $last_name, $phone, $address, $birthdate, $gender);
            if ($stmt->execute()) {
                $success = "Account created successfully for " . htmlspecialchars($first_name . ' ' . $last_name) . ".";
            } else {
                $error = "Failed to create account. Please try again.";
            }
            $stmt->close();
        }
        $check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Staff Account - JOEBZ POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .password-match-indicator {
            font-size: 0.75rem;
            margin-top: 0.25rem;
            transition: all 0.2s;
        }
        .match-success { color: #34d399; }
        .match-error   { color: #f87171; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 min-h-screen flex items-center justify-center px-4 py-8">
    <div class="w-full max-w-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-white">Create Staff Account</h1>
                <p class="text-slate-400 text-sm mt-1">Admin Panel — Account Management</p>
            </div>
            <a href="dashboard.php" class="text-sm text-slate-400 hover:text-white border border-slate-700 px-4 py-2 rounded-lg transition">
                ← Back to Dashboard
            </a>
        </div>

        <!-- Logged in as -->
        <div class="bg-blue-900/30 border border-blue-800 rounded-xl px-4 py-2 mb-6 text-sm text-blue-300">
            Logged in as <span class="font-semibold"><?= htmlspecialchars($_SESSION['first_name']) ?></span> (Admin)
        </div>

        <div class="bg-slate-900/95 rounded-2xl border border-slate-800 shadow-xl p-6 md:p-8">

            <?php if ($success): ?>
                <div class="bg-emerald-900/40 border border-emerald-700 text-emerald-200 rounded-xl px-4 py-3 mb-6 text-sm">
                    ✓ <?= $success ?>
                </div>
            <?php elseif ($error): ?>
                <div class="bg-red-900/40 border border-red-700 text-red-200 rounded-xl px-4 py-3 mb-6 text-sm">
                    ✗ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4" id="registerForm">

                <!-- Name Fields -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">First Name <span class="text-red-400">*</span></label>
                        <input type="text" name="first_name" required
                               value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"
                               class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Middle Name</label>
                        <input type="text" name="middle_name"
                               value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>"
                               class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Last Name <span class="text-red-400">*</span></label>
                        <input type="text" name="last_name" required
                               value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"
                               class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                    </div>
                </div>

                <!-- Username & Email -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Username <span class="text-red-400">*</span></label>
                        <input type="text" name="username" required
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Email <span class="text-red-400">*</span></label>
                        <input type="email" name="email" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                    </div>
                </div>

                <!-- Phone & Birthdate -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Phone Number</label>
                        <input type="tel" name="phone" placeholder="+63 912 345 6789"
                               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                               class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Birth Date</label>
                        <input type="date" name="birthdate"
                               value="<?= htmlspecialchars($_POST['birthdate'] ?? '') ?>"
                               class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                    </div>
                </div>

                <!-- Gender & Role -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Gender</label>
                        <select name="gender" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                            <option value="">Select</option>
                            <option value="Male"   <?= (($_POST['gender'] ?? '') === 'Male')   ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= (($_POST['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                            <option value="Other"  <?= (($_POST['gender'] ?? '') === 'Other')  ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Role <span class="text-red-400">*</span></label>
                        <select name="role" class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                            <option value="staff" <?= (($_POST['role'] ?? 'staff') === 'staff') ? 'selected' : '' ?>>Staff</option>
                            <option value="admin" <?= (($_POST['role'] ?? '') === 'admin')       ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>
                </div>

                <!-- Address -->
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">Address</label>
                    <textarea name="address" rows="2" placeholder="Street, City, Province"
                              class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                </div>

                <!-- Password -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Password <span class="text-red-400">*</span></label>
                        <input type="password" name="password" id="password" required minlength="6"
                               class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                        <p class="text-xs text-slate-500 mt-1">Minimum 6 characters</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Confirm Password <span class="text-red-400">*</span></label>
                        <input type="password" name="confirm_password" id="confirmPassword" required
                               class="w-full px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                        <div id="passwordMatchIndicator" class="password-match-indicator"></div>
                    </div>
                </div>

                <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg transition text-sm mt-2">
                    Create Account
                </button>
            </form>
        </div>
    </div>

    <script>
        const password        = document.getElementById('password');
        const confirmPassword = document.getElementById('confirmPassword');
        const indicator       = document.getElementById('passwordMatchIndicator');

        function checkPasswordMatch() {
            if (confirmPassword.value.length === 0) { indicator.innerHTML = ''; return; }
            if (password.value === confirmPassword.value) {
                indicator.innerHTML   = '✓ Passwords match';
                indicator.className   = 'password-match-indicator match-success';
            } else {
                indicator.innerHTML   = '✗ Passwords do not match';
                indicator.className   = 'password-match-indicator match-error';
            }
        }

        password.addEventListener('input', checkPasswordMatch);
        confirmPassword.addEventListener('input', checkPasswordMatch);
    </script>
</body>
</html>