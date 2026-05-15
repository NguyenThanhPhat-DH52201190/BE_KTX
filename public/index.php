<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Xác định xem ứng dụng có đang ở chế độ bảo trì hay không...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Đăng ký trình nạp tự động của Composer...
require __DIR__.'/../vendor/autoload.php';

// Khởi động Laravel và xử lý yêu cầu...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
