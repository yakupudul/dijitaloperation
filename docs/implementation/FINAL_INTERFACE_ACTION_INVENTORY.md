# Final Interface Action Inventory (development artifact)

Internal audit matrix for the Final Interface Completion milestone. Not an operator page.

| Screen | Action label | Type | Expected | Actual | Demo/Live/Internal | Route/state | Test |
|--------|--------------|------|----------|--------|--------------------|-------------|------|
| Header | Locale EN/TR | state | Persist locale | Persists on User | Internal | LocaleSwitcher | FinalInterfaceCompletionTest |
| Header | Profile avatar | navigate | /app/profile | OK | Internal | demo.profile | FinalInterfaceCompletionTest |
| Header | Settings | navigate | /app/settings | OK | Internal | demo.settings | CanonicalAppUrlIntegrityTest |
| Header | Notifications | drawer | Show in-app notifications | Bell + prefs | Demo | NotificationBell | CanonicalAppUrlIntegrityTest |
| Header | Global search | search | Customers/Brands/Assets | Results with context | Demo | GlobalSearch | CanonicalAppUrlIntegrityTest |
| Nav | Files | navigate | File library | OK | Internal | demo.files | FinalInterfaceCompletionTest |
| Settings AI | AI Control Plane | navigate | Configure routes | /app/settings/ai/control-plane | Internal | demo.settings.ai.control-plane | FinalInterfaceCompletionTest |
| Settings AI | Configure (route) | navigate | Selected route editor | Query ?route= | Internal | AiControlPlanePage | FinalInterfaceCompletionTest |
| Settings AI | Agent Profiles | anchor | #agents list | Read-only registry | Internal | settings?section=ai#agents | — |
| Settings AI | Skill Library | anchor | #skills list | Read-only registry | Internal | settings?section=ai#skills | — |
| Settings Advanced | Open Agency Command Center | navigate | Dashboard | OK | Internal | demo.dashboard | FinalInterfaceCompletionTest |
| Files | Upload/Download/Delete/Rename | CRUD | Private storage | Auth download | Internal | OperatorFile | FinalInterfaceCompletionTest |
| Site Connectors | Download package | download | Demo ZIP labeled | DEMO CONNECTOR PACKAGE | Demo | site-connector.download | FinalInterfaceCompletionTest |
| Integrations | Provider Manage | navigate | Provider detail | Google/Meta hubs | Demo | demo.integrations.* | ProductVisionRecoveryTest |
| Instagram | Tabs | navigate | Useful workspace | Profile/Findings/… | Demo | demo.instagram | FinalInterfaceCompletionTest |
| OAuth Google | Success/fail redirect | redirect | /app integrations | Not /system | Live+Demo | GoogleOAuthController | GoogleLiveAuthUxTest |
| Portfolio wizard | Setup | wizard | Customer→Brand→Assets | Shared engine | Demo | demo.setup | ProductVisionRecoveryTest |

## Dead-action notes

- No `href="#"` / Coming soon found under `resources/views/livewire/demo`.
- No Demo blade links to `/system` or `/admin`.
- Filament technical panel remains at `/admin` for login and technical CRUD (ADR-044); operator shell must not deep-link there.
- Full Agent Profile / Skill assignment editors remain Filament-backed (`/admin/settings/...`) — operator Settings shows registry overview + Control Plane editor only.

## Classification legend

- **Demo**: deterministic fixtures / local-only state
- **Live**: real provider/auth path where configured
- **Internal**: MoxDOP-owned persistence (DB/files/settings)
