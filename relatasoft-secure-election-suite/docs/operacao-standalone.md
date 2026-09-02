# Operação standalone (três sítios)

Operação contínua: **um processo PHP por modo E3**, árvore `VE_DATA` própria,
courier por arquivos. Sem sincronização automática de identidade ou base.

## Testes / demonstração vs produção

| Contexto | O que é correto |
|----------|------------------|
| **Testes e demonstrações** | Três processos no **mesmo** anfitrião (`key_authority`, `voting`, `tallying`), três `VE_DATA`, portas distintas. Pode usar `&` ou três terminais. Isolamento lógico de dados e modos — suficiente para lab e PoC. |
| **Produção** | Cada instância num **servidor independente e segregado**, preferencialmente em **nuvens distintas**. **Administradores de sistemas distintos**, preferencialmente que **nem se conheçam**; **contratações independentes**; preferencialmente **gestores de contrato independentes**. Todos respondem à **autoridade eleitoral superior**, preferencialmente **colegiada**. |

Colocar os três modos no mesmo host em produção **enfraquece** o modelo E3: um único operador de infra com acesso aos três `VE_DATA` concentra risco que o desenho criptográfico e organizacional pretende dispersar.

## Modelo

| Conceito | Significado |
|----------|-------------|
| Nó / sítio | Um `bin/ve-http` (ou `php -S index.php`) + um `VE_DATA` + um `VE_MODE` |
| Cliente típico | Três nós: `key_authority`, `voting`, `tallying` |
| Courier | Caixa local `VE_DATA/courier` de cada nó; entre sítios só cópia manual / canal auditável (nunca FS partilhado) |
| Parcela | Share Shamir — nunca misturar `secrets` entre sítios |
| URLs | `/login`, `/painel`, `/voto` (estáveis para nginx / clientes) |

## Layout — testes / demonstração (um anfitrião)

```text
$HOME/ve-data/   (ou /var/lib/ve/)
  ka/
    courier/      # só o processo KA
  voting/
    courier/      # só o processo voting
  tallying/
    courier/      # só o processo tallying
```

Lab no mesmo anfitrião **não** implica pasta partilhada: o operador (ou o piloto CLI) copia ficheiros de um courier para o outro.

```bash
mkdir -p "$HOME/ve-data"/{ka,voting,tallying}

php bin/ve-http --mode=key_authority --data="$HOME/ve-data/ka" \
  --host=10.42.0.1 --port=8888 &
php bin/ve-http --mode=voting --data="$HOME/ve-data/voting" \
  --host=10.42.0.1 --port=8889 &
php bin/ve-http --mode=tallying --data="$HOME/ve-data/tallying" \
  --host=10.42.0.1 --port=8890
```

Confirmar as três escutas: `ss -ltnp | grep -E '8888|8889|8890'`.

## Layout — produção (três anfitriões)

Cada servidor corre **apenas um** modo, com `VE_DATA` local e sem partilhar filesystem com os outros sítios. O courier é sempre `VE_DATA/courier` do próprio nó; o material (chave pública, parcelas, exportações) move-se por canal controlado e auditável entre equipas/sítios — nunca NFS/SMB comum aos três.

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

1. KA: `/painel/autoridades` → `/painel/keygen` → ficheiros em `ka/courier/` (`public-key.json`, `parcela-*.json`, `authorities.json`).
2. Transferir esses ficheiros para `voting/courier/` e `tallying/courier/` (descarregar/upload ou `cp` no lab).
3. Voting: importar `authorities.json` → votar → exportar material de voto no courier local → transferir `vote-material.json` para `tallying/courier/`.
4. Tallying: importar `authorities.json` → importar material → autoridades submetem parcelas em `/painel/parcelas` até ao limiar → certificar.

Sem autoridades no nó de apuração, as parcelas não sobem e o limiar Shamir não é atingido.

## Becape

1. Parar o processo do nó (ou garantir quiescência).
2. Copiar a árvore `VE_DATA` (incluir identidade, persistência, secrets, audit).
3. Courier local de cada nó já entra no becape do `VE_DATA`; se houver filas pendentes noutro canal, becape à parte.
4. Restauro: mesma árvore + mesmo `VE_MODE`; nunca misturar secrets de nós distintos.

## Observabilidade

- `journalctl` / logs do processo.
- Auditoria sob `VE_DATA` (sem parcelas em claro).
- Disco em cada `VE_DATA` (inclui o `courier/` local).

## Limitações conscientes (piloto HTTP)

- Jobs async no HTTP podem ser InMemory (reinício perde fila).
- Cabina HTTP: boletim mínimo (0/1) nesta superfície.
- Certificação HTTP: alinhar com piloto CLI/`ve-node` quando o fluxo completo for exigido.

## CLI auxiliar

```bash
php bin/ve-node pilot --root=/tmp/ve-piloto --cliente=piloto --votes=2
```

Útil para validar o caminho criptográfico ponta a ponta sem browser.
