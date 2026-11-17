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

## Provision one verified reviewer account (no email service)

Email verification remains required for normal registrations. For this assessment, use the opt-in `demo:provision` command to create one pre-verified reviewer account. It sends no email and adds no public provisioning endpoint. The password is hashed by the User model. Existing accounts are never verified, reset, or otherwise changed by this command.

Render Free has no interactive shell, so the startup script runs the command after migrations only when either of these runtime environment variables is nonempty:

```dotenv
DEMO_ACCOUNT_EMAIL=<a new demo account email>
DEMO_ACCOUNT_PASSWORD=<a unique strong password>
```

Minimal provisioning steps:

1. In the Render web service's **Environment** settings, add both variables. Use a new email address not already registered (it does not need a working inbox) and a unique 16–72 character ASCII password with upper/lowercase letters, a number, and a symbol. Do not put the values in source files, build arguments, the Docker command, or GitHub.
2. Choose **Save, rebuild, and deploy** so the latest `main` and those runtime secrets are applied. Leave **Docker Command** and **Pre-Deploy Command** blank. After migrations, logs should show `Verified demo account created.` No credentials are printed.
3. Log in at `/login` using those credentials and confirm `/generations` opens. Share the credentials privately with reviewers, alongside the deployment and public repository URLs.
4. Remove both variables and apply the environment change with a redeploy. The hashed account and its verification timestamp remain in PostgreSQL. This removes the provisioning secret from the running service. Retain the password in your private password manager for reviewer handoff.

Repeated starts with the same credentials succeed without changing the account. Missing/invalid values or an existing account with a different password or unverified email cause provisioning to fail and stop startup; remove both variables to restore normal startup, or choose a new unused email and valid password. The command cannot be used to reset passwords. Locally or on a plan with a shell, it can also be invoked as `php artisan demo:provision --no-interaction` with both variables supplied securely in the environment.

Keep `MAIL_MAILER=array` for this no-email demo. Ordinary users can still register but cannot complete verification or email-based password resets without a mail service; direct reviewers to the provisioned account instead. Do not disable verification globally. A future production rollout can configure a real mail transport separately.

## Configure Pixelforge and reviewer history

Pixelforge is simulated: no real model runs and no real money is charged. Its usage and pricing still need to be calculated correctly for the assessment.

1. In the Pixelforge dashboard, create/select a key for this deployment. Each key has its own webhook URL and signing secret.
2. Set that key's webhook URL to `https://promptedit-assessment.onrender.com/webhooks/pixelforge`.
3. In Render's runtime Environment settings, set `PIXELFORGE_API_KEY` to that key and `PIXELFORGE_WEBHOOK_SECRET` to its matching signing secret. Leave `PIXELFORGE_BASE_URL` unset to use `https://pixelforge.promptedit.dev`. The image blocks submissions without a key and rejects webhook deliveries without a valid matching secret.
4. Once the configuration is applied, send a test event from Pixelforge and confirm HTTP 204 in its delivery log.
5. Log in with the provisioned reviewer account. Generate at least three `[succeed]` examples with different sizes/qualities. Add `[duplicate]` to one prompt and verify duplicate deliveries do not change the settled charge. Test `[fail]` separately and confirm a zero actual charge. Confirm history updates without a manual page reload and compare provider usage/rates with the displayed final charge including the 25% markup.
6. History is private to each user. Provide reviewers a dedicated demo account through the assessment handoff, with those completed examples in that account's history, using the provisioning steps above. Never commit its password or any secrets. Verify the GitHub repository and deployed URL both open in a signed-out browser.

### Image URL expiration

The official Images documentation states that URLs expire 24 hours after generation completion. `GET /v1/generations/{id}` returns a fresh URL with the **same expiration time**; it does not extend access. There is no documented API to extend that deadline. The current UI shows “Image link expired” after expiry. Generate examples within 24 hours of review. Durable image history would require downloading completed images before expiry and keeping them in external persistent storage; Render Free's local filesystem cannot preserve them. No refresh or storage change is implemented here.

[Pixelforge API reference](https://pixelforge.promptedit.dev/docs#images)

## Startup and free-tier limits

Each container start runs `php artisan migrate --force --no-interaction` against PostgreSQL. Already-applied migrations are skipped. If a migration fails, the HTTP server stays down. Fix the reported cause and redeploy. Keep future migrations compatible with the previous release because an old instance can still serve during rollout.

The image serves `public/` with three FrankenPHP request threads. Node and Composer are build-time only. The database holds sessions and cache, including submission rate-limit counters. Render handles TLS. Caddy tells Laravel to generate HTTPS URLs. For local HTTP-only container testing, set `HTTPS=off` and `SESSION_SECURE_COOKIE=false`.

The runtime image removes the base FrankenPHP binary's `cap_net_bind_service` file capability before switching to `www-data`. Render's restricted capability set can otherwise reject execution with `Operation not permitted` (exit 126), even though the binary is executable. Port `10000` does not require this capability. Keep the startup script's `exec "$@"` so FrankenPHP receives container signals directly.

Cold starts can delay webhook acknowledgments. Verify delivery and manual recovery before reviewer handoff. Free PostgreSQL has no managed backups. Export any data you need before expiry.

[Render free-tier limits](https://render.com/docs/free) · [Render Docker deployments](https://render.com/docs/docker) · [Render PostgreSQL connections](https://render.com/docs/postgresql-creating-connecting)
