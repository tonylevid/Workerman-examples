<?php

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;

if (strpos(strtolower(PHP_OS), 'win') === 0) {
    exit("Do not support windows\n");
}
if (!extension_loaded('pcntl')) {
    exit("Please install pcntl extension. See http://doc3.workerman.net/appendices/install-extension.html\n");
}
if (!extension_loaded('posix')) {
    exit("Please install posix extension. See http://doc3.workerman.net/appendices/install-extension.html\n");
}
// 标记是全局启动
define('GLOBAL_START', 1);
// 加载所有服务文件，以便启动所有服务
foreach (glob(__DIR__ . '/services/service_*.php') as $service) {
    require_once $service;
}
// 运行所有服务
Worker::runAll();
