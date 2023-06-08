<?php

namespace WittyFox\S3;

use Illuminate\Support\ServiceProvider;

class S3BackupServiceProvider extends ServiceProvider
{
    public function boot()
    {

    }

    public function register()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                S3DataBaseBackupCommand::class,
            ]);
        }

        $this->mergeConfigFrom(__DIR__.'/config/s3backup.php', 's3-backup');

        $this->publishes([
            __DIR__.'/config/s3backup.php' => config_path('s3backup.php'),
        ], 's3-backup');

    }
}
