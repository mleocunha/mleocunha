# Deploy — macOS

## LaunchAgent

Instalar `deployment/launchd/com.relatasoft.openclaw-bridge.plist` em:

```text
~/Library/LaunchAgents/com.relatasoft.openclaw-bridge.plist
```

```bash
launchctl load ~/Library/LaunchAgents/com.relatasoft.openclaw-bridge.plist
```

## Keychain

Armazenar a chave privada Ed25519 no Keychain (serviço `com.relatasoft.openclaw-bridge`). Não gravar em arquivos de configuração.

Opcionalmente instalar `keytar` no ambiente Node do bridge.

## Requisitos

- `RunAtLoad=true`, `KeepAlive=true`
- Sem `sudo`, sem daemon global
- Sem porta de escuta
- Logs em `~/Library/Logs/relatasoft-openclaw/`

## Desinstalação

```bash
launchctl unload ~/Library/LaunchAgents/com.relatasoft.openclaw-bridge.plist
rm ~/Library/LaunchAgents/com.relatasoft.openclaw-bridge.plist
```
