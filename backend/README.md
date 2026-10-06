# KPS Internal System Backend

Laravel 12 API backend for KPS Internal System. This foundation currently exposes only a public health endpoint; authentication and KPS business modules have not been implemented.

## Requirements

- PHP 8.2 or later
- Composer 2
- PHP ZIP extension or a Composer-supported archive extraction tool
- MySQL for application data

SQLite may be used for isolated automated tests. It is not the planned production database.

## Local setup

From the repository root:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

On Windows Command Prompt, use `copy .env.example .env` instead of `cp`. Configure the `DB_*` values in `.env` for your local MySQL instance before running database-dependent commands. Never commit `.env` or real credentials.

Start the API:

```bash
php artisan serve
```

The default local API URL is `http://127.0.0.1:8000`.

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

- Implemented: Laravel 12 foundation, API routing, environment-driven CORS, public health endpoint.
- Planned separately: authentication, authorization, business schema, CRM, AI, and external integrations.
