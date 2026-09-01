# Operação standalone (três sítios)

Operação contínua: **um processo PHP por modo E3**, árvore `VE_DATA` própria,
courier por ficheiros. Sem sincronização automática de identidade ou base.

## Modelo

| Conceito | Significado |
|----------|-------------|
| Nó / sítio | Um `bin/ve-http` (ou `php -S index.php`) + um `VE_DATA` + um `VE_MODE` |
| Cliente típico | Três nós: `key_authority`, `voting`, `tallying` |
| Courier | Directorio JSON partilhado: `dirname(VE_DATA)/courier` |
| Parcela | Share Shamir — nunca misturar `secrets` entre sítios |
| URLs | `/login`, `/painel`, `/voto` (estáveis para nginx / clientes) |

## Layout recomendado

```text
/var/lib/ve/
  ka/           # VE_DATA + VE_MODE=key_authority
  voting/       # VE_DATA + VE_MODE=voting
  tallying/     # VE_DATA + VE_MODE=tallying
  courier/      # partilhado pelos três
```

## Arranque

```bash
php bin/ve-http --mode=key_authority --data=/var/lib/ve/ka --host=127.0.0.1 --port=8881
php bin/ve-http --mode=voting --data=/var/lib/ve/voting --host=127.0.0.1 --port=8882
php bin/ve-http --mode=tallying --data=/var/lib/ve/tallying --host=127.0.0.1 --port=8883
```

Proxy TLS por sítio → loopback. Definir `VE_PUBLIC_BASE` se o `ve-http` não for a URL pública.

## Identidade

- `identity.json` dentro de cada `VE_DATA`.
- Contas **não** replicam entre nós — criar o necessário em cada sítio.
- Sessão de operador: cookie após `/login`.

## Cadastro (nó voting)

Importar `.rsv` em `/painel/cadastro`:

```text
identificador;nome;papel
```

## Fluxo de material (courier)

1. KA: `/painel/keygen` → `public-key.json` e `parcela-*.json` em `courier/`.
2. Voting: confirmar courier → votar → exportar material de voto para `courier/`.
3. Tallying: `/painel/importar` + parcelas → `/painel/certificar`.

Permissões: os três processos devem ler/escrever o mesmo `courier/`.

## Becape

1. Parar o processo do nó (ou garantir quiescência).
2. Copiar a árvore `VE_DATA` (incluir identidade, persistência, secrets, audit).
3. Courier: becape à parte se houver filas pendentes.
4. Restauro: mesma árvore + mesmo `VE_MODE`; nunca misturar secrets de nós distintos.

## Observabilidade

- `journalctl` / logs do processo.
- Auditoria sob `VE_DATA` (sem parcelas em claro).
- Disco em `VE_DATA` e `courier/`.

## Limitações conscientes (piloto HTTP)

- Jobs async no HTTP podem ser InMemory (reinício perde fila).
- Cabina HTTP: boletim mínimo (0/1) nesta superfície.
- Certificação HTTP: alinhar com piloto CLI/`ve-node` quando o fluxo completo for exigido.

## CLI auxiliar

```bash
php bin/ve-node pilot --root=/tmp/ve-piloto --cliente=piloto --votes=2
```

Útil para validar o caminho criptográfico ponta a ponta sem browser.
