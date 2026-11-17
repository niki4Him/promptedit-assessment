# PromptEdit assessment

PromptEdit is a Laravel 13 and Vue/Inertia app for submitting Pixelforge image generations and reviewing their status and cost. The Generations page shows a server-calculated estimate, then records the final charge from Pixelforge's actual token usage and frozen rates. Amounts are stored as integer USD nanodollars.

## Local setup

Use PHP 8.4.1 or newer with Composer 2, Node 24.11+ on the Node 24 line, npm, and PDO SQLite.

    composer install
    cp .env.example .env
    php artisan key:generate
    touch database/database.sqlite
    php artisan migrate
    npm ci
    npm run build
    php artisan serve

Create the local .env on a fresh checkout. Set PIXELFORGE_API_KEY there to enable submissions and PIXELFORGE_WEBHOOK_SECRET to enable signed webhook delivery. Use a publicly reachable HTTPS endpoint for live deliveries. The test suite uses fakes and requires neither credential.

    php artisan test
    vendor/bin/pint --test
    vendor/bin/phpstan analyse --memory-limit=512M
    npm run types:check
    npm run build

## Laravel Cloud

Use the normal Laravel web runtime with PHP 8.4.1+ and a compatible Node version for the build. Attach a managed PostgreSQL database. Cloud's local filesystem is ephemeral, so keep SQLite for local development. Use database-backed sessions and cache. Image history stores Pixelforge's signed URLs, which expire, and requires no persistent local image storage.

Set APP_NAME, APP_ENV=production, APP_DEBUG=false, a stable APP_KEY, APP_URL with the canonical HTTPS origin, SESSION_SECURE_COOKIE=true, SESSION_DRIVER=database, and CACHE_STORE=database. Cloud injects the attached database settings. Provide PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET as secrets. PIXELFORGE_BASE_URL defaults to the official provider. Use a real transactional mail transport and a valid sender for password resets. Send application logs to stderr with LOG_CHANNEL=stderr and an appropriate LOG_LEVEL.

Cloud build commands

    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    npm ci --no-audit
    npm run build
    php artisan optimize

Cloud deploy command

    php artisan migrate --force

Let Cloud serve the public directory and use /up as its health endpoint. Register the final HTTPS /webhooks/pixelforge URL against the production Pixelforge key. Verify migrations, authentication, reset email, and a signed webhook in staging before public release. Keep the same Pixelforge account when replaying pending submissions, because provider idempotency keys are account-scoped.

[Laravel deployment guide](https://laravel.com/docs/13.x/deployment) · [Laravel Cloud guide](https://laravel.com/cloud/docs/deploy-guides/laravel) · [Pixelforge API reference](https://pixelforge.promptedit.dev/docs)
