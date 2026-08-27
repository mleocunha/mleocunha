# Implantação E3 — um cliente, três WordPress

Modelo essencial RelataSoft: **1 cliente = 3 instalações WordPress**, preferencialmente em **nuvens distintas**.

## Sites

1. **Autoridade de chaves** — gera ElGamal + parcelas Shamir
2. **Plataforma de votação** — urnas criptografadas e cadastro
3. **Apuração / certificação** — importa material, reconstrói segredo, certifica

## Isolamento

- **Sem sincronização automática** de usuários, opções ou mídia entre sites
- Contas de **Gestor pelo Cliente** podem compartilhar o mesmo login/senha *inicial* por conveniência operacional, mas são identidades **isoladas** em cada base de dados
- Transporte de material (chaves públicas, exportações de votos, parcelas) é **manual e auditável**

```mermaid
flowchart TB
  subgraph C1[Nuvem A]
    KA[WP Chaves]
  end
  subgraph C2[Nuvem B]
    VT[WP Votação]
  end
  subgraph C3[Nuvem C]
    TL[WP Apuração]
  end
  KA -->|pacote chave pública| VT
  VT -->|exportação criptografada| TL
  KA -->|parcelas offline| TL
```

Nunca compartilhar a chave privada completa entre sites de votação.
