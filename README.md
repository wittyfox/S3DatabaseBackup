This is a simple package that backs up your MySQL/MariaDB database and uploads it to S3. The number of backups to keep can be configured. It can be run manually or on a schedule.

## Installation

```
composer require wittyfox/s3-backup
```

```
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
```

`mysqldump` must be installed on the server.

Optionally publish the config:

```
php artisan vendor:publish --tag=s3-backup
```

This publishes `config/s3backup.php`, where you can set:

| Option | Default | |
|---|---|---|
| `number_of_backups_allowed` | `3` | How many backups to keep. |
| `disk` | `s3` | Filesystem disk to upload to. |
| `path` | `backups` | Folder on the disk. |
| `connection` | default connection | Database connection to dump. |
| `gzip` | `true` | Compress the dump before uploading. |
| `mysqldump` | `mysqldump` | Path to the binary, if it isn't on the `PATH`. |

## Usage

```
php artisan db:backupS3
```

To run it nightly, add it to the scheduler (`routes/console.php`):

```php
Schedule::command('db:backupS3')->dailyAt('03:00')->withoutOverlapping();
```

The command exits with a failure code if anything goes wrong, so `->emailOutputOnFailure(...)` or your
monitoring will catch it.

## How it behaves

- **Old backups are only deleted after a new one has uploaded.** A failed or incomplete dump uploads
  nothing and leaves the existing backups alone.
- **Rotation only touches backups.** It looks inside the `path` folder, and only at files named like a
  backup (`2026-10-05_03-00-00.sql.gz`), so the bucket can hold other files.
- **The tables are not locked.** The dump uses `--single-transaction`, so the app keeps working while it
  runs. (This gives a consistent snapshot for InnoDB tables, which is the MySQL default.)
- **Credentials stay off the command line.** They go to a temporary `0600` option file, so they don't
  appear in the process list and any characters in the password work.

## Restoring

```
aws s3 cp s3://YOUR_BUCKET/backups/2026-10-05_03-00-00.sql.gz .
gunzip < 2026-10-05_03-00-00.sql.gz | mysql -u USER -p DATABASE
```

Test a restore into a scratch database now and then — a backup you've never restored is a guess.

## Upgrading from 1.0

Backups now go into a `backups/` folder instead of the bucket root, and are gzipped. Backups made by
1.0 at the bucket root are not rotated away; delete them by hand when you no longer need them, or set
`S3_BACKUP_PATH=` (empty) to keep using the root.
