# Administrador Eleitoral

O Administrador Eleitoral gere o sítio WordPress no modo bloqueado (chaves, votação ou apuração).

## Responsabilidades

- Bloquear o **modo de operação** do sítio
- Gerir o **Cadastro Eleitoral** (contas e, em votação, import/export `.rsv`)
- Configurar eleições, shortcodes e exportações
- Consultar o **Registro de Auditoria** e a documentação em **Conhecimento**

## Fluxo típico (modo votação)

```mermaid
flowchart LR
  A[Importar chave pública] --> B[Criar eleição]
  B --> C[Cadastro Eleitoral]
  C --> D[Abrir votação]
  D --> E[Exportar material]
```

Mantenha credenciais fortes e contas de Gestor pelo Cliente isoladas por sítio.
