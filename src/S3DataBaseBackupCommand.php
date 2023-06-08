<?php

namespace WittyFox\S3;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class S3DataBaseBackupCommand extends Command
{
    protected $signature = 'db:backupS3';

    protected $description = 'Create a backup of the database on s3';

    public function handle()
    {
        $directoryPath = storage_path('app/backups');

        if (!File::exists($directoryPath)) {
            File::makeDirectory($directoryPath, 0755, true, true);
        }

        $allBackups = Storage::disk('s3')->allFiles();
        $oldestFileIndex = null;
        $oldestTimestamp = null;

        if (count($allBackups) >= 3) {
            foreach ($allBackups as $index => $fileName) {
                $timestamp = substr($fileName, 0, -4); // Remove the ".sql" extension
                if ($oldestTimestamp === null || $timestamp < $oldestTimestamp) {
                    $oldestTimestamp = $timestamp;
                    $oldestFileIndex = $index;
                }
            }
            $oldestFile = $allBackups[$oldestFileIndex];
            Storage::disk('s3')->delete($oldestFile);
        }


        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');
        $backupPath = storage_path('app/backups/' . date('Y-m-d_H-i-s') . '.sql');

        $command = sprintf(
            'mysqldump --user=%s --password=%s --host=%s %s > %s',
            $username,
            $password,
            $host,
            $database,
            $backupPath
        );

        exec($command);

        $folderPath = storage_path('app/backups/');

        $allBackups = scandir($folderPath);

        $allBackups = array_diff($allBackups, array('.', '..'));

        $latestBackup = reset($allBackups);


        $filePath = storage_path('app/backups/' . $latestBackup);

        $upload = Storage::disk('s3')->put('/' . $latestBackup, file_get_contents($filePath));

        if ($upload) {

            /* Delete all that are not latest from local storage */
            foreach ($allBackups as $backup) {
                unlink(storage_path('app/backups/' . $backup));
            }
        }
    }
}
