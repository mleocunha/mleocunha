# Cadastro Eleitoral (`.rsv`)

Formato de importação/exportação do cadastro unificado no sítio de **votação**.

## O que é

Arquivo de texto com colunas separadas por `:` e linhas por `;` (ver `RsvFormat`). Papéis em português:

| Papel no `.rsv` | Função no sítio |
|-----------------|-----------------|
| `eleitor` | Eleitor |
| `auditor` | Auditor |
| `autoridade` | Autoridade Eleitoral |
| `administrador` | Administrador Eleitoral |
| `gestor` | Gestor pelo Cliente |

## Fluxo

1. Abrir **Cadastro Eleitoral** no Painel (modo votação para import/export).
2. Importar `.rsv` (job assíncrono) ou exportar por papel.
3. Cada exportação gera **um papel por arquivo** — não misturar papéis no mesmo download.
4. Não há sincronização automática entre os 3 sítios: o courier de material crypto é independente do cadastro.

## Isolamento E3

O cadastro vive só no sítio onde foi importado. Replicar eleitores para outro sítio exige novo `.rsv` manual — ver [Implantação E3](implantacao-3wp.md).
