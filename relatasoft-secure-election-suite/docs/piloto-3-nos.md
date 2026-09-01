# Piloto — três nós standalone

Guião de piloto do **Voto Eletrônico** com três sítios isolados
(`key_authority`, `voting`, `tallying`). Activação e operação só via pacote PHP
(`bin/ve-http` / `index.php`).

**Piloto / demonstração:** os três processos no **mesmo** anfitrião (três `VE_DATA`,
três portas) são a configuração **correcta** para este guião.  
**Produção eleitoral:** cada modo em servidor independente e segregado —
preferencialmente nuvens distintas, administradores e contratos independentes,
sob autoridade eleitoral superior preferencialmente colegiada
(`docs/operacao-standalone.md`).

## Objectivo

1. Activar três nós sem CMS.
2. Identidade local por sítio (`identity.json`).
3. Cadastro via `.rsv` no nó de votação.
4. Ciclo E3 com parcelas e courier.
5. Operador em `/painel` e eleitor em `/voto`.

## Material

- Pacote com `composer install`
- PHP 8.2+ com GMP
- Três `VE_DATA` + `courier/` partilhado no lab (`docs/operacao-standalone.md`)
- Opcional: nginx TLS
- Ficheiro `.rsv` de ensaio

## Dia 0 — activação

Seguir `docs/activar-standalone.md`. No lab (um anfitrião):

```bash
mkdir -p "$HOME/ve-data"/{ka,voting,tallying,courier}

php bin/ve-http --mode=key_authority --data="$HOME/ve-data/ka" \
  --host=10.42.0.1 --port=8888 &
php bin/ve-http --mode=voting --data="$HOME/ve-data/voting" \
  --host=10.42.0.1 --port=8889 &
php bin/ve-http --mode=tallying --data="$HOME/ve-data/tallying" \
  --host=10.42.0.1 --port=8890
```

Confirmar as três portas, `/login` em cada uma e, no voting, `/voto`. Registar URLs na ficha do piloto.

## Dia 1 — identidade, autoridades e chave

1. Login admin em cada nó (contas não sincronizam).
2. No **KA**: cadastrar autoridades eleitorais em `/painel/autoridades` (pelo menos *n* para *n* parcelas).
3. KA: `/painel/keygen` — seleccionar exactamente *n* autoridades → gerar chave e atribuir parcelas → courier.
4. Nos outros sítios, criar operadores necessários *naquele* sítio.

## Dia 2 — cadastro e voto

1. Voting: importar `.rsv` em `/painel/cadastro`.
2. Confirmar material do courier.
3. Exercitar `/voto` / cabine.
4. Exportar material de voto para o courier.

## Dia 3 — apuramento

1. Tallying: importar material + parcelas.
2. Certificar conforme o painel.
3. Opcional: validar crypto ponta a ponta com `php bin/ve-node pilot --root=/tmp/ve-piloto`.
4. Becape de cada `VE_DATA` + courier.

## Critérios de aceite

- [ ] Três nós sobem com modos distintos e `VE_DATA` distintos
- [ ] Parar um nó não expõe secrets dos outros
- [ ] RSV importa no voting
- [ ] Courier entrega chave/parcelas/material entre sítios
- [ ] Operador e eleitor usam só HTTP deste pacote
- [ ] PHPUnit relevante verde na build de referência

## Falhas frequentes

| Sintoma | Verificar |
|---------|-----------|
| 500 ao abrir | `VE_MODE` e `VE_DATA` definidos? `composer install`? |
| Login falha | `VE_ADMIN_*` no mesmo processo; `identity.json` já com outra senha? |
| Courier vazio | Path `dirname(data)/courier`; permissões; data dirs irmãos |
| GMP | Extensão `gmp` instalada |

## Legado

Textos sobre *plugin*, *mu-plugin*, *wp-admin* ou *Adapter #1* são históricos de
migração — não usar como procedimento de activação deste piloto.
