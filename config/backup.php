<?php

return [
    'disk' => env('BACKUP_DISK', 'backup'),
    'directory' => 'database',
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'mysql_binary' => env('MYSQL_BINARY', 'mysql'),
    'restore_test_database' => env('BACKUP_RESTORE_TEST_DATABASE'),
];
