<?php

return [

    // How many backups to keep. The oldest are deleted only after a new one has uploaded.
    'number_of_backups_allowed' => 3,

    // Filesystem disk to upload to, from config/filesystems.php.
    'disk' => env('S3_BACKUP_DISK', 's3'),

    // Folder on the disk. Rotation only ever looks inside this folder, and only at
    // files named like a backup, so the bucket can safely hold other things.
    'path' => env('S3_BACKUP_PATH', 'backups'),

    // Database connection to dump. null uses the app's default connection.
    'connection' => env('S3_BACKUP_CONNECTION'),

    // Gzip the dump before uploading (needs the zlib extension).
    'gzip' => true,

    // The mysqldump binary, if it isn't on the PATH.
    'mysqldump' => env('S3_BACKUP_MYSQLDUMP', 'mysqldump'),

];
