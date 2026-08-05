# Evolução criptográfica — lab SSS → VSS (reimplementação)

**Status:** Fase 1 (Feldman VSS) em curso nesta lab; Fase 2 (partial decrypt + Chaum–Pedersen) a seguir  
**Plugin:** RelataSoft Secure Election Suite  
**Branch:** `cursor/vss-feldman-threshold-03b3` (base `cursor/votador-wp-sss-1.0.27-2eb1`)  
**Versão alvo Fase 1:** `1.0.28.0`

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

## Fase 1 (atual)

- `FeldmanVss`, `Polynomial`, `CeremonyTranscript`, `ShareVerifyService`, `CryptoSchemeRegistry`
- Key Authority gera commitments + transcript; ZIP do oficial inclui ficheiros públicos da cerimônia
- Verify offline; mismatch de commitments invalida a cerimônia
- Apuração ainda reconstrói `x` em memória (transitional)

## Fase 2 (seguinte)

- Partial decryption + provas Chaum–Pedersen
- Combinação sem reconstruir `x`
- Scheme `modp-elgamal-threshold-cp-v1`

## Testes

```bash
php tests/feldman-vss-acceptance.php
php tests/crypto-acceptance.php
```
