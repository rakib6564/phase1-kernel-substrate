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

## Sections (planned)

| Doc | Covers |
|---|---|
| [gotchas-and-preservation-notes.md](gotchas-and-preservation-notes.md) | **The tacit knowledge** — undocumented behaviors, hazards, and load-bearing decisions that must not be lost |
| core-structure.md | Folder/dir structure with ownership; core class inventory + responsibilities |
| bootstrap-and-lifecycle.md | Entry points, `config.php` boot order, request → response |
| database-as-built.md | Full current schema, table ownership (core vs plugin), tenant handling |
| plugin-system-as-built.md | Real lifecycle, manifest shape, boot/registration, load order |
| runtime-catalogues.md | Hook catalogue, route catalogue (admin/customer/public), cron |
| services-as-built.md | Each current service: how it actually works today |
| modules-as-built.md | Per-plugin inventory (tables/routes/perms/events/blocks/APIs) for all 19 |
| security-as-built.md | Every security control currently implemented |
| performance-as-built.md | Real bottlenecks, existing caching, scalability limits |

The first two (framing + preservation notes) are authored; the factual
inventories are built from direct code extraction (grep/read), section by section.
