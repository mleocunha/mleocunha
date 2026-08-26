# Painel de Controle Eleitoral — Arquitetura

## Princípio

WordPress é um **adapter**. O domínio e a aplicação do Painel não chamam APIs WP directamente.

## Camadas

1. **Domain** — Persona, AccessPolicy, MenuItem, NavigationRegistry, SettingsSchema
2. **Application** — SettingsService, PermissionResolver, NavigationService, DashboardHomeService, LoginBrandingService
3. **Contracts** — SettingsRepository, UserProvider, CapabilityResolver, NavigationRegistrar, AssetProvider, Logger
4. **Infrastructure** — defaults / stores de teste
5. **Adapters/WordPress** — opções, roles, menus, assets, login, redirects
6. **Presentation** — ShellView, HomeView + `assets/painel`

## Personas (roles WP)

| Persona | Role WP |
|---------|---------|
| Gestor Voto Eletrônico | `ve_gestor` |
| Administrador Eleitoral | `administrator` |
| Autoridade Eleitoral | `editor` |
| Eleitor | `subscriber` (nunca no admin) |

## Modos

O home e a navegação mudam com `key_authority` | `voting` | `tallying`.

## Portabilidade

Substituir `Adapters/WordPress` por outro adapter sem reescrever Domain/Application/tests de domínio.
