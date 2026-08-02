# Scheme specification: `modp-elgamal-feldman-v1` (Fase 1 — in progress)

**Status:** mathematics + verify API landed; ceremony wiring / UI / ZIP transcript **not** enabled for generation yet  
**Implementation:** PHP + GMP  
**Registry:** `CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_FELDMAN_V1` → `modp-elgamal-feldman-v1`  
**Class:** `includes/Crypto/FeldmanVss.php`

## Decision vs baseline field prime

Baseline `modp-elgamal-shamir-v1` uses a **separate** Shamir prime \(P > x\).

Feldman VSS verification

\[
g^{s_i} \equiv \prod_{k=0}^{t-1} C_k^{\,i^k} \pmod p,
\qquad C_k = g^{a_k} \pmod p
\]

requires polynomial arithmetic in \(\mathbb{Z}/q\mathbb{Z}\) where \(q\) is the ElGamal subgroup order. Therefore **Fase 1 uses field = \(q\)** (clean break from baseline). This resolves backlog item B4 for the Feldman scheme.

Also \(C_0 = g^{a_0} = g^x = y\) (ElGamal public key).

## API (landed)

- `FeldmanVss::rses_split_with_commitments(x, t, n, p, q, g)`
- `FeldmanVss::rses_verify_share(i, s, commitments, p, q, g, y?)`
- Decimal helpers for transcript JSON

## Still to land (Fase 1 remainder)

- Public ceremony transcript ZIP (`commitments.json`, manifest, signature)
- Official package with encrypted share + transcript copy
- Offline “Verificar meu share” UI (fail-closed → `CEREMONY_INVALID`)
- Wire Key Authority generation to this scheme (`rses_may_generate` still false)
- Adversarial acceptance suite on full packages
- Freeze `scheme_id` / `format_version` on every artefact

## Tests

`php tests/feldman-vss-acceptance.php`
