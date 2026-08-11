# FASE 2 — Design

## Objetivo

Gerir SnappyMail como recurso Virtualmin: um Sub-server **web-only** `webmail.<pai>` com instalação full-copy portável, CLI administrativa e plugin GUI (webapp manager).

## Princípio

```text
unidade administrativa = backup = restore = migração = instalação SnappyMail
```

Mail sempre no Virtual Server pai. Subserver = HTTP/HTTPS + PHP apenas.

## Componentes

```text
virtualmin-snappymail/
├── bin/virtualmin-snappymail          # entrypoint CLI
├── src/virtualmin_snappymail/
│   ├── cli.py                         # argparse / comandos
│   ├── domain.py                      # validação, webmail.FQDN
│   ├── virtualmin_client.py           # adapter CLI Virtualmin
│   ├── environment.py                 # auditoria runtime do host
│   ├── mail_discovery.py              # IMAP/SMTP reais
│   ├── snappymail_app.py              # download, extract, config, upgrade
│   ├── manifest.py                    # .virtualmin-snappymail.json
│   ├── ssl_ops.py                     # LE via Virtualmin
│   ├── ops_install.py
│   ├── ops_status.py
│   ├── ops_diagnose.py
│   ├── ops_repair.py
│   ├── ops_upgrade.py
│   ├── ops_remove.py
│   ├── ops_discover.py
│   ├── ops_adopt.py
│   ├── logging_util.py
│   ├── security.py                    # escaping, path safety
│   └── errors.py
├── hooks/post-domain-change.sh        # conservador
├── webmin/virtualmin-snappymail/      # Webmin GUI + virtual_feature plugin
├── tests/
├── docs/
├── VERSION
├── CHANGELOG.md
└── README.md
```

## Adapter Virtualmin

`VirtualminClient` encapsula subprocessos:

- `run(["list-domains", ...])` com lista de args (nunca `shell=True` com input do utilizador)
- Domínios validados por regex DNS estrita antes de qualquer chamada
- Métodos: `domain_exists`, `get_domain`, `list_children`, `create_web_only_subserver`, `delete_domain`, `enable/disable_feature`, `generate_letsencrypt`, `backup_hint`

Em testes: `FakeVirtualminClient`.

## Manifesto

Caminho preferido:

```text
{subserver_home}/.virtualmin-snappymail.json
```

Campos (sem segredos):

```json
{
  "managed": true,
  "schema_version": 1,
  "deployment": "full-copy",
  "manager_version": "0.1.0",
  "parent_domain": "exemplo.com.br",
  "webmail_domain": "webmail.exemplo.com.br",
  "version": "2.38.2",
  "installed_at": "2026-08-10T22:00:00Z",
  "updated_at": "2026-08-10T22:00:00Z",
  "document_root": "public_html",
  "mail_identity_domain": "exemplo.com.br"
}
```

## Fluxo install

```text
validate parent (exists + mail ON)
  → derive webmail.FQDN
  → if managed install exists → idempotent exit
  → if subserver exists → verify parent + mail OFF else abort
  → else create web-only subserver (+ dns/web/ssl/dir/logrotate)
  → assert mail OFF
  → SSL via Virtualmin
  → download+verify SnappyMail
  → extract into HTML dir
  → ownership/permissions
  → configure domain.ini for parent mail identity + discovered IMAP/SMTP
  → write manifesto
  → status/diagnose smoke
```

## Upgrade (transacional)

```text
backup tarball sob {home}/.snappymail-backups/
→ download new
→ extract to staging
→ swap code preserving data/ + plugins locais + include.php
→ smoke tests
→ on failure: restore tarball
```

## Erros

Códigos estáveis (`VSM-*`):

| Código | Significado |
|---|---|
| VSM-DOMAIN-INVALID | domínio malformado |
| VSM-PARENT-MISSING | pai inexistente |
| VSM-PARENT-NO-MAIL | pai sem feature mail |
| VSM-SUB-CONFLICT | subserver existe com propósito conflituoso |
| VSM-MAIL-ON-SUB | mail ligado no subserver |
| VSM-ALREADY-INSTALLED | idempotência |
| VSM-DOWNLOAD | falha download/integridade |
| VSM-VIRTUALMIN | CLI Virtualmin falhou |
| VSM-NOT-MANAGED | operação exige manifesto |

## Rollback

- install: se falhar após create, opção `--keep-subserver` (default keep) vs limpeza só do código
- upgrade: restore do tarball pré-upgrade
- repair: backup antes de alterações destrutivas seguras

## Saída

- humano por defeito
- `--json` estruturado
- `--verbose` / `--debug` (sem segredos)
