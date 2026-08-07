# DOP Core Rules (automation compact)

- **Does not replace** MASTER_SPEC (authoritative on conflict).
- Use this file for stable, cache-friendly agent context.

## Product identity

- DOP / MoxDOP is an **internal Moximu agency operations system**.
- **Not SaaS**. No customer login. No Workspace / Client Portal / marketplace / ZIP install.
- Used only by agency owner and agency staff.

## Hard constraints

- **No external write** to third-party systems (read / collect / analyze only).
- **Modular monolith**; modules are local Composer/Filament plugins — no custom plugin marketplace framework.
- **Reuse the framework** (Laravel / Filament / Spatie / queue / HTTP / encryption). Do not reinvent framework capabilities.
- **Slim Core** (ADR-037): Attachments/Tags/heavy Notification/Audit/Health are not mandatory MVP Core.
- Minimal Module Registry only (ADR-035). No custom compatibility engine / migrator FSM for MVP.

## Domain distinctions

- Hierarchy: `Customer → Brand → Digital Asset → Connection`.
- **Digital Asset** = managed real-world asset (Website, Ads account, …).
- **Connection** = data/integration attachment on an asset (credentials, connectors). Keep them distinct (ADR-017).
- MVP: no multi-tenant Workspace.

## Analysis pipeline

- Flow: `Run → Evidence → Finding → Recommendation → Task`.
- **Finding is persistent** at Digital Asset level with lifecycle (ADR-034).
- **No Result entity** (ADR-036). Outcomes tracked via later Runs + Finding status.

## AI / security

- AI must not get uncontrolled raw external data dumps.
- Never commit secrets / `.env` / credentials.
- Prefer smallest reversible PR; do not invent blueprint-absent business behavior.

## Source priority

1. MASTER_SPEC  
2. Accepted / newer ADRs  
3. Product blueprints (`docs/product/**`)  
4. IMPLEMENTATION_ROADMAP  
5. Framework-native simplicity
