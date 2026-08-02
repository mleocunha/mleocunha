# ADR-001 — Crypto evolution roadmap

**Status:** Accepted  
**Date:** 2026-08-02  
**Deciders:** Product owner (digital-law counsel) + engineering  

## Context

The suite ships modular ElGamal + plain Shamir with reconstruct-then-decrypt tally. That is insufficient for mission-critical custody: shares are not verifiable, a full private exponent `x` exists briefly at keygen and again at tally, and there is no path to DKG or TSE-aligned elliptic parameters.

## Decision

1. Keep **PHP + GMP** as the executable reference until the modular protocol (VSS + threshold decrypt + proofs) is stable and differentially tested.
2. Treat **Rust** as a later reimplementation under differential tests — not a simultaneous rewrite of language, group, and protocol.
3. Sequence: **Feldman VSS (PHP)** → **partial decryption + Chaum–Pedersen (PHP)** → **Rust parity (modular)** → **EC ElGamal P-521 (Rust)** → **Pedersen DKG (Rust)**.
4. Frame TSE alignment as **primitives/parameters** (P-521, SHA-512, ECDSA P-521+SHA-512, AES+HMAC-SHA-512), never as “same electoral protocol as the TSE.”
5. Identify schemes by stable `scheme_id` strings in `CryptoSchemeRegistry` (see `docs/CRYPTO-EVOLUTION.md`).

## Consequences

- Baseline `modp-elgamal-shamir-v1` is frozen as legacy-baseline (this Fase 0).
- New elections will eventually refuse the plain-Shamir generator; archives remain verifiable read-only.
- Reconstruct-`x` becomes a legacy adapter after Fase 2.
- Eight contractual pendencies wait on the TSE team and do not block Fase 0–1 (`docs/CRYPTO-EVOLUTION.md` §4).

## References

- `docs/CRYPTO-EVOLUTION.md`
- `docs/crypto/modp-elgamal-shamir-v1.md`
- `includes/Crypto/CryptoSchemeRegistry.php`
