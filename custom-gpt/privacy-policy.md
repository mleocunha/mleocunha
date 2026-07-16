# Política de Privacidade — RelataSoft OpenClaw Control

**Última atualização:** 2026-07-16  
**Controlador:** RelataSoft (`relatasoft.com.br`)

## Finalidade

Este serviço existe exclusivamente para permitir que um GPT personalizado privado (ChatGPT Plus) consulte e, em fases futuras, comande um OpenClaw executado localmente no Mac do titular, por meio de um Action Gateway intermediário.

## Dados enviados pelo GPT ao servidor

- Texto de prompts de consulta somente leitura.
- Identificadores de sessão OpenClaw, quando fornecidos.
- Metadados de requisição (horário, IP de origem da Action, user-agent).
- Cabeçalhos de autenticação (o token bearer é validado por hash e **não** é armazenado em texto puro).

## Dados encaminhados ao Mac

- Envelopes de comando tipados e assinados (status, canais, sessões, ask-readonly).
- Não são encaminhados tokens da API pública do GPT Action sob a forma reutilizável no Mac.

## Dados armazenados

- Eventos de auditoria (identificador, operação, resultado, hash de payload, IP, user-agent, latência).
- Registros de idempotência (chave, hash do payload, resposta).
- Chave pública do dispositivo Mac Bridge e hash do token GPT Action.

**Não armazenamos:**

- Token do OpenClaw Gateway.
- Conteúdo integral de mensagens pessoais por padrão.
- Agenda completa de contatos no servidor público.
- Credenciais do Mac Bridge em texto puro.

## Retenção

- Auditoria operacional: retenção configurável (padrão sugerido 90 dias), append-only.
- Idempotência: 24 horas.
- Desafios de autenticação de dispositivo: minutos.

## Mascaramento

Números de telefone, tokens e caminhos sensíveis são mascarados ou removidos antes de respostas ao GPT e nos logs estruturados.

## Credenciais

- Token GPT Action: segredo no GPT; apenas hash SHA-256 no servidor.
- Chave privada do Mac Bridge: macOS Keychain no Mac.
- Token OpenClaw Gateway: permanece exclusivamente no Mac.

## Compartilhamento e venda

Não vendemos dados. Não compartilhamos dados com terceiros para marketing. O processamento limita-se à operação do controle privado.

## Exclusão

O titular pode solicitar exclusão dos registros de auditoria e revogação imediata do token GPT Action e da chave do dispositivo.

## Contato

RelataSoft — `relatasoft.com.br`
