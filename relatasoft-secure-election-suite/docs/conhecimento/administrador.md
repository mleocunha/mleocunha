# Administrador Eleitoral

O Administrador Eleitoral gerencia o sítio no modo bloqueado (chaves, votação ou apuração).

## Responsabilidades

- Bloquear o **modo de operação** do sítio
- Gerenciar o **Cadastro Eleitoral** (contas e, em votação, import/export `.rsv`)
- Configurar eleições, rotas da jornada (`/voto`) e exportações
- Shortcodes de jornada são adaptadores opcionais; o itinerário nativo não exige páginas do sítio
- Consultar o **Registro de Auditoria** e a documentação em **Conhecimento**

## Fluxo típico (modo votação)

```mermaid
flowchart LR
  A[Importar chave pública] --> B[Criar eleição]
  B --> C[Cadastro Eleitoral]
  C --> D[Abrir votação]
  D --> E[Exportar material]
```

Manter credenciais fortes e contas de Gestor pelo Cliente isoladas por sítio.
