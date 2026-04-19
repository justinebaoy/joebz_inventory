<?php
session_start();
require_once 'config/db.php';


// Properly clear session data before destroying
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);

}
session_destroy();
header('Location: ' . BASE_URL . '/index.php');
exit; 