# ISP Billing Platform

[![CI](https://github.com/tiarh/isp-billing-platform/actions/workflows/ci.yml/badge.svg)](https://github.com/tiarh/isp-billing-platform/actions/workflows/ci.yml)

Billing and operations system built for a regional ISP with 2,000+ subscribers.

**This is a read-only snapshot.** It is published so people can read the code. It is not a
deployable product and it is not set up for outside contributions. Pull requests are not
accepted. You can open an issue, but a reply is not guaranteed.

The data in this repository is fictional. Real customer data, servers, addresses and
credentials are not part of it.

## What it does

- Customers, packages, invoices and payments (payment gateway callbacks, payment pages).
- Automatic billing runs, overdue handling and isolation of unpaid accounts.
- WhatsApp notifications for invoices, reminders and installation updates.
- New installation (PSB) workflow, from request to active PPPoE account.
- Router, OLT and ONU management (MikroTik, ZTE, Huawei, Fiberhome) and TR-069 devices.
- Monitoring, outage detection and a ticket system for field technicians.
- Admin panel with roles, audit log and two-step login.

## Stack

Laravel (PHP 8.4), Inertia with React and TypeScript, MySQL, Redis, Vite.

## Run it locally with demo data

You need PHP 8.4 with the usual Laravel extensions, Composer, Node 22 and MySQL 8.

```bash
cp .env.example .env
# fill DB_PASSWORD and set E2E_PASSWORD (the demo admin password), set APP_ENV=testing
composer install
npm ci
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

The demo seeder only runs with `APP_ENV=testing` and a non-empty `E2E_PASSWORD`. It creates a
few fictional customers and one admin login, `admin@local`, using that password.

Tests: `php artisan test` (needs a MySQL database named `testing`, see `phpunit.xml`).

## Snapshot notes

- Some files from the original project are left out on purpose: operations scripts, internal
  docs, deployment files and tests that depend on them. The list of left-out test files is in
  `QUARANTINE.txt`.
- `config/site.php` holds placeholder hosts and addresses. A real setup replaces it.
- New installation passwords are random in this snapshot.

## License

No license is granted. All rights reserved. You may read the code, but you may not copy,
modify or redistribute it without written permission.

## Security

See [SECURITY.md](SECURITY.md).
