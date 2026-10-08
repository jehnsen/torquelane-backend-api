# TorqueLane API

Laravel API for TorqueLane, a multi-tenant ERP + CRM for Philippine automotive
service businesses. It is the only writer and the source of truth for every
business rule; the Next.js frontend renders what it returns.

```bash
composer setup   # PHP 8.4 + pdo_pgsql, Composer 2 and Docker required
composer test
```

- **[CLAUDE.md](CLAUDE.md)**: architecture, conventions, standing rules, roadmap
- **[docs/deploy.md](docs/deploy.md)**: staging deploy (CloudPanel VPS)
- **[docs/frontend-parity.md](docs/frontend-parity.md)**: every frontend screen and mutation → its endpoint (Phase 5's switch-over map)
- **[openapi.json](openapi.json)**: generated API description (`composer openapi`)
