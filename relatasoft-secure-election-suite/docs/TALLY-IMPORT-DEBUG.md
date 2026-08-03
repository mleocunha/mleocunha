# Debug: tela branca em Importação da apuração

## Enviar o ZIP para o agente (chat não aceita anexo grande)

1. **URL temporária (preferido)**  
   Suba o ZIP para um lugar baixável por HTTPS (Drive “qualquer um com o link”, WeTransfer, S3 pré-assinado, pasta do próprio site) e cole a URL no chat.

2. **Só metadados (se não puder enviar o arquivo)** — no Mac:
   ```bash
   unzip -l /caminho/election-export-*.zip
   ls -lh /caminho/election-export-*.zip
   unzip -p /caminho/election-export-*.zip manifest.json
   unzip -p /caminho/election-export-*.zip checksums.json
   ```
   Cole essa saída no chat (não precisa dos votos).

3. **Log PHP do host** — última linha de `Allowed memory size` / `Fatal error` no error_log do domínio de apuração.

4. **Confirme a versão no site de apuração**  
   Em **Importação da apuração** deve aparecer `plugin 1.0.27.4` (ou superior). Se ainda mostrar 1.0.27 / 1.0.27.3, o plugin dessa instalação não foi atualizado.

## Checklist rápido

- Atualizar o plugin **no site em modo Apuração** (não só no de votação).
- Preferir export **ZIP** gerado com plugin ≥ 1.0.27.2 no site de votação.
- Reabrir a página de importação uma vez: versões ≥ 1.0.27.4 limpam manifests enormes antigos no banco.
- Se a página abrir mas o Import falhar, anote a mensagem (não deve mais ser tela 100% branca).
