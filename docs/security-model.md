# Modelo de segurança

## Controles

| Controle | Implementação |
|---|---|
| TLS | Nginx termina TLS; HSTS |
| Auth GPT | Bearer 256-bit; store = SHA-256 |
| Auth Mac | Ed25519 challenge-response |
| Replay | Nonce cache no Bridge |
| Integridade | HMAC do envelope de comando |
| Idempotência | Header `Idempotency-Key` + hash de payload |
| Rate limit | `@fastify/rate-limit` |
| Schema | Zod `.strict()` em todas as entradas |
| Segredos em log | Redação pino + sanitizers |
| Menor privilégio | Allowlist de tipos de comando fase 1 |
| OpenClaw token | Nunca enviado ao Ubuntu |

## Política de confirmação

- Nível 0 (leitura): sem confirmação adicional — fase 1.
- Níveis 1–2: preparação + confirmação — fases 2–3.
- Nível 3: desabilitado.

## Prompt injection

Conteúdo de OpenClaw/WhatsApp/e-mail/arquivos é dado não confiável. O GPT e o backend nunca interpretam esse texto como autorização operacional. `ask-readonly` rejeita prompts com indícios de mutação/envio.
