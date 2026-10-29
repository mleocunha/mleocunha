# Modelo de ameaças (STRIDE) — módulo IAM

| Ativo | Limite de confiança |
|-------|---------------------|
| Contas e papéis E3 | Processo do nó + `VE_DATA` |
| Sessões `ve_session` | Cookie HttpOnly; TLS em produção |
| Hash de senha | Argon2id / password_hash |
| Parcelas SSS / chave privada | Fora do cadastro; nunca no perfil |
| Vínculo gov.br | Credenciamento oficial + JWKS |

| Ameaça | Agente | Controlo implementado | Residual |
|-------|--------|----------------------|----------|
| Spoofing de login | Externo | Rate limit; CSRF; hash; status=active | Sem MFA |
| Tampering de papel | Autenticado | Mass-assignment bloqueado no cadastro; authz admin | UI WP legada |
| Repudiation | Insider | `security_events` append-only | Sem assinatura HSM da trilha |
| Information disclosure | Logs/UI | `publicView` sem hash; audit sanitiza tokens | IDs sequenciais |
| Denial of service | Automação | Backoff temporário no login | Sem WAF |
| Elevation of privilege | Autenticado | Admin≠eleitor; roles por modo | gov.br attrs não elevam |
| Account takeover federado | OIDC | Sem link por e-mail/CPF; state/PKCE | Troca token pendente de homologação |
| Certificado falso ICP | Upload | OpenSSL parse; nunca “válido ICP” sem âncoras | Âncoras ITI + OCSP pendentes |
