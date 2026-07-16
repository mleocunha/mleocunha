# Política de ferramentas

## Permitidas (fase 1)

- `getStatus` / `GET_STATUS`
- `listChannels` / `LIST_CHANNELS`
- `listSessions` / `LIST_SESSIONS`
- `getSession` / `GET_SESSION`
- `askOpenClawReadonly` / `ASK_READONLY`

## Explicitamente proibidas

`exec`, `shell`, `bash`, `zsh`, `process`, `runCommand`, `invokeAnyTool`, `rawGatewayCall`, `arbitraryHttpRequest`, `readAnyFile`, `writeAnyFile`, `deleteAnyFile`, `installPlugin`, `changeGatewayConfig`.

O adapter **não** aceita `toolName` arbitrário.

## Separação de camadas

1. Transporte (WebSocket Bridge)
2. Adapter OpenClaw (operações tipadas)
3. Policy (allowlist + heurística readonly)
4. Sanitização de respostas
