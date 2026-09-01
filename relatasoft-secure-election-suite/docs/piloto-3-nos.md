# Piloto — três nós standalone

Guião de piloto do **Voto Eletrônico** com três sítios isolados
(`key_authority`, `voting`, `tallying`). Activação e operação só via pacote PHP
(`bin/ve-http` / `index.php`).

## Objectivo

1. Activar três nós sem CMS.
2. Identidade local por sítio (`identity.json`).
3. Cadastro via `.rsv` no nó de votação.
4. Ciclo E3 com parcelas e courier.
5. Operador em `/painel` e eleitor em `/voto`.

## Material

- Pacote com `composer install`
- PHP 8.2+ com GMP
- Três `VE_DATA` + `courier/` partilhado (`docs/operacao-standalone.md`)
- Opcional: nginx TLS
- Ficheiro `.rsv` de ensaio

## Dia 0 — activação

Seguir `docs/activar-standalone.md` em cada modo:

```bash
php bin/ve-http --mode=key_authority --data=/var/lib/ve/ka --port=8881
php bin/ve-http --mode=voting --data=/var/lib/ve/voting --port=8882
php bin/ve-http --mode=tallying --data=/var/lib/ve/tallying --port=8883
```

Confirmar `/login` e, no voting, `/voto`. Registar URLs na ficha do piloto.

## Dia 1 — identidade e chave

1. Login admin em cada nó (contas não sincronizam).
2. Criar operadores necessários *naquele* sítio.
3. KA: `/painel/keygen` → courier com chave pública e parcelas.

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
