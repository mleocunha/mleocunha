# Painel de Controle Eleitoral — Arquitetura

## Princípio

A plataforma hospedeira é um **adapter**. O domínio e a aplicação do Painel não chamam APIs do adapter directamente.

## Camadas

1. **Domain** — Persona, AccessPolicy, MenuItem, NavigationRegistry, SettingsSchema; **Crypto** portável (`src/Domain/Crypto`: ElGamal, Shamir, Schnorr, CanonicalHash, …); formatos (`.rsv`, AuthoritiesPackage). Sem boot do sítio — PHPUnit em `tests/Unit`. Adapter #1 mantém facades em `includes/Crypto` / `Exports\HashService`.
2. **Application** — SettingsService, PermissionResolver, NavigationService, DashboardHomeService, LoginBrandingService; **PersistenceGateway** (A2); **IdentityGateway** (A3); **JobGateway** (A4: keygen / RSV import / RSV export); **JourneyGateway** (A5); **ThreeNodePilot** (A6).
3. **Contracts** — SettingsRepository, UserProvider, UserDirectory, CapabilityResolver, SessionPort, SecretKeyProvider, JobStore, KeygenJobService, RsvImportJobService, RsvExportJobService, JourneyUrlGenerator, JourneyRouteResolver, JourneyPresenter, **ModePort** / SiteModes, NavigationRegistrar, AssetProvider, Logger; **persistência A2**.
4. **Infrastructure** — defaults / stores de teste (InMemory Persistence + Identity + Jobs + Journey).
5. **Adapters** — **WordPress** (Adapter #1): opções, papéis, menus, assets, login, redirects, Persistence / Identity / Jobs / Journey. **Standalone** (Adapter #2): `NodeRuntime`, `EnvModeLock`, `bin/ve-node`, piloto 3 nós, persistência JSON por nó (`StandalonePersistenceFactory`).
6. **Presentation** — ShellView, HomeView + `assets/painel`; jornada `/voto` (Adapter #1).

## Personas (papéis do sítio)

| Persona | Papel interno |
|---------|----------------|
| Gestor pelo Cliente | `ve_gestor` |
| Administrador Eleitoral | `administrator` |
| Autoridade Eleitoral | `editor` |
| Eleitor | `subscriber` (nunca no admin) |

## Modos

O home e a navegação mudam com `key_authority` | `voting` | `tallying`. No Adapter #2 cada **processo/nó** tranca um único modo (`ModePort`).

## Portabilidade

Substituir o adapter do sítio hospedeiro por outro adapter sem reescrever Domain/Application/tests de domínio. Gate **M6**: piloto de 3 nós sem host legado e sem sync — ver `docs/piloto-adapter2-3-nos.md`.

Cronograma (PMBOK, caminho crítico): `docs/roadmap-independencia-adapter.md`.  
CI (gate M1): `.github/workflows/phpunit.yml` — PHPUnit + GMP sem boot do sítio.
