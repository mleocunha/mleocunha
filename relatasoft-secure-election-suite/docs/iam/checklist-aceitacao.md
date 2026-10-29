# Checklist de aceitação — IAM / gov.br / ICP

Estados: **C** concluído e verificado · **P** pendente · **N/A** · **B** bloqueado externo

| Critério | Estado | Evidência |
|----------|--------|-----------|
| Projeto auditado | C | `docs/iam/diagnostico-inicial.md` |
| Stack identificada | C | PHP 8.2+, standalone HTTP + WP |
| Campos revisados (minimização) | C | Sem CPF/telefone/endereço obrigatórios |
| Modelo de dados consistente | C | status/uuid/timestamps + blobs |
| Migrations seguras | C | JSON retrocompatível (defaults) |
| Dados existentes preservados | C | Sem deletes destrutivos |
| Validação backend | C | PasswordPolicy + create/update |
| Frontend integrado | C | Cadastro + Central de segurança |
| Hash apropriado | C | Argon2id quando disponível; sem `plain:` |
| AuthN revisada | C | Login + status + rate limit + CSRF |
| Recuperação senha standalone | P | WP shortcode existe; standalone: alteração autenticada |
| Verificação e-mail | P | Campo previsto; fluxo token não ativado |
| Sessões seguras | C | Idle/absolute TTL; revoke; Secure condicional |
| AuthZ backend | C | Admin cadastro; role allowlist; voto=ROLE_VOTER |
| Anti privilege escalation | C | Teste mass-assignment |
| Anti IDOR básico | C | Sessão própria; status gate |
| Anti mass assignment | C | Verificado |
| Rate limiting | C | LoginRateLimiter |
| Auditoria segurança | C | StandaloneSecurityAudit |
| Classificação dados | C | DataClassification |
| Criptografia proporcional | C | Senhas=hash; sem chave privada no cadastro |
| KMS/HSM | B | Operacional / infraestrutura |
| Sem segredos no código | C | Env vars; defaults só PoC |
| Artefactos crypto validados | C | X509 inspector estrutural |
| Privacidade/retenção | C | Docs; retenção eventos 5000 |
| Testes automatizados | C | 125 PHPUnit OK |
| Testes segurança | C | CSRF, suspend, mass-assign, rate limit |
| Build/testes | C | `phpunit` OK |
| gov.br fluxo completo homologado | B | Credenciamento |
| ICP cadeia+OCSP | B | Âncoras ITI |
| MFA | N/A | Não simulado |
