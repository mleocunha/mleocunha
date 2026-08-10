# FASE 1 — Auditoria do Ambiente

Data: 2026-08-10  
Ambiente de execução: Cursor Cloud Agent (`bc-375744ac-0415-4b81-827f-cf539bebf6d0`)  
Repositório: `github.com/mleocunha/mleocunha` (perfil GitHub; greenfield para este projeto)

## 1. Resumo executivo

Este host de desenvolvimento **não possui Virtualmin, Webmin, Postfix, Dovecot, Nginx/Apache nem PHP instalados**.  
A auditoria abaixo combina:

1. inventário factual deste ambiente;
2. análise da API oficial/CLI do Virtualmin GPL (fonte `virtualmin/virtualmin-gpl` + docs.virtualmin.com);
3. modelo de release do SnappyMail (GitHub `the-djmaze/snappymail`).

**Consequência arquitetural:** o gerenciador será um pacote portável instalável em servidores Virtualmin reais. Toda chamada a `virtualmin` deve ser descoberta/validada em runtime (`virtualmin help` / `--help`), nunca hardcoded cegamente. Testes de aceitação end-to-end (subserver real, HTTPS, IMAP) exigem um host Virtualmin; aqui cobrimos unitários + integração com adaptador mock.

Nenhuma alteração destrutiva foi feita no sistema durante esta fase.

---

## 2. Inventário deste ambiente

| Componente | Resultado |
|---|---|
| SO | Ubuntu 24.04.4 LTS (Noble), kernel 6.12.94+, x86_64 |
| Hostname | `cursor` |
| Perl | 5.38.2 (`/usr/bin/perl`) |
| Python | 3.12.3 (`/usr/bin/python3`) |
| OpenSSL | 3.0.13 |
| curl / wget / jq | presentes |
| Webmin | **ausente** (`/usr/share/webmin`, `/etc/webmin` inexistentes) |
| Virtualmin | **ausente** (`virtualmin` not in PATH) |
| PHP / PHP-FPM | **ausente** |
| Nginx / Apache | **ausente** |
| Postfix | **ausente** |
| Dovecot | **ausente** |
| Certbot | **ausente** |
| `/home` | apenas usuário `ubuntu` |
| Pacotes dpkg mail/web | nenhum encontrado |
| Environment Cursor | sem environment.json vinculado; egress unrestricted |

---

## 3. Modelo Virtualmin (documentação + fonte GPL)

### 3.1 CLI oficial

Formato:

```text
virtualmin <comando> [--flags...]
virtualmin help
virtualmin help <comando>
```

Deve correr como root. Exit code 0 = sucesso.

Comandos relevantes confirmados na documentação/fonte:

| Comando | Uso previsto |
|---|---|
| `list-domains` | validar pai, listar filhos, features, home, HTML dir |
| `list-features --parent DOM` | features disponíveis para subserver |
| `create-domain` | criar subserver web-only |
| `delete-domain` | remover subserver (remove) |
| `enable-feature` / `disable-feature` | corrigir features |
| `modify-web` | PHP mode/version, document-dir |
| `generate-letsencrypt-cert` | SSL nativo |
| `generate-cert` | fallback self-signed |
| `backup-domain` / `restore-domain` | portabilidade |
| `list-users` | caixas do domínio pai (diagnóstico) |

### 3.2 Sub-server vs Sub-domain (crítico)

| Flag | Significado | Árvore típica | Uso neste projeto |
|---|---|---|---|
| `--parent DOM` | **Sub-server** (filho administrativo) | `/home/USER/domains/webmail.DOM/` | **OBRIGATÓRIO** |
| `--subdom DOM` | Sub-domínio sob `public_html` do pai | `/home/USER/public_html/<prefix>/` | **PROIBIDO** |

A invariável `webmail.*` exige `--parent`, nunca `--subdom`.

### 3.3 Criação web-only (sintaxe documentada)

Padrão a validar em runtime no host alvo:

```bash
virtualmin create-domain \
  --domain webmail.exemplo.com.br \
  --parent exemplo.com.br \
  --desc "SnappyMail (web-only)" \
  --dir --dns --web --ssl --logrotate \
  --letsencrypt
```

**Omitir `--mail`.** Não usar `--default-features` / `--features-from-plan` (podem ligar mail).

Após criar, verificação obrigatória via:

```bash
virtualmin list-domains --domain webmail.exemplo.com.br --multiline
```

Esperado:

- `Type: Sub-server`
- `Parent domain: exemplo.com.br`
- `Features:` contém `dir web ...` e **não** contém `mail`

Se `mail` aparecer → erro de provisionamento.

### 3.4 Relação pai/filho

Em `list-domains --multiline`:

- Sub-server: `Type: Sub-server` + `Parent domain: ...`
- Filtro: `list-domains --parent exemplo.com.br --name-only`
- Features ativas: linha `Features: unix dir dns mail web ssl ...` (códigos separados por espaço)
- Paths: `Home directory:`, `HTML directory:` (via `public_html_dir`)
- Username: `Username:`

Domínios persistidos em `/etc/webmin/virtual-server/domains/*` (chave/valor: `dom=`, `user=`, `home=`, `parent=`, features booleanas).

### 3.5 SSL

Preferência nativa:

```bash
virtualmin generate-letsencrypt-cert --domain webmail.exemplo.com.br
# ou --letsencrypt na criação
```

Também existe `install-service-cert` (Postfix/Dovecot SNI no **pai**, não no subserver webmail).

### 3.6 Backup / restore

```bash
virtualmin backup-domain --domain exemplo.com.br --parent --all-features --newformat --dest /backup/
```

`--parent` inclui sub-servers e aliases. Home do subserver (com SnappyMail integral + manifesto) viaja no feature `dir`.

Pós-restore no servidor B:

```text
discover → adopt
```

Dados **não** transportados automaticamente: estado global do gerenciador em `/etc` (se houver), hooks instalados no host, políticas de template. O manifesto **junto à instalação** resolve a descoberta.

### 3.7 Hooks / eventos (confirmados na docs)

`$VIRTUALSERVER_ACTION` pode ser:

| Action | Uso |
|---|---|
| `CREATE_DOMAIN` | opcional; **não** auto-instalar SnappyMail sem política |
| `DELETE_DOMAIN` | limpeza conservadora se subserver gerido |
| `MODIFY_DOMAIN` | revalidar features se necessário |
| `DISABLE_DOMAIN` / `ENABLE_DOMAIN` | status |
| `RESTORE_DOMAIN` | candidato a `adopt` assistido |
| `SSL_DOMAIN` | revalidar HTTPS |
| `DBNAME_DOMAIN` / `DBPASS_DOMAIN` | irrelevante (SnappyMail sem DB) |

Configuração: *Virtualmin Configuration → Actions upon server and user creation* (pre/post commands).

Alternativa superior a longo prazo: **plugin Virtualmin** (`virtual_feature.pl`) — feature opcional “SnappyMail” em templates/plans.

### 3.8 Plugins Webmin/Virtualmin

Módulo Webmin com `virtual_feature.pl` implementando `feature_setup`, `feature_delete`, `feature_backup`, `feature_restore`, etc.  
GUI reutiliza ACL Webmin; não criar login paralelo.

Roundcube já existe como *script installer* (`scripts/roundcube.pl`); SnappyMail **não** está no core GPL. Nosso gerenciador é independente (full-copy por domínio), alinhado à unidade backup/restore.

---

## 4. Stack de correio / web (alvo; a descobrir no host real)

Função de descoberta obrigatória (não assumir `127.0.0.1:993/587`):

| Fonte | O que ler |
|---|---|
| `postconf -n` | `inet_interfaces`, `smtpd_tls_*`, submission |
| `/etc/postfix/master.cf` | serviço `submission` / `smtps` |
| `doveconf -n` | `protocols`, `ssl=`, `service imap-login`, listeners |
| `hostname -f` / Virtualmin hostname | identidade TLS |
| `virtualmin list-domains --domain PAI --multiline` | IP, SSL do pai |
| `ss -tlnp` / `ss -tnlp` | bindings reais |
| PHP | `virtualmin list-domains` PHP mode + `php -m` / FPM pool |

Recomendação típica *quando* validado local:

- IMAPS no listener local/loopback ou hostname do servidor com TLS
- SMTP submission 587 STARTTLS (ou 465 SMTPS)
- Auth: mesma base Dovecot/Postfix do Virtualmin (usuário `user@pai`)

---

## 5. SnappyMail

| Item | Valor |
|---|---|
| Projeto | https://github.com/the-djmaze/snappymail |
| Release estável auditada | **v2.38.2** (2024-10-09) |
| Artefacto preferido | `snappymail-2.38.2.tar.gz` |
| Assinatura | `.asc` disponível (verificar quando chave confiável) |
| Upgrade oficial | extrair por cima; `data/` preservado; versões em `snappymail/v/` |
| DB | não requer |
| Config domínio | `data/_data_/_default_/domains/<dom>.ini` + `application.ini` |

Instalação **full-copy** por subserver em `HTML directory` do Virtualmin. Sem `/opt/snappymail` compartilhado.

---

## 6. Riscos

1. **Host de CI sem Virtualmin** — testes E2E limitados; mitigação: adapter + mocks + comando `audit`.
2. **`--default-features` / plan** — risco de ligar `mail` no subserver; mitigação: features explícitas + assert pós-create.
3. **Confundir `--subdom` com `--parent`** — quebraria a árvore e o backup; mitigação: API interna só usa `--parent`.
4. **DNS/LE no cloud/DNS externo** — LE pode falhar; repair/diagnose devem reportar claramente.
5. **IMAP/SMTP bindings não-locais** — config SnappyMail errada se assumirmos loopback; mitigação: discovery.
6. **Permissões PHP-FPM** — owner do VS vs `www-data`; seguir modelo Virtualmin do domínio.
7. **Perfil GitHub como repo** — projeto vive sob `virtualmin-snappymail/` sem alterar o README de perfil além do necessário.

---

## 7. Decisões técnicas (para FASE 2)

1. Pacote `virtualmin-snappymail/` autocontido no repositório.
2. **Python 3** para CLI e lógica (testável sem Virtualmin); **Perl** para módulo Webmin/plugin fino.
3. Toda interação Virtualmin via adapter que invoca a CLI oficial com escaping seguro; paths/homes sempre via `list-domains`.
4. Manifesto: `.virtualmin-snappymail.json` no home do subserver (fora de `public_html` se possível; fallback ao lado do docroot).
5. Política de hooks: conservadora; auto-install só com flag/config explícita.
6. Idioma de implementação CLI: Python 3.12 (já presente); shebang `/usr/bin/env python3`.

---

## 8. Próximo passo

FASE 2 — Design detalhado (componentes, fluxos, erros, rollback) e início da FASE 3 (MVP CLI).
