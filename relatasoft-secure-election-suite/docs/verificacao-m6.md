# Verificação M6 — Piloto Adapter #2 (3 nós)

Gate M6: **3 nós** de um cliente **sem host legado**, material só via courier — **sem sync**.

## Critérios

| | |
|--|--|
| **Go** | 3 nós, um modo cada, material só via `MaterialCourier`; crypto real (ElGamal + Shamir + tally) sem boot do host |
| **No-go** | Sync automático entre nós; um único runtime a fingir os 3 papéis |

## Evidência

| Peça | Estado |
|------|--------|
| Contratos | `ModePort`, `SiteModes` |
| Domain material | `PublicKeyPackage`, `VoteMaterialPackage`, `MaterialCourier` (sem `private_x`) |
| Adapter #2 | `NodeRuntime`, `EnvModeLock`, `Bootstrap`, `bin/ve-node` |
| Application | `ThreeNodePilot` — KA → courier → voting → courier → tallying |
| Isolamento | Stores/gateways distintos por nó; `isolationSnapshot()` |
| CI | PHPUnit + smoke `php bin/ve-node pilot` (PHP 8.3) |
| Doc ops | `docs/piloto-adapter2-3-nos.md` |
| Testes | `tests/Unit/Standalone/ThreeNodePilotTest.php` |

## Corrigido nesta verificação

- `PublicKeyPackage` / `VoteMaterialPackage` rejeitam pacotes com `private_x`
- Tallying lê material **só** via `MaterialCourier::readJson` (sem `file_get_contents` directo)
- Testes: env mode lock, rejeição de `private_x`, path traversal do courier, arquivos do courier sem `private_x`, gateways distintos

## Residual aceitável (pós-M6)

- Persistência **JSON por nó** (A6.1) — não SQL multi-writer
- UI HTTP do eleitor no Adapter #2 fora de âmbito
- Adapter #1 continua caminho de produção até migração nó a nó
- `ThreeNodePilot` orquestra os 3 nós **no mesmo processo PHPUnit/CLI** para o ensaio; produção = 3 processos (`ve-node info` / env `RSES_MODE`)
- Identity / Jobs InMemory no standalone até auth/async

**Veredicto M6:** PASS

```bash
./vendor/bin/phpunit --filter ThreeNodePilotTest
php bin/ve-node pilot --root=/tmp/ve-piloto --votes=2
```
