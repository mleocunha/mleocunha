# Implantação E3 — um cliente, três sítios

> **Standalone HTTP (caminho atual):** [`../piloto-3-nos.md`](../piloto-3-nos.md), [`../ativar-standalone.md`](../ativar-standalone.md), [`../operacao-standalone.md`](../operacao-standalone.md).  
> O modelo E3 abaixo permanece válido; a ativação já **não** passa por CMS.

Modelo essencial RelataSoft: **1 cliente = 3 sítios**, preferencialmente em **nuvens distintas**.

**Lab / demonstração:** três processos no mesmo anfitrião são corretos para ensaio.  
**Produção:** servidores independentes e segregados; administradores de sistemas distintos
(preferencialmente que nem se conheçam); contratações e, preferencialmente, gestores de
contrato independentes; todos respondendo à autoridade eleitoral superior,
preferencialmente colegiada.

## Sítios

1. **Autoridade de chaves** — gera ElGamal + parcelas Shamir
2. **Plataforma de votação** — urnas criptografadas e cadastro
3. **Apuração / certificação** — importa material, reconstrói segredo, certifica

## Isolamento

- **Sem sincronização automática** de usuários, opções ou mídia entre sítios
- Contas de **Gestor pelo Cliente** podem compartilhar o mesmo login/senha *inicial* por conveniência operacional, mas são identidades **isoladas** em cada base de dados
- Transporte de material (chaves públicas, exportações de votos, parcelas) é **manual e auditável**
- Em produção, o isolamento é também **organizacional** (infra, pessoas e contratos), não só de processo PHP

```mermaid
flowchart TB
  subgraph C1[Nuvem A]
    KA[Sítio Chaves]
  end
  subgraph C2[Nuvem B]
    VT[Sítio Votação]
  end
  subgraph C3[Nuvem C]
    TL[Sítio Apuração]
  end
  KA -->|pacote chave pública| VT
  VT -->|exportação criptografada| TL
  KA -->|parcelas offline| TL
```

Nunca compartilhar a chave privada completa entre sítios de votação.

## Piloto sem host legado (A6)

O Adapter #2 (`Standalone`) reproduz esta topologia com três processos/pastas e um **courier** de arquivos — ver `docs/piloto-adapter2-3-nos.md` e `php bin/ve-node pilot`.
