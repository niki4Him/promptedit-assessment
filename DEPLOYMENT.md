# Deploy PromptEdit on Render Free

Use the free plans for a temporary assessment demo. The web service sleeps after 15 minutes of inactivity and can take about a minute to wake. Free PostgreSQL has 1 GB of storage and expires 30 days after creation. The web filesystem is ephemeral.

## Create the services

1. In the Render Dashboard, choose **New → Postgres**, select **Free**, and choose a region. Once the database is ready, copy its **Internal Database URL** from **Connect**. Remove external network access if all database clients will run inside Render.
2. Choose **New → Web Service** and connect the repository and branch containing the deployment files. Select **Docker**, choose the **same region and workspace** as PostgreSQL, and select **Free**.
3. Use the repository root as the build context and `./Dockerfile` as the Dockerfile path. Leave **Docker Command** and **Pre-Deploy Command** blank. Under **Advanced**, set **Health Check Path** to `/up`.
4. Add the environment variables below and deploy. The first start applies migrations before the service starts accepting traffic. Check the deploy logs for the migration result and visit the HTTPS service URL and its `/up` endpoint.

## Environment variables

| Name                    | Value                                                                                                                         |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `APP_NAME`              | `PromptEdit`                                                                                                                  |
| `APP_ENV`               | `production`                                                                                                                  |
| `APP_DEBUG`             | `false`                                                                                                                       |
| `APP_KEY`               | Generate once with `php artisan key:generate --show` locally. Save the output in Render and keep the same key across deploys. |
| `APP_URL`               | The exact HTTPS public origin, such as `https://your-service.onrender.com`, without a trailing slash.                         |
| `DB_CONNECTION`         | `pgsql`                                                                                                                       |
| `DB_URL`                | The full **Internal Database URL** from Render PostgreSQL. Treat it as a secret.                                              |
| `DB_SSLMODE`            | `require`                                                                                                                     |
| `SESSION_DRIVER`        | `database`                                                                                                                    |
| `SESSION_SECURE_COOKIE` | `true`                                                                                                                        |
| `CACHE_STORE`           | `database`                                                                                                                    |
| `LOG_CHANNEL`           | `stderr`                                                                                                                      |
| `LOG_LEVEL`             | `info`                                                                                                                        |
| `PORT`                  | Leave Render's default `10000`. The container honors an override.                                                             |

Configure mail before inviting users to register. Use `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtp`, the provider's `MAIL_HOST`, `MAIL_PORT=2525` with STARTTLS if supported, `MAIL_USERNAME`, `MAIL_PASSWORD`, a verified `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME=PromptEdit`. Render Free blocks outbound SMTP on ports 25, 465, and 587. The image defaults to `MAIL_MAILER=array`, which delivers no email and keeps verification and reset links out of service logs. Verify email delivery and that signed links use the public HTTPS origin before sharing the demo.

## Enable paid generations only after confirming spending limits

Leave `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` unset on the first deploy. The app blocks submissions and rejects unsigned or unconfigured webhook deliveries. After confirming an acceptable provider-side spending limit, add the credentials in Render's Environment settings and configure Pixelforge to send signed webhooks to `https://your-service.onrender.com/webhooks/pixelforge`. Every verified account can submit once the API key is set. Keep credentials in Render's runtime environment. Never commit them or add them to Docker build arguments.

## Startup and free-tier limits

Each container start runs `php artisan migrate --force --no-interaction` against PostgreSQL. Already-applied migrations are skipped. If a migration fails, the HTTP server stays down. Fix the reported cause and redeploy. Keep future migrations compatible with the previous release because an old instance can still serve during rollout.

The image serves `public/` with three FrankenPHP request threads. Node and Composer are build-time only. The database holds sessions and cache, including submission rate-limit counters. Render handles TLS. Caddy tells Laravel to generate HTTPS URLs. For local HTTP-only container testing, set `HTTPS=off` and `SESSION_SECURE_COOKIE=false`.

The runtime image removes the base FrankenPHP binary's `cap_net_bind_service` file capability before switching to `www-data`. Render's restricted capability set can otherwise reject execution with `Operation not permitted` (exit 126), even though the binary is executable. Port `10000` does not require this capability. Keep the startup script's `exec "$@"` so FrankenPHP receives container signals directly.

Cold starts can delay webhook acknowledgments. Verify delivery and manual recovery before enabling paid usage. Free PostgreSQL has no managed backups. Export any data you need before expiry.

[Render free-tier limits](https://render.com/docs/free) · [Render Docker deployments](https://render.com/docs/docker) · [Render PostgreSQL connections](https://render.com/docs/postgresql-creating-connecting)
