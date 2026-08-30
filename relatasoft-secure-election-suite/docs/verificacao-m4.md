# Verificação M4 — Jobs (CI era)

Gate M4: import/keygen via contratos; domínio **não** depende de `admin-ajax`.

## Critérios

| | |
|--|--|
| **Go** | Keygen / RSV import / RSV export via `JobStore` + job services + `JobGateway` |
| **No-go** | Domínio a possuir estado de job em `admin-ajax` / `get_option` |

## Evidência

| Peça | Estado |
|------|--------|
| Contratos | `JobStore`, `JobSlots`, `KeygenJobService`, `RsvImportJobService`, `RsvExportJobService`, `JobResult` |
| Persistência de jobs | **Só** `WordPressJobStore` usa `get_option`/`update_option`/`delete_option` |
| AJAX Adapter #1 | Cliente fino de `JobGateway`; falhas via `JobResult` (sem `\WP_Error` no port) |
| Download export | `downloadPath()` + `downloadFilename()` no port |
| Crypto / Domain | Sem AJAX |
| Testes | `tests/Unit/Jobs/JobPortsTest.php` |
| CI | `.github/workflows/phpunit.yml` |

## Corrigido nesta verificação

- Contratos RSV sem `\WP_Error`; `JobResult::fail/isFailure`
- Download sem `ElectoralRollExportJob::rses_get()` no handler
- Stages no AJAX como literais (`complete`/`failed`), sem acoplar a constantes legado no tick
- Testes: cancel, ingest, idle status, filename, option-key mapping puro

## Residual aceitável (Adapter #1)

- UI/JS ainda posta para `admin-ajax.php` (transporte)
- Orquestração FS (`wp_upload_dir`) nos runners legado em `includes/`
- `ElectoralRollImportJob::MAX_UPLOAD_BYTES` usado como constante de UI
- `rses_option_key()` deprecated (mapeamento real em `WordPressJobStore`)

**Veredicto M4:** PASS

```bash
./vendor/bin/phpunit --filter JobPortsTest
```
