# Slate Tests

The Phase-0 safety net (see `docs/09-Roadmap/refactor-roadmap.md` and
`docs/12-Testing/`). Intentionally **dependency-free** — plain PHP, no PHPUnit or
`composer install` — so it runs on shared hosting exactly as production does.

## Run

```bash
php tests/smoke.php      # or: bash tests/run.sh
```

Exit code `0` = all passed, `1` = one or more failures. Output is TAP-style.

## What `smoke.php` checks (read-only)

- `config.php` boots without a fatal (autoload/bootstrap intact)
- Core classes are wired (`Database`, `Auth`, `Hook`, `PluginLoader`, …)
- Core constants defined (`SLATE_VERSION`, `SLATE_URL`)
- Database connectivity (`SELECT 1`)
- Core schema present (`tenants`, `roles`, `users`, `settings`, `plugins`, …)
- The default tenant is seeded

It never writes data.

## Growing the suite

This is a seed. As the refactor lands, add assertions for the highest-value
paths first (per `docs/12-Testing/`): **money** (`Money` never a float),
**tenancy** (no query escapes tenant scope), **auth/authz**, **payments**, and
**migrations** (up/down round-trip). When a real test DB and CI runner are
available, promote these to a proper suite and wire the
[architecture-conformance](../docs/12-Testing/architecture-conformance.md) checks
as gates.

## CI

No CI platform is wired to this working tree yet (no remote). Until one exists,
run `bash tests/run.sh` before committing. Once a remote is added, run it on
push/PR as the merge gate.
