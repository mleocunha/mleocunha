# Votador — Prova de Conceito

Ferramenta local de stress/votação para a **RelataSoft Secure Election Suite**. Abre **Google Chrome real** (Playwright, modo headed), processa o CSV de cadastro eleitoral em lotes de **5 janelas × 5 contextos = 25** sessões paralelas, e grava o **hash do recibo** de cada voto.

Credenciais de admin, senhas de eleitores e CSVs **não são commitados** — permanecem só nesta máquina (`credentials/`, `uploads/`, `results/` estão no `.gitignore`).

## Requisitos

- Node.js 18+
- Google Chrome instalado
- Plataforma WordPress com o plugin RSES ≥ **1.0.19** (marcadores `data-rses-*`, JSON de eleições abertas, override `?election_id=&round_id=` na cabina)
- Eleições com rodadas **open**
- Página da cabina com `[rses_voting_booth]` e Redirecionamentos configurados (em Redirecionamentos → “Create welcome, booth & thank-you pages”)

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
| CSV | Mesmo formato do **Importador de cadastro eleitoral** (`password` como última coluna) |
| Tentativas (x) | Máximo de eleitores pulados/registrados no teste (default 50) |
| Insistências (n) | Retentativas por cada falha (default 3) |
| Limite máximo de retentativas (y) | Teto **por falha**: se as insistências daquela falha chegarem a y, o teste para (default 3) |

Com `n < y`, um eleitor pode esgotar as insistências, ser pulado/registrado e contar para x. Com `n ≥ y`, a primeira falha que atingir y insistências encerra o teste inteiro.

## CLI

```bash
npm run vote -- \
  --url https://votacao.exemplo.gov.br \
  --csv ./meu-cadastro.csv \
  --admin admin \
  --admin-pass '***' \
  --login /wp-login.php
```

Senha admin também pode vir de `RSES_ADMIN_PASS`.

## Fluxo automatizado

1. Login admin → `admin-post.php?action=rses_dump_open_elections` (fallback: `#rses-open-elections-json` na lista de eleições).
2. Para cada eleitor do CSV: login → boas-vindas → **todas** as eleições abertas → boletim com escolhas aleatórias (respeita min/max) → confirma `confirm()` → captura `#rses-journey-receipt-hash`.
3. Após o primeiro voto bem-sucedido, as URLs da jornada (welcome / booth / thank-you) ficam em cache para a execução.
4. Resultados em `results/<timestamp>/`:
   - `receipts.csv` — hashes
   - `events.ndjson` — progresso
   - `failures.ndjson` — falhas
   - `summary.json` — totais

## PoC com troca de senha

Opção desmarcada por padrão. Quando marcada:

1. Campo **URL do Roundcube** aparece logo abaixo do checkbox (default `https://relatasoft.com.br/mail/`).
2. Descobre o locale preferido a partir de `html[lang]` na página de login (sem autenticar o eleitor).
3. Por eleitor (Chrome **headed**, nunca headless; paralelo entre contextos):
   1. Se já existir senha WP em `credentials/generated-passwords.csv` e ela autenticar → pula o reset.
   2. Senão: na URL de login da plataforma, clica **Recuperar minha senha** / lostpassword (ainda **sem** sessão WP).
   3. Roundcube com `user_email` + senha de e-mail do CSV (`password` — **inalterada**).
   4. Aguarda INBOX (assunto traduzido, ex. pt_BR *Redefinição de Senha Eleitora*, com fallback para outros locales / assuntos de reset).
   5. Abre o link `action=rp`, define nova senha WP (8 chars, sem ambíguos), marca o e-mail como lido.
   6. Grava a senha WP em `credentials/generated-passwords.csv` → logoff/cookies limpos → login WP com a senha nova → vota.
4. Cópia das senhas também em `results/<timestamp>/passwords.csv`.

CLI: `--password-change --mail-url https://relatasoft.com.br/mail/`

> O shortcode `[enviar_redefinicao_senha]` permanece disponível no plugin (útil para testes manuais logados), mas o Votador PoC **não** depende mais dele: o disparo é pelo fluxo nativo *Recuperar minha senha*.

## Notas

- Cada “aba” é um **BrowserContext** Playwright (jar de cookies isolado), agrupado em 5 processos Chrome.
- Já votou: trata como sucesso se houver recibo na cabina; caso contrário registra `already_voted`.
- Direção futura: material gerado aqui alimenta um **Certificador de Ambiente Eleitoral**.
- Não versionar CSVs reais com senhas.
