<?php
// logout.php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// очистить данные сессии
$_SESSION = [];

// удалить cookie сессии
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// уничтожить сессию на сервере
session_destroy();

// на главную
header('Location: index.php');
exit;
