# Scheme specification: `modp-elgamal-feldman-v1` (Fase 1)

**Status:** generation + ceremony wiring enabled (plugin ≥ 1.0.30)  
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

## API

- `FeldmanVss::rses_split_with_commitments(x, t, n, p, q, g)`
- `FeldmanVss::rses_verify_share(i, s, commitments, p, q, g, y?)`
- `FeldmanVss::rses_build_share_payload(...)` / `validateSharePayload(...)`
- `CeremonyTranscript::rses_build` / `rses_public_files` (ZIP transcript)
- `ShareVerifyService::rses_verify_payload` (offline UI) and `rses_validate_for_tally` (submit / decrypt)

## Landed in Key Authority

- Active generation scheme = `modp-elgamal-feldman-v1` (`rses_may_generate`)
- Schema `rses_keys` 1.1.0: `scheme_id`, `ceremony_id`, commitments, transcript, status
- Official ZIP includes ceremony files + `encrypted-share.json` + verification instructions
- Offline “Verify my share” UI; fail-closed → `CEREMONY_INVALID:SHARE_VERIFICATION_FAILED`
- Export blocked when ceremony is invalid
- Tally / share submit accept Feldman payloads (still reconstruct `x` until Fase 2)

Legacy elections stay on `modp-elgamal-shamir-v1` (read-only archive).

## Still open (later)

- Broader adversarial acceptance suite on full ZIP packages
- Partial decryption + Chaum–Pedersen (Fase 2) — reconstruct `x` remains until then

## Tests

`php tests/feldman-vss-acceptance.php`  
`php tests/baseline-scheme-acceptance.php`
