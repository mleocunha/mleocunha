# Scheme specification: `modp-elgamal-shamir-v1`

**Status:** Fase 0 freeze — legacy baseline (shipping)  
**Implementation:** PHP + GMP  
**Registry:** `CryptoSchemeRegistry::SCHEME_MODP_ELGAMAL_SHAMIR_V1`  
**Share JSON `scheme` field (historical):** `ShamirSecretSharing`  
**Share JSON `version` field:** `1.0`

This document freezes the mathematics, formats, and private-exponent lifecycle of the current product. It is the reference for regression tests and for differential checks when Rust parity arrives. It does **not** claim independent cryptographic certification.

---

## 1. Security profile flags (baseline)

```text
scheme_id                    = modp-elgamal-shamir-v1
key_generation_mode          = trusted_dealer
private_key_reconstruction   = permitted_during_tally
verifiable_secret_sharing    = false
partial_decryption           = false
full_private_key_exists      = true   # briefly at keygen; briefly at tally
security_generation          = legacy-baseline
```

---

## 2. ElGamal (modular)

### 2.1 Group

- Safe prime \(p = 2q + 1\).
- Generator \(g\) of the order-\(q\) subgroup: sample \(h \in [2, p-2]\), set \(g = h^2 \bmod p\), require \(g > 1\) and \(g^q \equiv 1 \pmod p\).
- Primality: `gmp_prob_prime(·, 25)`.
- Allowed key sizes (bits): 512, 1024, 2048 (default), 3072, 4096. Minimum enforced: 512.

### 2.2 Key generation

\[
x \leftarrow_{\$}\ [2, q-2],\qquad y = g^x \bmod p
\]

Validated: \(g^q \equiv 1\), \(y^q \equiv 1\), \(y = g^x\).

**Code:** `ElGamal::generateKeyPair`, `generateKeyPairFromParams`  
**Chunked KA pipeline:** `safe_prime` → `generator` → `keypair` → `persist` → `shamir` → `complete` (≤25s/tick).

### 2.3 Encryption / decryption

Message element \(m \in [1, p-1]\). Ephemeral \(r \leftarrow_{\$}\ [2, q-2]\).

\[
\alpha = g^r \bmod p,\quad
\beta = m \cdot y^r \bmod p
\]

\[
m = \beta \cdot (\alpha^x)^{-1} \bmod p
\]

### 2.4 Vote encoding (exponential ElGamal)

Count \(c \ge 0\) encoded as \(M = g^c \bmod p\) (`CryptoEncoding::encodeCount`).  
Decode: bounded search \(i = 0..max\_count\) for \(g^i \equiv M\) (`decodeCount`).  
Ballot options typically use \(c \in \{0,1\}\).

### 2.5 Homomorphic aggregation

For ciphertexts \((\alpha_i, \beta_i)\) of encodings \(g^{c_i}\):

\[
\alpha = \prod_i \alpha_i,\quad
\beta = \prod_i \beta_i \pmod p
= \bigl(g^{\sum r_i},\; g^{\sum c_i}\, y^{\sum r_i}\bigr)
\]

Decrypt → \(g^{\sum c_i}\); bounded dlog with `max_decode_count` = number of ballots (unique voters) for that tally unit.

---

## 3. Shamir Secret Sharing

### 3.1 Field

Separate probable prime \(P > x\) with target bit length \(\mathrm{bitlen}(x) + 128\) (`PrimeGenerator::generatePrimeGreaterThan`).  
**Not** equal to ElGamal \(q\) in the baseline.

### 3.2 Split

Degree \(t-1\) polynomial over \(\mathbb{Z}/P\mathbb{Z}\):

\[
a_0 = x,\quad a_i \leftarrow_{\$}\ [1, P-1]\ (i=1..t-1)
\]

Shares \((i, f(i))\) for \(i = 1..n\) (Horner evaluation). Constraints: \(t \ge 2\), \(n \ge t\).

### 3.3 Reconstruct

Lagrange interpolation at \(0\) using any \(t\) shares (`reconstructWithThreshold` uses the first \(t\) after a count check).  
Tally path additionally checks \(g^x \equiv y \pmod p\) after reconstruction.

### 3.4 Verifiability

**None.** Only a SHA-256 checksum over the share JSON (excluding the checksum field). No Feldman/Pedersen commitments.

---

## 4. Format catalogue

### 4.1 Share payload (official custody JSON)

| Field | Type | Notes |
|-------|------|--------|
| `version` | string | `"1.0"` |
| `scheme` | string | `"ShamirSecretSharing"` (historical; not `scheme_id`) |
| `key_id` | int | |
| `election_round_id` | int | |
| `threshold_t` | int | |
| `total_n` | int | |
| `field_prime` | decimal string | \(P\) |
| `share_index` | decimal string | \(i\) |
| `share_value` | decimal string | \(f(i)\) |
| `public_key` | object | `{p,q,g,y}` decimal strings |
| `checksum` | hex SHA-256 | of `wp_json_encode` without checksum, `JSON_UNESCAPED_SLASHES` |

At rest: AES-256-CBC; key = SHA-256 of WordPress salts + `rses_share_encryption`; IV prepended; Base64 (`ShareEncryptionService`).

### 4.2 Public key (DB `wp_*_rses_keys` + export)

Persisted: `public_p/q/g/y`, `key_size`, `encoding_mode` default `g_power_count`, `field_prime`, `threshold_t`, `total_n`, `private_key_persisted` (normally `0`), nullable `private_x_encrypted`.

Export `public-key.json`: `{p,q,g,y,keySizeBits}`.

### 4.3 Key Authority export ZIP

| Path | Always / conditional |
|------|----------------------|
| `public-key.json` | always |
| `manifest.json` | always (`version` = plugin `RSES_VERSION`) |
| `checksums.json` | always |
| `README.txt` | always |
| `private-key.json` | only if DB persisted `x` (default path: absent) |
| `shamir-shares.json` | admin full export |
| `own-share.json` | official own export |

### 4.4 Voting export ZIP

`manifest.json`, `public-key.json`, `election.json`, `round.json`, `ballot.json`, `encrypted-votes.json`, `encrypted-tallies.json`, `audit.json`, `README.txt`, `checksums.json`.

### 4.5 Fields **not** present in baseline artefacts

`format_version`, `scheme_id`, `profile_id`, `ceremony_id`, `public_transcript_hash`, VSS `commitments`, partial-decryption proofs.

---

## 5. Inventory — where private exponent `x` exists

| Location | Form | Lifetime |
|----------|------|----------|
| `ElGamal::generateKeyPairFromParams` | plaintext GMP | request memory |
| WP option `rses_keygen_job.private_x_encrypted` | AES ciphertext of decimal `x` | between KA stages `keypair` and end of `shamir`; cleared by `rses_clear_secrets` |
| `ShareAssignmentService` during split | plaintext GMP | request memory; discarded after encrypting shares |
| `wp_*_rses_keys.private_x_encrypted` | column exists | **default keygen does not write it** (`private_key_persisted = 0`) |
| `wp_*_rses_shares.share_payload_encrypted` | Shamir shares of `x` | until ceremony retired |
| Tally submissions table | encrypted share JSON | until retired |
| `TallyDecryptionService` | reconstructed GMP `$rses_x` | decrypt request; `unset` after use |
| Official “My shares” UI / JSON / ZIP | decrypted `share_value` | screen + download (custodian custody) |
| Admin full ZIP `private-key.json` | only if persisted | export artefact |
| Audit log | keys named like `private_x` / `share_value` | **redacted** to `[REDACTED]` |
| AJAX keygen public status | — | never includes `x` |
| `CryptoSelfTest` / CLI acceptance | in-process only | test process |

**Shares are not “naked” on disk** (AES at rest), but they are **not VSS-verifiable** and a human with the WordPress salts (or a decrypted download) holds a usable share of `x`.

---

## 6. Tally decryption flow (baseline)

1. Voting aggregates per option → \((\alpha, \beta)\).  
2. Tallying imports package; officials submit share JSON until count \(\ge t\).  
3. Admin decrypt: reconstruct full \(x\), check \(g^x = y\), `ElGamal::decrypt`, bounded dlog.  
4. Result stored in transient (~1h); `$x` unset.

There is **no** partial decryption and **no** decryption proof.

---

## 7. Fixed regression vectors

See `tests/vectors/modp-elgamal-shamir-v1-tiny.json` and `tests/baseline-scheme-acceptance.php`.

These use a **toy** safe prime \((p,q)=(23,11)\) solely to lock arithmetic. They are not production parameters.

---

## 8. Successor

Next generation scheme: `modp-elgamal-feldman-v1` (Fase 1) — same modular group, Feldman commitments, offline share verification, fail-closed ceremony invalidation. See `docs/CRYPTO-EVOLUTION.md`.
