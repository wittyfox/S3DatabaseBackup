<?php

namespace WittyFox\S3;

use Illuminate\Support\ServiceProvider;

class S3BackupServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                S3DataBaseBackupCommand::class,
            ]);
        }
    }

    public function register()
    {

    }
}
