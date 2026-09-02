# Verificação M2 / M3 (CI era)

Auditoria pós-pipeline GitHub Actions. Objectivo: fechar gaps residuales e confirmar go/no-go dos gates.

## M2 — Persistência

| Critério | Evidência |
|----------|-----------|
| Go: trocar impl. de DB sem tocar crypto | InMemory ↔ WordPress via `PersistenceGateway`; `src/Domain/Crypto` sem `$wpdb` |
| No-go: repos acoplados ao host | Facades em `includes/{KeyAuthority,Voting,Tallying,Security}` delegam a ports |

### Corrigido nesta verificação

- `TallyImportRepository::rses_backfill_summaries` / `rses_purge_oversized_manifests` — `$wpdb` saiu da facade; ports `listIdsNeedingSummary` / `purgeOversizedManifests`
- `SignedResultsStore` — metadados assinados sem `get_option`/`update_option`/`delete_option` no serviço
- `PersistencePortsTest` — métodos de listagem + manutenção + signed results

### Residual aceitável (Adapter #1)

- `$wpdb` / `Database\Repository` **só** em `src/Adapters/WordPress/Persistence/**`
- Anexos de mídia do PDF assinado (arquivos) continuam no host; o **meta** está no port
- `ModeLock::rses_truncate_all_tables` — ops de reset, fora do CRUD de domínio

**Veredicto M2:** PASS

## M3 — Identidade

| Critério | Evidência |
|----------|-----------|
| Go: provisionar papéis via portas | `UserDirectory` / `CapabilityResolver` / `SessionPort` / `SecretKeyProvider` |
| No-go: caps/users hardcoded ao host | Cadastro, autoridades (lista), export count e create usam `IdentityGateway` |

### Corrigido nesta verificação

- `UserRegistryService` → `IdentityGateway::users` (sem `get_users` / `wp_insert_user`)
- `ElectoralAuthoritiesPage` lista via `listByRole`
- `ElectoralRollExportService::rses_count_role` → `countByRole`
- Constantes de papel em Domain `UserRegistryRoles` (Capability facade deixa de importar adapter WP)
- `IdentityPortsTest` — update/password/role/count + caps de eleição/export

### Residual aceitável

- `KeyAuthorityViews` / alguns `get_userdata` de UI legado — fora do caminho RSV/cast/autoridades transfer
- `ModeLock` option-based no Adapter #1; Adapter #2 usa `ModePort`

**Veredicto M3:** PASS

## Como revalidar

```bash
cd relatasoft-secure-election-suite
composer test
# ou filtro:
./vendor/bin/phpunit --filter 'PersistencePortsTest|IdentityPortsTest'
```

CI: `.github/workflows/phpunit.yml` (PHP 8.2 / 8.3 + GMP).
