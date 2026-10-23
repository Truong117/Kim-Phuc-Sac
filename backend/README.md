# KPS Internal System Backend

Laravel 12 API backend for KPS Internal System. Authentication V1 uses Laravel Sanctum first-party SPA authentication with Laravel's server-side session and cookies; the frontend does not receive or store bearer tokens.

## Requirements

- PHP 8.2 or later
- Composer 2
- PHP ZIP extension or a Composer-supported archive extraction tool
- SQLite for the default local setup
- MySQL/TiDB for isolated staging and production environments

SQLite in-memory is used by the automated tests.

## Local setup

From the repository root:

```bash
cd backend
composer install
# Fresh clone only, after confirming .env does not exist:
if [ ! -e .env ]; then
  cp .env.example .env
  php artisan key:generate
fi
```

On PowerShell, use `if (-not (Test-Path .env)) { Copy-Item .env.example .env; php artisan key:generate }`. If `.env` already exists, do not copy over it or regenerate `APP_KEY`; back it up outside the repository and follow the one-time conversion procedure in [Environment and Deployment Safety](../docs/ENVIRONMENT_DEPLOYMENT_SAFETY.md). Never commit `.env` or real credentials.

The example configuration uses a local SQLite database. Create `database/database.sqlite`, then verify that `.env` contains `APP_ENV=local`, `KPS_DATABASE_ENVIRONMENT=development`, `DB_CONNECTION=sqlite`, and a local `DB_DATABASE` path. Only after confirming that target may you initialize the local schema and start the API:

```bash
php artisan migrate
php artisan serve --host=localhost --port=8000
```

Do not copy TiDB or production credentials into a development `.env`. Migrations are never an implicit application startup step. Staging and production migrations require a separately approved pre-deploy/one-off operation. See [Environment and Deployment Safety](../docs/ENVIRONMENT_DEPLOYMENT_SAFETY.md) for the environment matrix, Render/Vercel runbook, private object storage, and prohibited production operations.

Use this hostname convention consistently during development:

- Frontend: `http://localhost:5173`
- Backend: `http://localhost:8000`

The example environment config allows only that frontend origin, identifies `localhost:5173` as a Sanctum stateful domain, and enables credentialed CORS. The runtime environment guard is mandatory: local/development accepts only SQLite or MySQL on an explicit loopback host and rejects every non-empty `DB_URL`. It is not possible to opt a local process into a remote database through an environment flag. Production must provide environment-specific values for `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, and `SESSION_SECURE_COOKIE`; do not hard-code production domains or secrets in source.

For the default Vercel/Render vendor domains, the supported flow is a same-origin Vercel proxy for `/api` and `/sanctum`; direct cookie authentication between unrelated `vercel.app` and `onrender.com` sites is unsupported. A custom-domain alternative is valid only when frontend and API are sibling subdomains with a deliberately shared cookie domain. See the deployment runbook before changing this topology.

## Authentication endpoints

```text
GET  /sanctum/csrf-cookie
POST /api/auth/login
GET  /api/auth/me
POST /api/auth/logout
```

The frontend must request the CSRF cookie before login and send cookies with every auth request. `/api/auth/me` and `/api/auth/logout` require `auth:sanctum`. There is no public registration endpoint.

## Create a local development user

Create accounts only in your local database. Do not commit seed credentials.

```bash
php artisan tinker
```

Then create a user using a unique local email and a password chosen at the prompt/session:

```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;

User::create([
    'name' => 'Local Developer',
    'email' => '<your-local-email>',
    'password' => Hash::make('<your-unique-local-password>'),
]);
```

Do not reuse a real production password.

## Health check

```http
GET /api/health
```

Expected response:

```json
{
  "status": "ok",
  "service": "kps-backend"
}
```

## Current scope

- Implemented: Laravel foundation, MySQL configuration, public health endpoint, Sanctum session/cookie authentication, login, current-user lookup, logout, CSRF and credentialed CORS.
- Planned separately: authorization, roles, permissions, profile management, password reset, organization structure, CRM, AI, and external integrations.
