# Verificação B* — Produto no Adapter #1 (LEGADO)

> Ativação standalone atual: [`ativar-standalone.md`](ativar-standalone.md).  
> Este arquivo valida o fecho B* sobre o hospedeiro antigo.

Pacote paralelo ao caminho crítico (roadmap §3.2): Cadastro, Auditor, Conhecimento, áudio/beep, E1 cliente, PT-BR.

## Estado

| ID | Veredicto | Notas |
|----|-----------|-------|
| **B1** Cadastro + `.rsv` | PASS | UI unificada, `RsvFormat`, jobs RSV; doc `docs/conhecimento/cadastro-rsv.md` |
| **B2** Auditor + stats | PASS | `ve_auditor`; stats sem somar abstenções entre rodadas (média por rodada fechada) |
| **B3** Conhecimento + E3 | PASS | Catálogo + E3; Auditor/Autoridade/Gestor vêem E3; Cadastro `.rsv` |
| **B4** Áudio + beep | PASS | Áudio fim de turno + beep no export RSV (já existente) |
| **B5** `cliente_id` / `cliente_nome` | PASS | Settings + carimbo em `PublicKeyPackage` / export chave / manifest votação; chip no home |
| **B6** PT-BR / sítio / UX | PASS (contínuo) | Settings + Auditar certificação em PT; glossário sítio mantido |

## Corrigido neste fecho

- Export chave pública via `PublicKeyPackage` com `cliente_id`/`cliente_nome`
- Manifest/ZIP/JSON de votação carimbados
- Stats auditor: votantes só em rodadas abertas; abstenções = média das fechadas
- Conhecimento: `cadastro-rsv`; E3 para Auditor e Autoridade
- UI Settings / Certification Audit em PT-BR
- Testes: `ClienteStampPackageTest`

## Residual aceitável

- Nem todos os telas de `VotingViews` estão em PT (hardening contínuo B6)
- Export RSV continua um papel por job
- Beep só no export (não no import)

**Veredicto B*:** PASS

```bash
./vendor/bin/phpunit --filter 'ClienteStampPackageTest|RsvFormatTest|ThreeNodePilotTest'
```
