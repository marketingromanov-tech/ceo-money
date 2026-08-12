<?php

return [
    'disk' => env('BACKUP_DISK', 'backup'),
    'directory' => 'database',
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'mysql_binary' => env('MYSQL_BINARY', 'mysql'),
    'restore_test_database' => env('BACKUP_RESTORE_TEST_DATABASE'),
    'min_free_bytes' => (int) env('BACKUP_MIN_FREE_BYTES', 1073741824),
    'database' => [
        'host' => env('BACKUP_DB_HOST'),
        'port' => env('BACKUP_DB_PORT', 3306),
        'username' => env('BACKUP_DB_USERNAME'),
        'password' => env('BACKUP_DB_PASSWORD'),
    ],
];
