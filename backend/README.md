# KPS Internal System Backend

Laravel 12 API backend for KPS Internal System. Authentication V1 uses Laravel Sanctum first-party SPA authentication with Laravel's server-side session and cookies; the frontend does not receive or store bearer tokens.

## Requirements

- PHP 8.2 or later
- Composer 2
- PHP ZIP extension or a Composer-supported archive extraction tool
- MySQL 8 for local and production application data

SQLite in-memory is used by the automated tests.

## Local setup

From the repository root:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve --host=localhost --port=8000
```

On Windows Command Prompt, use `copy .env.example .env` instead of `cp`. Configure the `DB_*` values in `.env` for your local MySQL instance. Never commit `.env` or real credentials.

Use this hostname convention consistently during development:

- Frontend: `http://localhost:5173`
- Backend: `http://localhost:8000`

The example environment config allows only that frontend origin, identifies `localhost:5173` as a Sanctum stateful domain, and enables credentialed CORS. Production must provide environment-specific values for `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, and `SESSION_SECURE_COOKIE`; do not hard-code production domains or secrets in source.

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
