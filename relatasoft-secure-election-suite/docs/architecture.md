# Painel de Controle Eleitoral — Arquitetura

## Princípio

A plataforma hospedeira é um **adapter**. O domínio e a aplicação do Painel não chamam APIs do adapter directamente.

## Camadas

1. **Domain** — Persona, AccessPolicy, MenuItem, NavigationRegistry, SettingsSchema; **Crypto** portável (`src/Domain/Crypto`: ElGamal, Shamir, Schnorr, CanonicalHash, …); formatos (`.rsv`, AuthoritiesPackage). Sem boot do sítio — PHPUnit em `tests/Unit`. Adapter #1 mantém facades em `includes/Crypto` / `Exports\HashService`.
2. **Application** — SettingsService, PermissionResolver, NavigationService, DashboardHomeService, LoginBrandingService; **PersistenceGateway** (A2); **IdentityGateway** (A3); **JobGateway** (A4: keygen / RSV import / RSV export).
3. **Contracts** — SettingsRepository, UserProvider, UserDirectory, CapabilityResolver, SessionPort, SecretKeyProvider, JobStore, KeygenJobService, RsvImportJobService, RsvExportJobService, NavigationRegistrar, AssetProvider, Logger; **persistência A2**.
4. **Infrastructure** — defaults / stores de teste (InMemory Persistence + Identity + Jobs).
5. **Adapters** — opções, papéis, menus, assets, login, redirects; **WordPress Persistence / Identity / Jobs** (`get_option` de jobs só em `WordPressJobStore`; AJAX é cliente fino).
6. **Presentation** — ShellView, HomeView + `assets/painel`

## Personas (papéis do sítio)

| Persona | Papel interno |
|---------|----------------|
| Gestor pelo Cliente | `ve_gestor` |
| Administrador Eleitoral | `administrator` |
| Autoridade Eleitoral | `editor` |
| Eleitor | `subscriber` (nunca no admin) |

## Modos

O home e a navegação mudam com `key_authority` | `voting` | `tallying`.

## Portabilidade

Substituir o adapter do sítio hospedeiro por outro adapter sem reescrever Domain/Application/tests de domínio.

Cronograma (PMBOK, caminho crítico): `docs/roadmap-independencia-adapter.md`.
