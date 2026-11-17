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

## Verification email delivery

Email verification stays enabled and is required to access Generations. The image defaults to `MAIL_MAILER=array`, which delivers no email. The built-in verification notification sends synchronously, so no queue worker is needed for this setup.

Recommended: Resend Free via Laravel's existing SMTP transport (no additional package). The free tier provides 3,000 emails/month with a 100/day limit. You need a domain you control; the app can keep its `onrender.com` URL, but that shared domain cannot be verified as your sender domain. Add a sending domain/subdomain in Resend, publish its required SPF/DKIM records in your DNS, and follow its DMARC guidance. Wait for verification, then create a sending API key.

Set these values in Render's runtime Environment settings, replacing the sender and secret:

```dotenv
APP_URL=https://promptedit-assessment.onrender.com
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=2587
MAIL_USERNAME=resend
MAIL_PASSWORD=<Resend API key>
MAIL_FROM_ADDRESS=verify@<your verified sending domain>
MAIL_FROM_NAME=PromptEdit
```

Use STARTTLS on port 2587; Symfony's SMTP transport automatically upgrades when the server advertises STARTTLS. Remove any stale `MAIL_URL` override. Do not set `MAIL_SCHEME=tls` (the transport schemes are `smtp` and `smtps`). Render Free blocks outbound ports 25, 465, and 587. Save/apply these environment values when ready to restart/deploy. Keep the existing stable `APP_KEY`.

Register with an inbox you control, confirm receipt (including spam), follow the signed HTTPS verification link, and confirm access to `/generations`. Also check resend and password-reset delivery. Resend's testing sender is not a substitute for verifying a domain to send to reviewers. A free email service does not include buying a custom domain if you do not already own one.

[Resend pricing](https://resend.com/pricing) · [Resend SMTP](https://resend.com/docs/send-with-smtp) · [Domain verification](https://resend.com/docs/dashboard/domains/introduction)

## Configure Pixelforge and reviewer history

Pixelforge is simulated: no real model runs and no real money is charged. Its usage and pricing still need to be calculated correctly for the assessment.

1. In the Pixelforge dashboard, create/select a key for this deployment. Each key has its own webhook URL and signing secret.
2. Set that key's webhook URL to `https://promptedit-assessment.onrender.com/webhooks/pixelforge`.
3. In Render's runtime Environment settings, set `PIXELFORGE_API_KEY` to that key and `PIXELFORGE_WEBHOOK_SECRET` to its matching signing secret. Leave `PIXELFORGE_BASE_URL` unset to use `https://pixelforge.promptedit.dev`. The image blocks submissions without a key and rejects webhook deliveries without a valid matching secret.
4. Once the configuration is applied, send a test event from Pixelforge and confirm HTTP 204 in its delivery log.
5. Create an account using a working inbox and complete email verification. Generate at least three `[succeed]` examples with different sizes/qualities. Add `[duplicate]` to one prompt and verify duplicate deliveries do not change the settled charge. Test `[fail]` separately and confirm a zero actual charge. Confirm history updates without a manual page reload and compare provider usage/rates with the displayed final charge including the 25% markup.
6. History is private to each user. Provide reviewers a dedicated demo account through the assessment handoff, with those completed examples in that account's history, or explicitly arrange their own registration/verification. Never commit its password or any secrets. Verify the GitHub repository and deployed URL both open in a signed-out browser.

### Image URL expiration

The official Images documentation states that URLs expire 24 hours after generation completion. `GET /v1/generations/{id}` returns a fresh URL with the **same expiration time**; it does not extend access. There is no documented API to extend that deadline. The current UI shows “Image link expired” after expiry. Generate examples within 24 hours of review. Durable image history would require downloading completed images before expiry and keeping them in external persistent storage; Render Free's local filesystem cannot preserve them. No refresh or storage change is implemented here.

[Pixelforge API reference](https://pixelforge.promptedit.dev/docs#images)

## Startup and free-tier limits

Each container start runs `php artisan migrate --force --no-interaction` against PostgreSQL. Already-applied migrations are skipped. If a migration fails, the HTTP server stays down. Fix the reported cause and redeploy. Keep future migrations compatible with the previous release because an old instance can still serve during rollout.

The image serves `public/` with three FrankenPHP request threads. Node and Composer are build-time only. The database holds sessions and cache, including submission rate-limit counters. Render handles TLS. Caddy tells Laravel to generate HTTPS URLs. For local HTTP-only container testing, set `HTTPS=off` and `SESSION_SECURE_COOKIE=false`.

The runtime image removes the base FrankenPHP binary's `cap_net_bind_service` file capability before switching to `www-data`. Render's restricted capability set can otherwise reject execution with `Operation not permitted` (exit 126), even though the binary is executable. Port `10000` does not require this capability. Keep the startup script's `exec "$@"` so FrankenPHP receives container signals directly.

Cold starts can delay webhook acknowledgments. Verify delivery and manual recovery before reviewer handoff. Free PostgreSQL has no managed backups. Export any data you need before expiry.

[Render free-tier limits](https://render.com/docs/free) · [Render Docker deployments](https://render.com/docs/docker) · [Render PostgreSQL connections](https://render.com/docs/postgresql-creating-connecting)
