# Operação standalone (três sítios)

Operação contínua: **um processo PHP por modo E3**, árvore `VE_DATA` própria,
courier por ficheiros. Sem sincronização automática de identidade ou base.

## Testes / demonstração vs produção

| Contexto | O que é correcto |
|----------|------------------|
| **Testes e demonstrações** | Três processos no **mesmo** anfitrião (`key_authority`, `voting`, `tallying`), três `VE_DATA`, portas distintas. Pode usar `&` ou três terminais. Isolamento lógico de dados e modos — suficiente para lab e PoC. |
| **Produção** | Cada instância num **servidor independente e segregado**, preferencialmente em **nuvens distintas**. **Administradores de sistemas distintos**, preferencialmente que **nem se conheçam**; **contratações independentes**; preferencialmente **gestores de contrato independentes**. Todos respondem à **autoridade eleitoral superior**, preferencialmente **colegiada**. |

Colocar os três modos no mesmo host em produção **enfraquece** o modelo E3: um único operador de infra com acesso aos três `VE_DATA` concentra risco que o desenho criptográfico e organizacional pretende dispersar.

## Modelo

| Conceito | Significado |
|----------|-------------|
| Nó / sítio | Um `bin/ve-http` (ou `php -S index.php`) + um `VE_DATA` + um `VE_MODE` |
| Cliente típico | Três nós: `key_authority`, `voting`, `tallying` |
| Courier | Canal de material entre nós (lab: `dirname(VE_DATA)/courier`; produção: transferência auditável entre sítios) |
| Parcela | Share Shamir — nunca misturar `secrets` entre sítios |
| URLs | `/login`, `/painel`, `/voto` (estáveis para nginx / clientes) |

## Layout — testes / demonstração (um anfitrião)

```text
$HOME/ve-data/   (ou /var/lib/ve/)
  ka/
  voting/
  tallying/
  courier/     # partilhado pelos três processos locais
```

```bash
mkdir -p "$HOME/ve-data"/{ka,voting,tallying,courier}

php bin/ve-http --mode=key_authority --data="$HOME/ve-data/ka" \
  --host=10.42.0.1 --port=8888 &
php bin/ve-http --mode=voting --data="$HOME/ve-data/voting" \
  --host=10.42.0.1 --port=8889 &
php bin/ve-http --mode=tallying --data="$HOME/ve-data/tallying" \
  --host=10.42.0.1 --port=8890
```

Confirmar as três escutas: `ss -ltnp | grep -E '8888|8889|8890'`.

## Layout — produção (três anfitriões)

Cada servidor corre **apenas um** modo, com `VE_DATA` local e sem partilhar filesystem de secrets com os outros sítios. O courier **não** é um NFS comum aos três em produção típica: o material (chave pública, parcelas, exportações) move-se por canal controlado e auditável entre equipas/sítios.

Proxy TLS por sítio. Definir `VE_PUBLIC_BASE` com a URL pública real.

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
