# Slate — Current Implementation Reference (As-Built)

**Status:** Living reference · **Describes:** the code as it exists **today**

> **Read this framing first.** This directory documents Slate **as built** — the
> real, current implementation. It is deliberately **separate from the frozen
> [Slate Platform Architecture v1.0](../README.md)** (sections `00`–`15`), which
> describes the *target*. In many places the two **differ on purpose**: today's
> code uses global classes, `class_exists` coupling, `ensureSchema` self-heal,
> DECIMAL money, and per-plugin customer tables; the target replaces those. When
> the two conflict, **the hub is the destination; this is the starting point**,
> and [09-Roadmap](../09-Roadmap/) is the bridge.

This reference exists so a new developer (or AI) can understand and safely modify
the *actual* system, and so nothing tacit is lost when implementation begins.

## Sections

**Preservation & migration (authored):**

| Doc | Covers |
|---|---|
| [gotchas-and-preservation-notes.md](gotchas-and-preservation-notes.md) | ✅ Tacit knowledge — hidden behaviors & hazards |
| [load-bearing-behaviors.md](load-bearing-behaviors.md) | ✅ The must-not-change preservation contract |
| [architecture-mapping.md](architecture-mapping.md) | ✅ Current → future component, by phase |
| [compatibility-matrix.md](compatibility-matrix.md) | ✅ Bridges, mechanisms, planned retirement |

**As-built encyclopedia (built from code extraction):**

| Doc | Covers |
|---|---|
| core-structure.md | Folder/dir structure with ownership; **core class inventory** + responsibilities |
| bootstrap-and-lifecycle.md | Entry points, `config.php` boot order, request → response, DI/registration flow |
| database-as-built.md | Full current schema, table ownership (core vs plugin), relationships, tenant handling, migration risks |
| plugin-system-as-built.md | Real lifecycle, manifest shape, boot/registration, load order |
| runtime-catalogues.md | **Hook catalogue**, route catalogue (admin/customer/public/API), cron |
| rendering-as-built.md | Rendering pipeline, Theme Engine, Block system, Section/layout, templates |
| admin-and-portal.md | Admin architecture + Customer portal as built |
| services-as-built.md | Each current service (auth, rbac, settings, media, i18n, notifications, …) |
| modules-as-built.md | Per-plugin inventory (purpose/tables/routes/perms/hooks/events/APIs/deps/problems/migration plan) for all 19 |
| security-as-built.md | Every security control currently implemented |
| performance-as-built.md | Real bottlenecks, existing caching, scalability limits |
| technical-debt.md | Known debt, known issues, developer notes, refactor candidates |

The preservation & migration set is authored; the encyclopedia is built section by
section from direct code extraction (grep/read) so it is accurate, not inferred.
