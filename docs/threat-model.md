# Modelo de ameaças (inicial)

## Ativos

- Token GPT Action
- Chave privada do Mac Bridge
- Token OpenClaw Gateway
- Dados de sessões/canais
- Capacidade de enviar mensagens (fase futura)

## Ameaças e mitigações

| ID | Ameaça | Mitigação fase 1 |
|---|---|---|
| T1 | Exposição do OpenClaw na internet | Loopback only; Bridge outbound |
| T2 | Roubo do token GPT | Hash no servidor; rate limit; rotação |
| T3 | Impersonação do Mac | Ed25519 por dispositivo |
| T4 | Replay de comando | Nonce + TTL + assinatura |
| T5 | Confirmação forjada (fase 2+) | N/A na fase 1 |
| T6 | Prompt injection → ação | Sem mutação; policy no ask-readonly |
| T7 | Shell / tool arbitrário | Allowlist tipada; sem proxy genérico |
| T8 | Vazamento em logs | Redação; sem mensagem integral |
| T9 | SSRF via adapter | Só base URL loopback configurada |
| T10 | Elevação para nível 3 | Desabilitado |

## Fora de escopo nesta fase

- Ameaças físicas ao Mac
- Comprometimento da conta ChatGPT
- Side-channels de timing avançados
