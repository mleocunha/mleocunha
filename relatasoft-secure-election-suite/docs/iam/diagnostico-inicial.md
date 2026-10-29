# Diagnóstico técnico — Cadastro e IAM

**Data:** 2026-10-29 (America/Sao_Paulo)  
**Base de código:** `cursor/parcela-import-gui-03b3` → branch `cursor/iam-cadastro-govbr-03b3`  
**Escopo OWASP ASVS:** v4.0.3 nível L2 (alvo proporcional a dados pessoais + material criptográfico eleitoral)

## 1. Stack encontrada

| Camada | Tecnologia |
|--------|------------|
| Linguagem | PHP ≥ 8.2 |
| Empacotamento | Plugin WordPress + runtime **standalone HTTP** (`bin/ve-http`, `HttpKernel`) |
| Autoload | Composer PSR-4 `RelataSoft\SecureElectionSuite\Painel\` → `src/` |
| Testes | PHPUnit 11 (`composer.json` require-dev) |
| Frontend | HTML server-rendered no standalone; WP admin pages + shortcodes no modo WP |
| Persistência standalone | JSON documental por nó (`JsonDocumentStore`, `identity.json`) |
| Persistência WP | Tabelas WP + repositórios em `Adapters/WordPress/Persistence` |
| Criptografia eleitoral | ElGamal + Shamir SSS (já existente; fora do escopo de senhas) |

Arquitetura: **modular hexagonal** (Contracts / Domain / Application / Adapters), **não** microsserviços. Isolamento E3: um processo / um modo / um `VE_DATA`.

## 2. Cadastro existente

Campos de usuário (UserRow): `id`, `login`, `email`, `displayName`, `firstName`, `lastName`, `roles[]`, `passwordHash`.

Fluxos:
- **RSV** (`.rsv`) — importação em massa no modo votação (`RsvImporter` / `/painel/cadastro`).
- **Autoridades** — criação manual / pacote JSON (`/painel/autoridades`).
- **WP** — `UserRegistryService` + `UsersRegistryPage` com papéis por modo e paginação.
- Bootstrap admin: `VE_ADMIN_LOGIN` / `VE_ADMIN_PASS`.

Não coletados por padrão (e **não** serão coletados sem finalidade): CPF, endereço, telefone, data de nascimento, avatar arbitrário.

## 3. Autenticação e autorização

| Mecanismo | Estado |
|-----------|--------|
| Login local | POST `/login` + verificação de hash |
| Sessão | Cookie `ve_session` HttpOnly + SameSite=Lax; token 48 hex; TTL 24h fixo |
| Papéis | RBAC: `administrator`, `ve_gestor`, `editor`, `ve_auditor`, `subscriber` |
| Voto | Exige `ROLE_VOTER` (admin não é presumido eleitor) |
| MFA / passkeys | Ausentes |
| Recuperação de senha | Só via WP shortcode; **ausente no standalone** |
| JWT | Não usado (adequado — sessão própria) |
| CSRF standalone | **Ausente** nos formulários POST |
| Rate limit login | **Ausente** |
| gov.br / ICP-Brasil | **Ausentes** |

Hash de senha: `password_hash(..., PASSWORD_DEFAULT)` com **fallback perigoso** `plain:` se `password_hash` falhar; `passwordMatches` ainda aceita texto puro / igualdade direta.

## 4. Auditoria

WP: `AuditLogger` hash-chained, bloqueia chaves sensíveis (`share_value`, `private_x`, …).  
Standalone HTTP: **sem** trilha de eventos de autenticação/cadastro equivalente.

## 5. Riscos priorizados (Top 15)

1. **P1** Fallback `plain:` e comparação de senha em texto puro — comprometimento total se hash falhar.  
2. **P1** Sem rate limit / backoff no login — stuffing / spraying.  
3. **P1** Sem CSRF no standalone — ações de estado via cookie.  
4. **P1** Sem ciclo de vida de conta (suspender/bloquear) nem revogação de sessões após eventos críticos.  
5. **P2** Sessão sem renovação pós-login / sem idle timeout separado / Secure cookie só se HTTPS.  
6. **P2** Cadastro standalone sem criação controlada de eleitor (só RSV) e listagem limitada a 25.  
7. **P2** Sem alteração/recuperação de senha no standalone.  
8. **P2** Eventos de segurança de identidade não auditados no standalone.  
9. **P2** `passwordHash` pode vazar se rows forem serializados em respostas.  
10. **P3** Sem verificação de e-mail / ativação pendente.  
11. **P3** Sem MFA (e não deve ser “fictício”).  
12. **P3** gov.br exige credenciamento oficial — não habilitável sem `client_id`.  
13. **P3** ICP-Brasil: falta âncora de confiança oficial e OCSP/CRL.  
14. **P3** IDs sequenciais nas URLs de autoridades (enumeração) — risco moderado em nó autenticado.  
15. **P3** LGPD: base legal / retenção ainda documentais; exportação/exclusão de titular incompletas.

## 6. Compatibilidades necessárias

- Preservar `UserDirectory` / RSV / autoridades / E3.  
- Não migrar para JWT.  
- Não coletar CPF por padrão.  
- Não inventar endpoints gov.br; usar roteiro oficial (`acesso.gov.br/roteiro-tecnico`, staging `sso.staging.acesso.gov.br`).  
- Não simular MFA ou homologação gov.br.  
- Migrations JSON: campos novos opcionais com default seguro (`status=active`).

## 7. Prioridades de implementação (este ciclo)

1. Política de senhas + Argon2id (quando disponível) + remoção do fallback `plain:` em novos hashes.  
2. Estados de conta + invalidação de sessões.  
3. CSRF + rate limit de login.  
4. Hardening de sessão (Secure condicional, revoke-all, rotação).  
5. Auditoria de segurança de identidade no standalone.  
6. UI: cadastro manual controlado, central de segurança (senha + sessões).  
7. Scaffold gov.br (OIDC+PKCE) **desabilitado** sem config.  
8. Serviço ICP-Brasil de parse/validação estrutural X.509 (sem declarar confiança sem âncoras).  
9. Documentação de classificação de dados + ameaças + checklist.  
10. Testes unitários/integração/segurança automatizados.
