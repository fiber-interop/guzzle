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
    case '/chunks':
        // n chunks of 'chunk<i>' with ms between them; trunc=1 promises more than it sends.
        $n = (int) ($_GET['n'] ?? 5);
        header('Content-Length: ' . ($n * 6 + (isset($_GET['trunc']) ? 1000 : 0)));
        for ($i = 0; $i < $n; $i++) {
            if ($i > 0) {
                usleep((int) ($_GET['ms'] ?? 200) * 1000);
            }
            echo "chunk$i";
            flush();
        }
        break;
    case '/big':
        header('Content-Length: ' . (8 << 20));
        $s = str_repeat('x', 65536);
        for ($i = 0; $i < 128; $i++) {
            echo $s;
        }
        break;
    default:
        header('Content-Type: text/plain');
        echo 'hello ' . $_SERVER['REQUEST_METHOD'];
}
