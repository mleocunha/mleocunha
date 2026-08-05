# Evolução criptográfica — lab SSS → VSS (reimplementação)

**Status:** Fase 1 (`1.0.28.0`) + Fase 2 (`1.0.29.0`) landed nesta lab  
**Plugin:** RelataSoft Secure Election Suite  
**Branch:** `cursor/vss-feldman-threshold-03b3` (base `cursor/votador-wp-sss-1.0.27-2eb1`)

## Decisões fechadas

| Item | Decisão |
|------|---------|
| Abordagem | Reimplementar VSS nesta lab (não portar em bloco a linha 1.0.30) |
| Fases | Fase 1 + Fase 2 nesta entrega |
| Legado SSS | Corte limpo — artefactos Shamir ficam na versão anterior do plugin |
| Campo Shamir | `field = q` (ordem do subgrupo ElGamal) |
| Dealer | Trusted dealer (Key Authority) |
| Cerimônia ZIP | `ceremony-manifest.json`, `commitments.json`, `ceremony-public-key.json`, `participants.json`, instruções de verify |
| Submit | Fail-closed se a fração não verificar |
| UI pt_BR | Manter “fração/frações”; mencionar Feldman/VSS onde couber |
| Votador | Entra nesta entrega (demo ponta a ponta) |
| Melhorias 1.0.27.8–.19 | Reaplicar **depois** do núcleo VSS |

## Schemes

```text
modp-elgamal-shamir-v1          RETIRED (não gera / não verifica aqui)
modp-elgamal-feldman-v1         ACTIVE generation (Fase 1)
modp-elgamal-threshold-cp-v1    TARGET modular (Fase 2)
ec-elgamal-p521-threshold-cp-v1 planned
ec-elgamal-p521-pedersen-dkg-v1 planned
```

## Fase 1 (`1.0.28.0`)

- `FeldmanVss`, `Polynomial`, `CeremonyTranscript`, `ShareVerifyService`, `CryptoSchemeRegistry`
- Key Authority gera commitments + transcript; ZIP do oficial inclui ficheiros públicos da cerimônia
- Verify offline; mismatch de commitments invalida a cerimônia

## Fase 2 (`1.0.29.0`)

- Active generation: `modp-elgamal-threshold-cp-v1` (ainda usa Feldman VSS para repartir)
- Submit: partilha usada de forma efémera → contribuição com partials + provas Chaum–Pedersen (a partilha **não** é persistida)
- Decrypt: combina partials com Lagrange; `private_key_reconstruction = prohibited`
- Classes: `ChaumPedersen`, `ThresholdPartialDecrypt`

## Testes

```bash
php tests/feldman-vss-acceptance.php
php tests/threshold-cp-acceptance.php
php tests/crypto-acceptance.php
```
