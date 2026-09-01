# Verificação HTTP standalone

Checklist após activação. Um processo = um modo E3.

Três processos no mesmo anfitrião (como abaixo) são a topologia **correcta para
testes e demonstrações**. Produção: um modo por servidor segregado —
`docs/operacao-standalone.md`.

## Preparação

```bash
cd relatasoft-secure-election-suite
composer install
rm -rf /tmp/ve-http-check && mkdir -p /tmp/ve-http-check/{ka,voting,tallying,courier}
export VE_ADMIN_LOGIN=admin VE_ADMIN_PASS='AdminPoC1!'
php bin/ve-http --mode=key_authority --data=/tmp/ve-http-check/ka --host=127.0.0.1 --port=8888 &
php bin/ve-http --mode=voting --data=/tmp/ve-http-check/voting --host=127.0.0.1 --port=8889 &
php bin/ve-http --mode=tallying --data=/tmp/ve-http-check/tallying --host=127.0.0.1 --port=8890 &
sleep 1
```

## Smoke UI

| # | Acção | Esperado |
|---|--------|----------|
| 1 | `GET http://127.0.0.1:8888/login` | Formulário |
| 2 | Login admin no KA | Redireciona a `/painel` |
| 3 | `/painel/keygen` | Gera chave; ficheiros no courier |
| 4 | Login no voting `:8889` | Painel com Cadastro / Voto |
| 5 | Importar `.rsv` mínimo | Linhas no cadastro |
| 6 | `/voto` → cabina | Fluxo de voto |
| 7 | Tallying `:8890` importar/certificar | Usa material + parcelas do courier |
| 8 | `/assets/painel/css/shell.css` | 200 |

## Testes automatizados

```bash
./vendor/bin/phpunit --filter 'StandaloneHttpTest|DurablePersistenceTest|ThreeNodePilotTest|RsvFormatTest'
```

## Residual conhecido

- Jobs async HTTP InMemory em parte dos fluxos  
- Cabina: voto mínimo 0/1  
- Certificação HTTP pode ser registo parcial vs piloto CLI completo  

**Veredicto alvo:** superfície HTTP nos três modos operacional para piloto.
