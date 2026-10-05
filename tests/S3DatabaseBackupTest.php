<?php

namespace WittyFox\S3\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use WittyFox\S3\S3BackupServiceProvider;

class S3DatabaseBackupTest extends TestCase
{
    private string $argsFile;

    private string $cnfFile;

    protected function getPackageProviders($app)
    {
        return [S3BackupServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'game',
            'username' => 'backup',
            'password' => 'pa$$ "word" `rm -rf` \\ #1',
        ]);
        $app['config']->set('s3backup.mysqldump', __DIR__.'/fixtures/fake-mysqldump');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->argsFile = tempnam(sys_get_temp_dir(), 'args');
        $this->cnfFile = tempnam(sys_get_temp_dir(), 'cnf');
        putenv("FAKE_MYSQLDUMP_ARGS={$this->argsFile}");
        putenv("FAKE_MYSQLDUMP_CNF={$this->cnfFile}");
        putenv('FAKE_MYSQLDUMP_MODE=ok');
    }

    protected function tearDown(): void
    {
        putenv('FAKE_MYSQLDUMP_ARGS');
        putenv('FAKE_MYSQLDUMP_CNF');
        putenv('FAKE_MYSQLDUMP_MODE');
        @unlink($this->argsFile);
        @unlink($this->cnfFile);

        parent::tearDown();
    }

    public function test_it_uploads_a_gzipped_dump_and_cleans_up_locally()
    {
        $this->artisan('db:backupS3')->assertSuccessful();

        $files = Storage::disk('s3')->files('backups');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('#^backups/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql\.gz$#', $files[0]);
        $this->assertStringContainsString(
            '-- Dump completed',
            gzdecode(Storage::disk('s3')->get($files[0]))
        );

        // No dump and no credentials file left behind.
        $this->assertSame([], File::allFiles(storage_path('app/backups'), true));
    }

    public function test_it_can_upload_without_gzip()
    {
        config(['s3backup.gzip' => false]);

        $this->artisan('db:backupS3')->assertSuccessful();

        $files = Storage::disk('s3')->files('backups');
        $this->assertStringEndsWith('.sql', $files[0]);
    }

    public function test_a_failed_dump_uploads_nothing_and_keeps_existing_backups()
    {
        $this->seedBackups(3);
        putenv('FAKE_MYSQLDUMP_MODE=fail');

        $this->artisan('db:backupS3')
            ->expectsOutputToContain('Access denied')
            ->assertFailed();

        $this->assertSame($this->seededNames(3), Storage::disk('s3')->files('backups'));
    }

    public function test_an_incomplete_dump_is_rejected()
    {
        $this->seedBackups(3);
        putenv('FAKE_MYSQLDUMP_MODE=partial');

        $this->artisan('db:backupS3')
            ->expectsOutputToContain('incomplete')
            ->assertFailed();

        $this->assertSame($this->seededNames(3), Storage::disk('s3')->files('backups'));
    }

    public function test_it_keeps_only_the_newest_backups_and_ignores_other_files()
    {
        $this->seedBackups(3);
        Storage::disk('s3')->put('backups/notes.txt', 'keep me');
        Storage::disk('s3')->put('avatars/player.png', 'keep me too');

        $this->artisan('db:backupS3')->assertSuccessful();

        $backups = array_values(array_filter(
            Storage::disk('s3')->files('backups'),
            fn ($f) => str_ends_with($f, '.gz')
        ));
        $this->assertCount(3, $backups);
        $this->assertNotContains('backups/2020-01-01_00-00-00.sql.gz', $backups);
        $this->assertTrue(Storage::disk('s3')->exists('backups/notes.txt'));
        $this->assertTrue(Storage::disk('s3')->exists('avatars/player.png'));
    }

    public function test_the_default_retention_applies_without_publishing_the_config()
    {
        $this->assertSame(3, config('s3backup.number_of_backups_allowed'));
    }

    public function test_credentials_are_not_passed_on_the_command_line()
    {
        $this->artisan('db:backupS3')->assertSuccessful();

        $args = file_get_contents($this->argsFile);
        $this->assertStringNotContainsString('pa$$', $args);
        $this->assertStringStartsWith('--defaults-extra-file=', $args);
        $this->assertStringContainsString('--single-transaction', $args);

        $cnf = file_get_contents($this->cnfFile);
        $this->assertStringContainsString('user="backup"', $cnf);
        $this->assertStringContainsString('password="pa$$ "word" `rm -rf` \\\\ #1"', $cnf);
    }

    public function test_it_refuses_a_non_mysql_connection()
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $this->artisan('db:backupS3')
            ->expectsOutputToContain('not a MySQL/MariaDB connection')
            ->assertFailed();
    }

    private function seedBackups(int $count): void
    {
        foreach ($this->seededNames($count) as $name) {
            Storage::disk('s3')->put($name, 'old backup');
        }
    }

    private function seededNames(int $count): array
    {
        return array_map(
            fn ($i) => sprintf('backups/2020-01-0%d_00-00-00.sql.gz', $i + 1),
            range(0, $count - 1)
        );
    }
}
