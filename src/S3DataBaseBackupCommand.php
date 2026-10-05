<?php

namespace WittyFox\S3;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class S3DataBaseBackupCommand extends Command
{
    protected $signature = 'db:backupS3';

    protected $description = 'Create a backup of the database on s3';

    /** Only files matching this are ever treated as backups (and so ever rotated away). */
    private const BACKUP_NAME = '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql(\.gz)?$/';

    public function handle(): int
    {
        $connection = config('s3backup.connection') ?: config('database.default');
        $db = config("database.connections.{$connection}");

        if (! is_array($db) || ! in_array($db['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error("Connection [{$connection}] is not a MySQL/MariaDB connection.");

            return self::FAILURE;
        }

        $disk = Storage::disk(config('s3backup.disk', 's3'));
        $directory = trim((string) config('s3backup.path', 'backups'), '/');
        $keep = max(1, (int) config('s3backup.number_of_backups_allowed', 3));

        $workDir = storage_path('app/backups');
        File::ensureDirectoryExists($workDir, 0700);

        $dumpPath = $workDir.'/'.date('Y-m-d_H-i-s').'.sql';
        $credentialsPath = $workDir.'/.my-'.bin2hex(random_bytes(8)).'.cnf';

        try {
            $this->writeCredentials($credentialsPath, $db);
            $this->dump($credentialsPath, $db['database'], $dumpPath);

            $uploadPath = config('s3backup.gzip', true) ? $this->gzip($dumpPath) : $dumpPath;
            $remotePath = ltrim($directory.'/'.basename($uploadPath), '/');

            $this->upload($disk, $uploadPath, $remotePath);
            $this->info("Uploaded {$remotePath}");

            // Only now that a good backup is safely stored do we make room for it.
            $this->rotate($disk, $directory, $keep);
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            File::delete([$credentialsPath, $dumpPath, $dumpPath.'.gz']);
        }

        return self::SUCCESS;
    }

    /**
     * Credentials go in a private option file rather than on the command line, where any
     * user on the machine could read them from the process list, and where shell
     * characters in the password would break the command.
     */
    private function writeCredentials(string $path, array $db): void
    {
        $options = [
            'user' => $db['username'] ?? null,
            'password' => $db['password'] ?? null,
            'host' => $db['host'] ?? null,
            'port' => $db['port'] ?? null,
            'socket' => $db['unix_socket'] ?? null,
        ];

        $lines = ['[client]'];
        foreach ($options as $key => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = $key.'="'.addcslashes((string) $value, "\\\n\r\t").'"';
            }
        }

        touch($path);
        chmod($path, 0600);
        file_put_contents($path, implode("\n", $lines)."\n");
    }

    private function dump(string $credentialsPath, string $database, string $dumpPath): void
    {
        $command = implode(' ', array_map('escapeshellarg', [
            config('s3backup.mysqldump', 'mysqldump'),
            '--defaults-extra-file='.$credentialsPath, // must be the first option
            '--single-transaction', // consistent snapshot without locking the tables
            '--quick',
            '--no-tablespaces',
            '--result-file='.$dumpPath,
            $database,
        ])).' 2>&1';

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException("mysqldump exited with code {$exitCode}: ".trim(implode("\n", $output)));
        }

        // mysqldump writes this footer last, so its absence means the dump was cut short.
        $size = is_file($dumpPath) ? filesize($dumpPath) : 0;
        $tail = $size > 0 ? file_get_contents($dumpPath, false, null, max(0, $size - 512)) : '';

        if (! str_contains($tail, '-- Dump completed')) {
            throw new RuntimeException('mysqldump produced an incomplete dump.');
        }
    }

    private function gzip(string $path): string
    {
        if (! function_exists('gzopen')) {
            throw new RuntimeException('The zlib extension is required when s3backup.gzip is enabled.');
        }

        $gzPath = $path.'.gz';
        $in = fopen($path, 'rb');
        $out = gzopen($gzPath, 'wb6');

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);

                if ($chunk === false || gzwrite($out, $chunk) === false) {
                    throw new RuntimeException('Failed to compress the dump.');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }

        File::delete($path);

        return $gzPath;
    }

    private function upload(Filesystem $disk, string $localPath, string $remotePath): void
    {
        $stream = fopen($localPath, 'rb');

        try {
            $uploaded = $disk->writeStream($remotePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $uploaded) {
            throw new RuntimeException("Upload of [{$remotePath}] failed.");
        }
    }

    /** Delete the oldest backups beyond $keep. Never touches files that aren't backups. */
    private function rotate(Filesystem $disk, string $directory, int $keep): void
    {
        $backups = collect($disk->files($directory))
            ->filter(fn (string $file) => preg_match(self::BACKUP_NAME, basename($file)))
            ->sort()
            ->values();

        foreach ($backups->slice(0, max(0, $backups->count() - $keep)) as $old) {
            $disk->delete($old);
            $this->line("Deleted old backup {$old}");
        }
    }
}
