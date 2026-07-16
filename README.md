# RelataSoft OpenClaw Control

Solução privada para um GPT personalizado (ChatGPT Plus) consultar um OpenClaw local em um MacBook, sem expor o Gateway OpenClaw à internet.

## Princípios

- Qwen permanece o modelo principal do OpenClaw.
- ChatGPT Plus é apenas a interface via GPT Actions.
- O Mac abre WebSocket de saída; não há porta pública no Mac.
- Token do OpenClaw Gateway nunca sai do Mac.
- Fase 1: **somente leitura** (status, canais, sessões, ask-readonly).
- Sem shell, sem filesystem, sem envio de mensagens.

## Arquitetura

```text
GPT privado → HTTPS → Action Gateway (Ubuntu/Nginx)
                         ↕ WebSocket autenticado
                      Mac Bridge (LaunchAgent)
                         → loopback → OpenClaw :18789 → Qwen
```

## Monorepo

| Pacote / app | Função |
|---|---|
| `packages/contracts` | Schemas Zod e tipos compartilhados |
| `packages/crypto` | Assinaturas HMAC, Ed25519, nonces, hashes |
| `packages/logging` | Pino com redação de segredos |
| `apps/action-gateway` | Fastify API + WebSocket do dispositivo |
| `apps/mac-bridge` | Daemon macOS / cliente WS |
| `apps/openclaw-adapter` | Cliente tipado + mock OpenClaw |
| `custom-gpt/` | Instructions, OpenAPI, privacy |

## Pré-requisitos

- Node.js 22+
- pnpm 10+

## Configuração local

```bash
cp .env.example .env
pnpm install
```

Gere credenciais de desenvolvimento:

```bash
node --input-type=module <<'EOF'
import { createHash, generateKeyPairSync, randomBytes } from "node:crypto";
const token = randomBytes(32).toString("hex");
const hash = createHash("sha256").update(token).digest("hex");
const signing = randomBytes(32).toString("base64");
const { publicKey, privateKey } = generateKeyPairSync("ed25519");
console.log("GPT_ACTION_TOKEN=" + token);
console.log("GPT_ACTION_TOKEN_HASH=" + hash);
console.log("COMMAND_SIGNING_SECRET=" + signing);
console.log("DEVICE_PUBLIC_KEY=" + publicKey.export({ type: "spki", format: "der" }).toString("base64"));
console.log("DEVICE_PRIVATE_KEY=" + privateKey.export({ type: "pkcs8", format: "der" }).toString("base64"));
EOF
```

Preencha `.env` com os valores gerados (o token bruto vai só no GPT Action; no servidor use o hash).

## Executar

Terminal 1 — Action Gateway:

```bash
set -a && source .env && set +a
pnpm --filter @relatasoft/action-gateway dev
```

Terminal 2 — Mac Bridge (mock OpenClaw):

```bash
set -a && source .env && set +a
export BRIDGE_DEVICE_ID="${DEVICE_ID:-macbook-mauro}"
export GATEWAY_WS_URL="ws://127.0.0.1:8788/v1/device/connect"
export OPENCLAW_MODE=mock
pnpm --filter @relatasoft/mac-bridge dev
```

Consultas:

```bash
curl -s http://127.0.0.1:8788/health
curl -s -H "Authorization: Bearer $GPT_ACTION_TOKEN" http://127.0.0.1:8788/v1/openclaw/status
```

## Scripts

```bash
pnpm lint
pnpm typecheck
pnpm test
pnpm build
```

## Documentação

- [Arquitetura](docs/architecture.md)
- [Modelo de segurança](docs/security-model.md)
- [Modelo de ameaças](docs/threat-model.md)
- [Deploy Ubuntu](docs/deployment-ubuntu.md)
- [Deploy macOS](docs/deployment-macos.md)
- [Setup Custom GPT](docs/custom-gpt-setup.md)
- [Política de ferramentas](docs/tool-policy.md)

## Deploy

Artefatos em `deployment/`:

- Nginx: `deployment/nginx/openclaw-api.relatasoft.com.br.conf`
- systemd: `deployment/systemd/relatasoft-openclaw-gateway.service`
- LaunchAgent: `deployment/launchd/com.relatasoft.openclaw-bridge.plist`

## Fase atual

**Fase 1 — somente leitura.** Envio de mensagens, workflows e operações nível 3 estão fora de escopo.
