# Plano técnico — IAM / cadastro / gov.br / ICP-Brasil

## Arquivos a criar

| Arquivo | Função |
|---------|--------|
| `src/Domain/Identity/AccountStatus.php` | Máquina de estados da conta |
| `src/Domain/Identity/PasswordPolicy.php` | Política NIST-orientada + Argon2id |
| `src/Domain/Identity/SecurityEventTypes.php` | Catálogo de eventos de segurança |
| `src/Domain/Identity/DataClassification.php` | Classificação LGPD/sensibilidade dos campos |
| `src/Infrastructure/Identity/Security/LoginRateLimiter.php` | Rate limit login (arquivo/JSON) |
| `src/Infrastructure/Identity/Security/CsrfTokenService.php` | CSRF HMAC-bound à sessão |
| `src/Infrastructure/Identity/Security/StandaloneSecurityAudit.php` | Auditoria append-only de identidade |
| `src/Infrastructure/Identity/Federation/FederatedIdentityStore.php` | Vínculos OIDC (gov.br) |
| `src/Infrastructure/Identity/Federation/GovBrOidcClient.php` | Authorization Code + PKCE (config-driven) |
| `src/Infrastructure/Identity/Certificates/X509CertificateInspector.php` | Parse/validação estrutural X.509 |
| `docs/iam/*` | Diagnóstico, ameaças, classificação, gov.br, checklist |
| `tests/Unit/Identity/*` | Testes de política, estados, CSRF, rate limit, OIDC helpers, X.509 |

## Arquivos a modificar

| Arquivo | Alteração |
|---------|-----------|
| `UserDirectory.php` + stores | `status`, `createdAt`, `updatedAt`; `addRole` já existe; DTO sem hash na UI |
| `CookieSessionPort.php` | revokeAllForUser, touch idle, cookie Secure |
| `HttpKernel.php` | CSRF, rate limit, status gate, cadastro manual, `/painel/seguranca`, gov.br desabilitado |
| `InMemoryUserStore.php` | Paridade com FileJson |
| `SECURITY.md` | Controles IAM |
| Testes Standalone HTTP | Novos fluxos |

## Alterações de “banco” (JSON documental)

Campos novos em `users.rows[]` (defaults retrocompatíveis):
- `status` = `active`
- `uuid` = UUIDv4
- `createdAt` / `updatedAt` ISO-8601
- `emailVerifiedAt` opcional (null)
- Blob `security_events` (auditoria)
- Blob `federated_identities`
- Blob `login_throttle`

Sem exclusão destrutiva de usuários existentes.

## gov.br / ICP (limites)

- Endpoints oficiais confirmados (staging): `https://sso.staging.acesso.gov.br/authorize` e `/token` (roteiro 30/10/2025).
- Produção e escopos finais vêm de credenciamento — **feature flag** `VE_GOVBR_ENABLED=1` + `VE_GOVBR_CLIENT_ID` obrigatórios.
- UI não oferece login gov.br como funcional sem flag.
- ICP-Brasil: inspeção OpenSSL local; confiança de cadeia exige âncoras oficiais (pendência operacional).

## Estratégia de testes

PHPUnit: política de senha, status transitions, CSRF, rate limit, sessão revoke, cadastro, mass-assignment de role, OIDC state/PKCE, X.509 malformado.
