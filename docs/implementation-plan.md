# Plano de implementação — Fase 1

## Objetivo

Entregar monorepo funcional somente-leitura conforme especificação RelataSoft OpenClaw Control.

## Etapas

1. Scaffold pnpm + TypeScript strict
2. `packages/contracts` — envelopes, respostas, auditoria, device wire
3. `packages/crypto` — HMAC, Ed25519, nonces, hashes
4. `packages/logging` — pino + redação
5. `apps/openclaw-adapter` — client tipado, mock, HTTP stub, policy, sanitizers
6. `apps/action-gateway` — auth, WS hub, rotas fase 1, audit, idempotency
7. `apps/mac-bridge` — reconnect, validate, execute, keychain hook
8. `custom-gpt/` — OpenAPI 3.1, instructions, privacy
9. Deploy artifacts — nginx, systemd, launchd
10. Testes unitários, integração, segurança
11. Docs — architecture, threat model, tool policy, deploy

## Fora de escopo

Mensagens, shell, filesystem, nível 3, OAuth, proxy genérico, OpenAI API key.
