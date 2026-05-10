# Edarat365 — Backend API

Laravel-based REST API for the Edarat365 owners-association management platform.

## Stack

- PHP 8.3
- Laravel 11
- MySQL 8
- Sanctum (token authentication)

## Local Development

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## License

Proprietary. All rights reserved — منصة إدارات 365.
