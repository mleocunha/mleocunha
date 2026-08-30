# Piloto Adapter #2 — três nós isolados (A6 / M6)

**Produto:** Voto Eletrônico by RelataSoft / Painel de Controle Eleitoral  
**Objectivo:** provar que o domínio eleitoral corre **sem o host legado**, com **1 cliente = 3 sítios** e **sem sincronização automática**.

## O que é o Adapter #2

| | Adapter #1 | Adapter #2 (`Standalone`) |
|---|------------|---------------------------|
| Runtime | Sítio hospedeiro actual | Processo PHP isolado (`bin/ve-node`) |
| Persistência | Ports → DB do host | Ports → **JSON por nó** (`dataDir/persistence.json`); Identity/Jobs InMemory no piloto |
| Modo | `ModeLock` (opções do host) | `EnvModeLock` / `ModePort` — imutável após lock |
| Jornada | `/voto/…` no host | URL generator InMemory (piloto CLI) |
| Transporte | Exportações manuais | `MaterialCourier` (ficheiros JSON) |

O Adapter #1 **continua suportado**. O Adapter #2 fecha o gate **M6**: nós sem host legado.

## Topologia do piloto

```text
root/
  ka/          → modo key_authority + persistence.json
  voting/      → modo voting + persistence.json
  tallying/    → modo tallying + persistence.json
  courier/     → ÚNICO canal de material (cópia manual)
    public-key.json
    parcela-1.json … parcela-n.json
    vote-material.json
```

Fluxo (igual a `docs/conhecimento/implantacao-3wp.md`):

1. **KA** gera ElGamal + parcelas Shamir; publica só a chave pública + parcelas no courier  
2. **Votação** importa a chave pública (nunca `private_x`); cifra votos; exporta material  
3. **Apuração** importa votos + limiar de parcelas; reconstrói; certifica

**Proibido:** sync de users/DB entre pastas; um único processo “super-nó” em produção.

## Como executar

```bash
cd relatasoft-secure-election-suite
composer install
php bin/ve-node pilot --root=/tmp/ve-piloto --cliente=piloto --votes=2
```

PHPUnit (gate M6):

```bash
./vendor/bin/phpunit --filter ThreeNodePilotTest
```

## Contratos novos (A6)

- `Contracts/Mode/ModePort`, `SiteModes`
- `Domain/Material/{PublicKeyPackage,VoteMaterialPackage,MaterialCourier}`
- `Adapters/Standalone/{Bootstrap,NodeRuntime,EnvModeLock}`
- `Application/Standalone/ThreeNodePilot`

## Limitações conscientes do piloto

- Persistência JSON por nó (não SQL multi-writer) — suficiente para sobreviver a restart do processo  
- Identity / Jobs ainda InMemory no Adapter #2 (fora do caminho courier)  
- UI HTTP completa do eleitor no Adapter #2 fica para endurecimento seguinte  
- Adapter #1 permanece o caminho de produção até o cliente piloto migrar nó a nó  

## Critério M6

| Go | No-go |
|----|-------|
| 3 nós, um modo cada, material só via courier | Sync automático entre nós |
| Crypto real (ElGamal + Shamir + tally) sem boot do host | Um único runtime a fingir os 3 papéis |

Verificação fechada: `docs/verificacao-m6.md`.  
Endurecimento pós-M6 (persistência durável): `docs/verificacao-a61-persistencia.md`.
