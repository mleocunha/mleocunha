# Verificação A6.1 — Persistência durável Adapter #2

Incremento pós-M6: cada nó standalone sobrevive a **restart do processo** sem sync entre nós.

## Critérios

| | |
|--|--|
| **Go** | Keys / elections / votes / tallies / audit em ficheiro por nó (`persistence.json`); reload reconstitui estado |
| **No-go** | Partilhar um único ficheiro entre os 3 nós; sync automático |

## Evidência

| Peça | Estado |
|------|--------|
| Store | `JsonDocumentStore` (write atómico temp→rename) |
| Ports | `FileJson*` via `StandalonePersistenceFactory` — mesmos contratos A2 |
| Wiring | `NodeRuntime::create(..., $durable=true)` por defeito |
| Piloto | `ThreeNodePilot` / `ve-node pilot` usam JSON por nó |
| Identity / Jobs | Continuam InMemory (fora do caminho courier nesta v1) |
| Testes | `tests/Unit/Standalone/DurablePersistenceTest.php` |

## Residual

- UI HTTP do eleitor no Adapter #2 — próximo endurecimento  
- SQL / multi-writer — só se o piloto HTTP o exigir  
- Identity/Jobs duráveis — quando houver auth/async no standalone  

**Veredicto A6.1:** PASS

```bash
./vendor/bin/phpunit --filter DurablePersistenceTest
php bin/ve-node pilot --root=/tmp/ve-piloto --votes=2
# ka/voting/tallying/persistence.json presentes após o piloto
```
