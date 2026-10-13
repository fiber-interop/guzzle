<?php
// Router for the php -S fixture started by tests/bootstrap.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/slow':
        usleep((int) ($_GET['ms'] ?? 200) * 1000);
        echo 'slow';
        break;
    case '/status':
        http_response_code((int) $_GET['code']);
        echo 'status ' . $_GET['code'];
        break;
    default:
        header('Content-Type: text/plain');
        echo 'hello ' . $_SERVER['REQUEST_METHOD'];
}
