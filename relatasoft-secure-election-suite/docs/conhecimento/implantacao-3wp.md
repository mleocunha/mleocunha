# Implantação E3 — um cliente, três sítios

Modelo essencial RelataSoft: **1 cliente = 3 sítios**, preferencialmente em **nuvens distintas**.

## Sítios

1. **Autoridade de chaves** — gera ElGamal + parcelas Shamir
2. **Plataforma de votação** — urnas criptografadas e cadastro
3. **Apuração / certificação** — importa material, reconstrói segredo, certifica

## Isolamento

- **Sem sincronização automática** de usuários, opções ou mídia entre sítios
- Contas de **Gestor pelo Cliente** podem compartilhar o mesmo login/senha *inicial* por conveniência operacional, mas são identidades **isoladas** em cada base de dados
- Transporte de material (chaves públicas, exportações de votos, parcelas) é **manual e auditável**

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
