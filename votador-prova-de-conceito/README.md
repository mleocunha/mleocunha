# Votador — Prova de Conceito

Ferramenta local de stress/votação para a **RelataSoft Secure Election Suite**. Abre **Google Chrome real** (Playwright, modo headed), processa o cadastro eleitoral **`.rsv`** em lotes de **5 janelas × 5 contextos = 25** sessões paralelas, e grava o **hash do recibo** de cada voto.

Credenciais de admin, senhas de eleitores e ficheiros `.rsv` reais **não são commitados** — permanecem só nesta máquina (`credentials/`, `uploads/`, `results/` estão no `.gitignore`).

## Requisitos

- Node.js 18+
- Google Chrome instalado
- Plataforma WordPress com o plugin RSES ≥ **1.0.19** (marcadores `data-rses-*`, JSON de eleições abertas, override `?election_id=&round_id=` na cabina)
- Eleições com rodadas **open**
- Página da cabina com `[rses_voting_booth]` e Redirecionamentos configurados (em Redirecionamentos → “Create welcome, booth & thank-you pages”)
- Cadastro importado no sítio no formato **`.rsv`** do Painel (Cadastro Eleitoral)

### macOS (Tahoe / Apple Silicon)

1. Instale o Google Chrome (Chrome.app).
2. Em **Ajustes do Sistema → Privacidade e Segurança**, autorize **Automação** e/ou **Acessibilidade** para o app que inicia o Votador (Terminal, iTerm, Cursor) e, se solicitado, para o próprio Chrome.
3. Se o Chrome não for detectado pelo canal `chrome`, informe o caminho completo no formulário, por exemplo:

   `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`

4. Em cada execução o Votador sobe `caffeinate -d` automaticamente para a tela não apagar (Chrome headed trava quando o display dorme). O processo encerra ao terminar a corrida.

## Instalação

```bash
cd votador-prova-de-conceito
npm install
```

## Formato `.rsv` (Cadastro Eleitoral)

Mesmo contrato do plugin (`RsvFormat`): campos separados por `:`, séries (vários e-mails/celulares) por `;`, vírgula só em texto livre (endereço).

```
login:numerodeidentificacaocivil:numerodeidentificacaoeleitoral:regiaoeleitoralampla:regiaoeleitoralespecifica:nomecompleto:celular:email:endereco:papel:senha
```

O Votador **só processa linhas com `papel=eleitor`** (ou `subscriber` legado). Auditor/autoridade/administrador/gestor no mesmo ficheiro são ignorados.

Exemplo: `samples/exemplo-cadastro.rsv`.

## Interface (recomendado)

```bash
npm start
```

Abra `http://127.0.0.1:3847` e preencha:

| Campo | Descrição |
|--------|-----------|
| URL da plataforma | Sempre obrigatória |
| Caminho de login | `/id.php`, `/wp-login.php` ou personalizado (sucesso = sair do endpoint de login sem `#login_error`) |
| Admin | Usuário/senha WP admin (só para descobrir eleições abertas; não é persistido) |
| RSV | Mesmo formato do **Cadastro Eleitoral** do Painel |
| Tentativas (x) | Máximo de eleitores pulados/registrados no teste (default 50) |
| Insistências (n) | Retentativas por cada falha (default 3) |
| Limite máximo de retentativas (y) | Teto **por falha**: se as insistências daquela falha chegarem a y, o teste para (default 3) |

Com `n < y`, um eleitor pode esgotar as insistências, ser pulado/registrado e contar para x. Com `n ≥ y`, a primeira falha que atingir y insistências encerra o teste inteiro.

## CLI

```bash
npm run vote -- \
  --url https://votacao.exemplo.gov.br \
  --rsv ./meu-cadastro.rsv \
  --admin admin \
  --admin-pass '***' \
  --login /id.php
```

Senha admin também pode vir de `RSES_ADMIN_PASS`.  
`--csv` continua como alias depreciado de `--rsv` (o ficheiro tem de ser `.rsv`).

## Fluxo automatizado

1. Login admin → `admin-post.php?action=rses_dump_open_elections` (fallback: `#rses-open-elections-json` na lista de eleições).
2. Para cada eleitor do RSV: login → boas-vindas → **todas** as eleições abertas → boletim com escolhas aleatórias (respeita min/max) → confirma `confirm()` → captura `#rses-journey-receipt-hash`.
3. Após o primeiro voto bem-sucedido, as URLs da jornada (welcome / booth / thank-you) ficam em cache para a execução.
4. Resultados em `results/<timestamp>/`:
   - `receipts.csv` — hashes
   - `events.ndjson` — progresso
   - `failures.ndjson` — falhas
   - `summary.json` — totais

## PoC com troca de senha

Opção desmarcada por padrão. Quando marcada:

1. Exige `[enviar_redefinicao_senha]` na página de boas-vindas (plugin RSES ≥ 1.0.20).
2. Cada eleitor precisa de `email` no RSV (primeiro da série se houver vários).
3. Fluxo: login RSV → shortcode de reset → Roundcube → nova senha WP → votar.
4. Senhas geradas em `credentials/generated-passwords.csv` (reutilizadas automaticamente; cópia também em `results/<timestamp>/passwords.csv`).

CLI: `--password-change --mail-url https://relatasoft.com.br/mail/`

## Testes locais (parser)

```bash
npm test
```

## Segurança

- Não versionar `.rsv` reais com senhas.
- Admin password never written to disk by the UI server.
