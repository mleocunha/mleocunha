# Verificação HTTP standalone

Checklist após ativação. Um processo = um modo E3.

Três processos no mesmo anfitrião (como abaixo) são a topologia **correcta para
testes e demonstrações**. Produção: um modo por servidor segregado —
`docs/operacao-standalone.md`.

## Preparação

```bash
cd relatasoft-secure-election-suite
composer install
rm -rf /tmp/ve-http-check && mkdir -p /tmp/ve-http-check/{ka,voting,tallying}
export VE_ADMIN_LOGIN=admin VE_ADMIN_PASS='AdminPoC1!'
php bin/ve-http --mode=key_authority --data=/tmp/ve-http-check/ka --host=127.0.0.1 --port=8888 &
php bin/ve-http --mode=voting --data=/tmp/ve-http-check/voting --host=127.0.0.1 --port=8889 &
php bin/ve-http --mode=tallying --data=/tmp/ve-http-check/tallying --host=127.0.0.1 --port=8890 &
sleep 1
```

## Smoke UI

| # | Ação | Esperado |
|---|--------|----------|
| 1 | `GET http://127.0.0.1:8888/login` | Formulário |
| 2 | Login admin na AC | Redireciona a `/painel` |
| 3 | `/painel/autoridades` — cadastrar ≥3 autoridades | Listadas na tabela |
| 4 | `/painel/keygen` — selecionar n e gerar | Parcelas na persistência; export sessão |
| 5 | Login no voting `:8889` | Painel com Cadastro / Voto |
| 6 | Importar `.rsv` mínimo | Eleitores listados no cadastro |
| 7 | `/painel/eleicoes` — criar | Eleição + turno + pergunta |
| 8 | `/voto` → cabina → `/painel/material-voto` | Descarregar `vote-material.json` |
| 9 | Tallying `:8890` upload material → parcelas → certificar | Total numérico na certificação |
| 10 | `/assets/painel/css/shell.css` | 200 |

## Testes automatizados

```bash
./vendor/bin/phpunit --filter 'StandaloneHttpTest|DurablePersistenceTest|ThreeNodePilotTest|RsvFormatTest'
```

## Residual conhecido

- Cabina: voto mínimo 0/1 (sem candidaturas multi-opção)  
- Keygen durável; outros jobs async podem ser InMemory  

**Veredicto alvo:** triângulo HTTP (criar eleição → votar/exportar → certificar) operacional nos três modos.
