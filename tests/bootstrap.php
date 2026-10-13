<?php
/**
 * FI_RUNTIME=revolt|amp|react|phasync|swoole selects the adapter (default revolt).
 * FI_DRIVER=generic|native as in fiber-interop/curl.
 */
require __DIR__ . '/../vendor/autoload.php';
if (!class_exists('Scope')) {
    require_once __DIR__ . '/../vendor/fiber-interop/api/tests/Support/ScopeStub.php';
}

$runtime = getenv('FI_RUNTIME') ?: 'revolt';
$class = 'FiberInterop\\' . ucfirst($runtime) . '\\Adapter';
async\setup(new $class());

$driver = getenv('FI_DRIVER') ?: 'generic';
if ($driver === 'generic') {
    async\curl\setup(new async\curl\MultiDriver());
} elseif (async\curl\Registry::$candidates === []) {
    fwrite(STDERR, "No native async\\curl driver registered for $runtime\n");
    exit(1);
}
fwrite(STDERR, "runtime=$runtime driver=" . get_class(async\curl\driver()) . "\n");

$port = 19000 + getmypid() % 1000;
define('FI_BASE', "http://127.0.0.1:$port");
$proc = proc_open([PHP_BINARY, '-n', '-S', "127.0.0.1:$port", __DIR__ . '/server.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes,
    null, ['PHP_CLI_SERVER_WORKERS' => '16']);
register_shutdown_function(static function () use ($proc) {
    proc_terminate($proc);
});
for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(20000);
}
