# gov.br + ICP-Brasil — estado da integração

Documentação oficial consultada (atualização 30/10/2025):
- https://acesso.gov.br/roteiro-tecnico
- https://acesso.gov.br/roteiro-tecnico/iniciarintegracao.html
- https://acesso.gov.br/roteiro-tecnico/escopoatributos.html
- https://acesso.gov.br/roteiro-tecnico/solicitacaocredencial.html

## Login Único gov.br

Fluxo: OAuth 2.0 Authorization Code + PKCE (S256) + OIDC (`openid`, `nonce` obrigatório).

Endpoints **staging** documentados:
- `https://sso.staging.acesso.gov.br/authorize`
- `https://sso.staging.acesso.gov.br/token`

Produção: **não inventada** — configurar via env após credenciamento.

### Variáveis

| Variável | Função |
|----------|--------|
| `VE_GOVBR_ENABLED` | `1` para habilitar |
| `VE_GOVBR_CLIENT_ID` | Obrigatório |
| `VE_GOVBR_CLIENT_SECRET` | Se o tipo de cliente exigir |
| `VE_GOVBR_REDIRECT_URI` | URI registada |
| `VE_GOVBR_ENVIRONMENT` | `staging` (default) / produção |
| `VE_GOVBR_ISSUER` / `_AUTHORIZATION_ENDPOINT` / `_TOKEN_ENDPOINT` / `_JWKS_URI` | Overrides oficiais |
| `VE_GOVBR_SCOPES` | Default alinhado ao roteiro |

### O que este repositório faz

- Gera `state`, `nonce`, `code_verifier`/`code_challenge`.
- Persiste correlação em `oidc_pending` (sem expor verifier ao browser).
- Valida claims estruturais do ID Token (iss/aud/exp/nonce/sub).
- Mantém sessão interna `ve_session` (tokens gov.br **não** autorizam APIs internas).
- UI **não** apresenta login gov.br como funcional sem flag+client_id.

### Pendente (homologação externa)

- Credenciamento e vídeo de comprovação.
- Troca HTTP real code→tokens com Basic auth.
- Verificação criptográfica JWKS.
- Mapeamento de níveis Bronze/Prata/Ouro só com atributos oficiais.

## ICP-Brasil

`X509CertificateInspector` faz parse OpenSSL e **recusa declarar validade ICP** sem âncoras oficiais ITI + revogação (CRL/OCSP).

Prova de posse **não** é upload de `.cer`. mTLS / desafio de assinatura ficam para fase com infraestrutura.

Assinatura eletrônica gov.br (serviço distinto do login) — **não implementada**; interface futura documentada como bloqueada por elegibilidade.
