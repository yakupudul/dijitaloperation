# Integrations workspace

> Operator UX for Settings → Integrations (service control center).

## Principle

**Provider-specific operator experience over generic Integration CRUD.**

Operators should answer:

- Which services are connected?
- Do they work?
- Where do I manage them?

They should **not** think in CoreIntegration / ExternalResource / credential payload terms.

Canonical storage architecture is unchanged:

```
Integration
  ├── IntegrationCredential (provider | authorization)
  → ExternalResource (Google-style discovery only)
    → AssetBinding
```

## Index (card hub)

Settings → Integrations is a responsive **card grid**, not a database table.

- Desktop: up to 3 cards per row
- Tablet: 2
- Mobile: 1

Cards are driven by `IntegrationPresentationRegistry` (operator-ready metadata) + existing `CoreIntegration` rows.

A provider can appear **before** a DB row exists. **Set up** bootstraps the canonical Integration; **Manage** opens the provider workspace.

No generic **Add integration** for normal operators.

### Status semantics

Derived from persisted health — **not** from `CoreIntegration.status = active` alone.

| Status | Meaning |
|--------|---------|
| Connected | Required configuration exists and latest auth/health check succeeded |
| Configured | Configuration exists but has never successfully been checked |
| Needs attention | Configuration exists but latest check failed / auth unusable |
| Not configured | Required configuration missing |
| Disabled | Existing domain disabled state |

Rendering the index performs **zero** provider HTTP calls.

### Provider groups

- **Data & platforms** — Google, DataForSEO (Meta stays in `ProviderRegistry` but is hidden until operator-ready)
- **AI providers** — OpenAI, Anthropic, Gemini (Integrations ≠ Modules; route models live in AI Control Plane)

## Provider shapes

### Google — resource-discovery provider

- OAuth + agency account
- Discovers External Resources → Asset Bindings
- Workspace: Overview, Configuration, Available services, Google resources
- Actions: Configure, Authorize / Re-authorize, Test connection, Refresh resources
- Destructive: Disconnect / Remove configuration in Danger zone

### DataForSEO — credential/API provider

- Basic Auth (API Login + API Password)
- No resource discovery / no External Resources UI
- Actions: Configure, Test connection
- Destructive: Remove configuration in Danger zone

### OpenAI / Anthropic / Gemini — credential/API providers

- Secret API key only (no Key ID / Client ID / Client Secret / OAuth reuse for Gemini)
- Gemini is separate from Google OAuth Integration
- No resource discovery / no External Resources UI
- Non-generative Test Connection (models list endpoints)
- Integration represents provider availability — **AI route owns workflow models**
- Actions: Configure, Test connection
- Destructive: Remove configuration in Danger zone

## Reactive configure → test

After Configure saves, the workspace must refresh Integration + credential state so **Test connection** (and Google Authorize) become available immediately — no browser refresh.

## Presentation layer

- `IntegrationPresentationRegistry` — operator-ready metadata
- `IntegrationHealthPresenter` — status + card summaries from persisted state
- `IntegrationCardViewModel` — safe DTO (never secrets)
- `IntegrationWorkspaceCatalog` — hub assembly + bootstrap

`ProviderRegistry` remains canonical provider identity.

## Related / next

AI provider routing for Website AI Guidance is implemented in **AI Provider Routing & Failover V1** — see [`docs/product/AI_CONTROL_PLANE.md`](../AI_CONTROL_PLANE.md).

Aggregator providers (OpenRouter, etc.) and Agent Profiles remain later.

