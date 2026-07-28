# AGENTS.md

This file provides guidance to Qoder (qoder.com) when working with code in this repository.

## Project Overview

V2Board (wyx2685 fork) is a proxy-protocol subscription management panel built on **Laravel 8** (PHP 7.3+/8.x), MySQL 5.5+, and Redis. Redis is required for both cache and queue (Horizon). Supported node backends: modified V2bX and v2node.

## Common Commands

```bash
composer install                  # install dependencies (init.sh also adds joanhey/adapterman on PHP 8)
php artisan v2board:install       # first-time install: imports database/install.sql, creates admin
php artisan v2board:update        # upgrade: runs config:cache, imports database/update.sql, restarts Horizon
php artisan config:clear && php artisan config:cache   # required after changing config/v2board.php or .env
php artisan horizon               # queue worker (Horizon); restart with: php artisan horizon:terminate
php artisan schedule:run          # cron entry point (traffic:update, check:order, reset:traffic, etc. in app/Console/Kernel.php)

# Tests (PHPUnit 9, suites: Unit + Feature in tests/)
vendor/bin/phpunit
vendor/bin/phpunit --filter testName tests/Feature/ExampleTest.php   # single test
```

Production deployments use `./update.sh` (git reset --hard origin/master + composer update + v2board:update). On PHP 8 an optional Workerman/AdapterMan runtime exists: `php -c cli-php.ini webman.php start` (listens on 127.0.0.1:6600, loads `start.php` per worker).

There is no linter or migration system configured — schema changes go into `database/install.sql` (fresh) **and** `database/update.sql` (idempotent; update errors are silently swallowed, so statements must be safe to re-run).

## Architecture

### Configuration is a generated PHP file, not the DB

All panel settings live in `config/v2board.php`, accessed everywhere via `config('v2board.*')`. The admin ConfigController **rewrites this file with `var_export()`** on save. Because production runs with config cached, any change to it requires `php artisan config:cache` to take effect. Never hand-edit assuming DB storage.

### Routing: auto-discovered route classes

`routes/web.php` only serves the user theme, the admin SPA (path derived from `v2board.secure_path`), and an optional custom subscribe path. All API routes come from classes in `app/Http/Routes/V1` and `V2`, each with a `map($router)` method, auto-globbed by `RouteServiceProvider` under `/api/v1` and `/api/v2`. Controllers in `app/Http/Controllers/V1/` mirror the role split:

- **Passport** – login/register, issues JWT (firebase/php-jwt signed with `app.key`; sessions cached in Redis via `AuthService`)
- **User / Staff / Admin** – authenticated panel APIs, guarded by `user` / `staff` / `admin` middleware
- **Guest** – unauthenticated (payment notify callbacks, Telegram webhook)
- **Client** – subscription delivery, guarded by `client` middleware (per-user `token` query param, with optional OTP/TOTP token modes)
- **Server** (V1 + V2) – node backend communication (`UniProxyController` etc.), authenticated by the shared `v2board.server_token`; responses support msgpack + ETag/304

Form validation lives in `app/Http/Requests/{Admin,User,Passport,...}`.

### Plugin-by-convention subsystems (drop in a class, no registration)

Three subsystems discover classes dynamically — adding a file is the whole integration:

1. **Subscription protocols** (`app/Protocols/*.php`): each class has a `public $flag` and `handle()`. `ClientController@subscribe` matches the client User-Agent against each flag (files globbed in reverse alphabetical order); `General` (base64 URI list) is the fallback; sing-box is special-cased with version detection (`Singbox` vs `SingboxOld`).
2. **Payment gateways** (`app/Payments/*.php`): instantiated by class name via `PaymentService`. Each must implement `form()` (admin config fields), `pay($order)`, and `notify($params)`.
3. **Themes** (`public/theme/<name>`): initialized by `ThemeService` into `config/theme/`.

### Server/node model

Each protocol has its own table/model (`ServerVmess`, `ServerVless`, `ServerTrojan`, `ServerHysteria`, `ServerTuic`, `ServerShadowsocks`, `ServerAnytls`, `ServerV2node`) plus `ServerGroup` (permission groups) and `ServerRoute`. `ServerService::getAvailableServers($user)` merges all types into one sorted list with a `type` field — protocol classes and node APIs consume this unified shape. Traffic reporting flows through `TrafficFetchJob`/`StatServerJob`/`StatUserJob` queue jobs and is aggregated by scheduled commands.

### Business logic layering

Controllers stay thin; shared logic lives in `app/Services` (OrderService, UserService, CouponService, TelegramService, MailService...). Async work (mail, Telegram, stats, order handling) is dispatched as jobs in `app/Jobs` and processed by Horizon — code that must run after a request depends on Horizon actually running.

## Conventions

- API responses use the `{'data': ...}` envelope; errors are thrown with `abort(4xx/5xx, 'message')`. User-facing messages are Chinese with i18n keys in `resources/lang/`.
- Frontends are prebuilt static assets (`public/assets/admin` for admin SPA, `public/theme/` for user themes) — there is no JS build pipeline in this repo.
- Cache keys must be declared in `App\Utils\CacheKey` before use.
