# Scheme specification: `modp-elgamal-feldman-v1` (Fase 1)

**Status:** generation + ceremony wiring (plugin ≥ 1.0.28.0)  
**Implementation:** PHP + GMP  
**Registry:** `CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_FELDMAN_V1`  
**Class:** `includes/Crypto/FeldmanVss.php`

## Field prime

Feldman verification

\[
g^{s_i} \equiv \prod_{k=0}^{t-1} C_k^{\,i^k} \pmod p,
\qquad C_k = g^{a_k} \pmod p
\]

requires polynomial arithmetic in \(\mathbb{Z}/q\mathbb{Z}\). Therefore **field = \(q\)** (ElGamal subgroup order). Also \(C_0 = g^{a_0} = g^x = y\).

Plain Shamir’s separate field prime \(P > x\) is **not** used in this lineage.

## API

- `FeldmanVss::rses_split_with_commitments(x, t, n, p, q, g)`
- `FeldmanVss::rses_verify_share(i, s, commitments, p, q, g, y?)`
- `FeldmanVss::rses_build_share_payload(...)` / `validateSharePayload(...)`
- `CeremonyTranscript::rses_build` / `rses_public_files`
- `ShareVerifyService::rses_verify_payload` / `rses_validate_for_tally`
- `Polynomial::rses_reconstruct_with_threshold` (Fase 1 tally only)

## Clean cut

Legacy `ShamirSecretSharing` / `modp-elgamal-shamir-v1` payloads are rejected. Use the previous plugin version for archived SSS elections.
