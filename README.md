
## Instalation

```composer require wittyfox/s3-backup```


```
AWS_ACCESS_KEY_ID=

AWS_SECRET_ACCESS_KEY=

AWS_DEFAULT_REGION=

AWS_BUCKET=
```

```
php artisan vendor:publish --tag=s3-backup
```
This will publish a s3backup.php config file in your /config directory. You can adjust how many backups your S3 bucket can store/hold.
<hr>

## Usage

<hr>

php artisan db:backupS3

