# ADR-0008 — One design-token vocabulary for admin + public

**Status:** Accepted   **Date:** 2026-07-27

## Context

The codebase currently has **five disjoint token/theming vocabularies**
(`--accent`/`--glass`, `--cb-*`, `--sb-*`, the landing page's, the storefront's)
and **three parallel admin component kits**. Accent color alone is defined in 5+
places. This makes consistent theming impossible and every UI change a
multi-place edit.

## Decision

Adopt **one design-token vocabulary** (`--slate-*`) consumed by **both admin and
public**, and **one component library** built on it. A Theme supplies token
*values* (per tenant); Components consume tokens; Blocks compose Components. One
source of truth per visual concept.

## Alternatives considered

- **Separate admin and public design systems.** Rejected: duplicates the token +
  component work and guarantees drift; a tenant's public theme couldn't inform
  admin accents.
- **Keep per-subsystem tokens, add a mapping layer.** Rejected: a mapping between
  five vocabularies is more complexity than unifying them.

## Consequences

- **Positive:** change a token once, everything restyles; per-tenant theming is
  trivial and contrast-validated ([04-Design-System/accessibility.md](../04-Design-System/accessibility.md));
  admin and public feel like one product; accessibility is solved at the primitive
  layer.
- **Negative / accepted trade-offs:** a migration consolidating five vocabularies
  and three component kits (churn across many templates); a period where old and
  new coexist; naming discipline required to keep the vocabulary coherent.

## Related

- [ADR-0003](0003-server-rendered-no-build.md) · [ADR-0007](0007-section-block-before-page-builder.md)
- [04-Design-System](../04-Design-System/) · [05-Rendering/theme-and-template-engine.md](../05-Rendering/theme-and-template-engine.md)
