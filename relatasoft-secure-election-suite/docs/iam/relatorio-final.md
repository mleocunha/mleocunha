# Relatório final — IAM / cadastro / gov.br / ICP-Brasil

## 1. Resumo executivo

O módulo de identidade do Voto Eletrônico (standalone HTTP + contratos partilhados) foi reforçado: política de senhas (Argon2id), ciclo de vida de contas, CSRF, rate limit, sessões com revogação, auditoria de segurança, cadastro administrativo com anti mass-assignment, central de segurança, scaffold gov.br (desabilitado sem credenciamento) e inspeção X.509 sem falsa confiança ICP.

## 2. Funcionalidades implementadas

- Estados de conta + bloqueio de autenticação/sessão.
- PasswordPolicy + remoção do fallback `plain:` em novos hashes.
- CSRF em login/cadastro/autoridades/segurança (`VE_HTTP_TEST_MODE` só em PHPUnit).
- Rate limiting progressivo no login.
- `/painel/cadastro` (criar utilizador, suspender/reativar, RSV) — só admin.
- `/painel/seguranca` (senha, sessões, eventos, identidade federada).
- FederatedIdentityStore + GovBrOidcClient (PKCE/state/nonce).
- X509CertificateInspector (estrutural / untrusted sem âncoras).

## 3. Persistência

Campos novos em `users` (JSON): `uuid`, `status`, `createdAt`, `updatedAt`.  
Blobs: `security_events`, `login_throttle`, `federated_identities`, `oidc_pending`.

## 4. Controles de segurança

Ver `SECURITY.md` e `docs/iam/ameacas-stride.md`.

## 5. Dados criptográficos

Senhas: hash não reversível. Chaves privadas/parcelas SSS: **fora** do cadastro. Certificados: metadados/inspeção apenas.

## 6. Interface

Cadastro ampliado; nav «Segurança»; login informa gov.br só se habilitado.

## 7. Testes

`php vendor/bin/phpunit` — **125 testes, 731 asserções, OK**.

## 8. Pendências

- Recuperação de senha por e-mail no standalone.
- Verificação de e-mail com token.
- Homologação gov.br (credenciais + JWKS + troca de code).
- Âncoras ICP-Brasil + OCSP/CRL.
- MFA/WebAuthn (arquitetura futura, não fictícia).

## 9. Riscos residuais

Sem MFA; IDs sequenciais; auditoria sem HSM; gov.br/ICP incompletos até infraestrutura oficial.

## 10. Produção

- HTTPS (`VE_PUBLIC_BASE=https://…`) para cookie Secure.
- `VE_ADMIN_PASS` forte (≥12, sem substring do login).
- `VE_CSRF_SECRET` dedicado.
- Não definir `VE_HTTP_TEST_MODE` nem `VE_GOVBR_ENABLED` sem credenciamento.
- Proteger `VE_DATA` (permissões, disco cifrado, backups isolados).

## 11. Evolução

1. Completar callback gov.br com JWKS.  
2. Carregar âncoras ITI.  
3. Passkeys para operadores.  
4. Exportação/exclusão LGPD do titular.
