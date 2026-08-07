# DashLog

A syslog collector, viewer, and analyzer, built with [Symfony](https://symfony.com) and running on [FrankenPHP](https://frankenphp.dev)/[Caddy](https://caddyserver.com) via Docker Compose.

## Stack

- **App**: Symfony 8.1 (webapp edition — Twig, Forms, Security, Doctrine ORM, Messenger, Mailer)
- **Database**: PostgreSQL 16
- **Web/App server**: FrankenPHP (Caddy), automatic HTTPS in dev and prod
- **Dev mail catcher**: Mailpit

## Getting Started

1. Install [Docker Compose](https://docs.docker.com/compose/install/) (v2.10+)
2. Build the images:

   ```console
   docker compose build
   ```

3. Start the stack:

   ```console
   docker compose up -d --wait
   ```

4. Open <https://localhost> and accept the auto-generated dev TLS certificate.
5. Mailpit (dev mail catcher) is available at <http://localhost:8025>.
6. Stop everything with:

   ```console
   docker compose down
   ```

## Running console commands

```console
docker compose exec php bin/console <command>
```

## Docker template

The container setup is based on [dunglas/symfony-docker](https://github.com/dunglas/symfony-docker). See `docs/` for details on options, production deployment, Xdebug, extra services, and troubleshooting.
