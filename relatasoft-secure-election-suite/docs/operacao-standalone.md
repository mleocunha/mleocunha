# Operação standalone (três sítios)

Operação contínua: **um processo PHP por modo E3**, árvore `VE_DATA` própria,
material por descarregar/carregar na sessão. Sem sincronização automática de identidade ou base.

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
| Material | Descarregar na sessão / carregar upload entre sítios (nunca pasta Courier partilhada) |
| Parcela | Share Shamir — nunca misturar `secrets` entre sítios |
| URLs | `/login`, `/painel`, `/voto` (estáveis para nginx / clientes) |

## Layout — testes / demonstração (um anfitrião)

```text
$HOME/ve-data/   (ou /var/lib/ve/)
  ka/
  voting/
  tallying/
```

Lab no mesmo anfitrião **não** usa pasta Courier: o operador descarrega na sessão de um nó e carrega no outro (ou o piloto CLI simula o transporte).

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

Cada servidor corre **apenas um** modo, com `VE_DATA` local e sem partilhar filesystem com os outros sítios. O material (chave pública, autoridades, parcelas secretas, vote-material) move-se por descarregar/carregar na sessão — canal controlado e auditável entre equipas/sítios — nunca NFS/SMB comum aos três.

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

## Fluxo de material (sem Courier)

1. KA: `/painel/autoridades` → `/painel/keygen`. Admin ou cada autoridade descarrega `authorities.json` (parcela pública SSS, sem `share_value`) em `/painel/autoridades/exportar`. Cada autoridade descarrega a sua parcela secreta em `/painel/minha-parcela`. Chave pública em `/painel/chave/{id}.json`.
2. Voting/tallying: importar autoridades (upload) e chave pública em `/painel/chave-publica`.
3. Voting: criar eleição → votar → descarregar `vote-material.json` em `/painel/material-voto`.
4. Tallying: importar material (upload) → cada autoridade submete a sua parcela em `/painel/parcelas` → certificar.

Sem autoridades no nó de apuração, as parcelas não sobem e o limiar Shamir não é atingido.

## Becape

1. Parar o processo do nó (ou garantir quiescência).
2. Copiar a árvore `VE_DATA` (incluir identidade, persistência, secrets, audit).
3. Material pendente fora do `VE_DATA` (USB/exportações): becape à parte se houver filas pendentes.
4. Restauro: mesma árvore + mesmo `VE_MODE`; nunca misturar secrets de nós distintos.

## Observabilidade

- `journalctl` / logs do processo.
- Auditoria sob `VE_DATA` (sem parcelas em claro).
- Disco em cada `VE_DATA`.

## Limitações conscientes (piloto HTTP)

- Cabina HTTP: boletim mínimo sim/não (0/1); sem candidaturas multi-opção nesta superfície.
- Jobs async no HTTP: keygen é durável; outros fluxos podem ser InMemory conforme o adapter.
- Certificação HTTP: reconstrução Shamir + total homomórfico alinhados ao piloto CLI (`HomomorphicCertifyService`).

## CLI auxiliar

```bash
php bin/ve-node pilot --root=/tmp/ve-piloto --cliente=piloto --votes=2
```

Útil para validar o caminho criptográfico ponta a ponta sem browser.
