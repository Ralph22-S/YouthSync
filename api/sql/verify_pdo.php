<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
echo 'pdo_ok db=' . youthsync_env('DB_NAME') . ' port=' . youthsync_env('DB_PORT') . PHP_EOL;
echo 'tables=' . implode(',', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
echo 'sk1=' . $pdo->query("SELECT email FROM users WHERE email='sk1@demo.test'")->fetchColumn() . PHP_EOL;
