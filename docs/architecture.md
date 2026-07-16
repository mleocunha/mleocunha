# Arquitetura — RelataSoft OpenClaw Control

## Decisões arquiteturais (fase 1)

1. **Monorepo pnpm + TypeScript strict** — contratos compartilhados evitam drift entre Gateway e Bridge.
2. **Action Gateway em Fastify** — HTTP para GPT Actions + WebSocket para o Mac.
3. **Mac inicia a conexão** — sem porta de entrada no Mac; reconexão com backoff exponencial.
4. **Credenciais separadas** — bearer GPT (hash no servidor) ≠ Ed25519 do dispositivo ≠ token OpenClaw (só no Mac).
5. **Comandos envelopados e assinados (HMAC-SHA256)** — TTL, nonce anti-replay, idempotency key.
6. **Adapter OpenClaw tipado** — sem proxy genérico; mock para testes; HTTP loopback opcional.
7. **Auditoria append-only em memória/arquivo** — SQLite/Prisma pode substituir o store na fase seguinte sem mudar o contrato.
8. **Qwen permanece o modelo principal** — o adapter reporta `activeModel: qwen`; sem dependência de OpenAI API.

## Fluxo de uma consulta

1. GPT Action chama `GET /v1/openclaw/status` com bearer.
2. Gateway autentica, audita, assina `GET_STATUS`, envia ao Bridge.
3. Bridge valida schema, expiração, assinatura e nonce.
4. Adapter consulta OpenClaw (mock ou HTTP local).
5. Resultado sanitizado retorna ao GPT com `auditId`.

## Limites da fase 1

- Sem prepare/confirm/send.
- Sem nível de risco 3.
- Sem OAuth.
- Sem execução de shell ou filesystem.
