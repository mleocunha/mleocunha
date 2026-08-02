# Security Policy

## Production Warning

RelataSoft Secure Election Suite is intended to be engineered toward production-grade use. However, **the cryptography in this plugin has not been independently reviewed** and must not be used for binding public elections without:

- Independent cryptographic review
- Penetration testing
- Operational security review
- Electoral and legal certification

## Cryptographic Design

**Baseline (shipping today):** modular ElGamal + plain Shamir (`modp-elgamal-shamir-v1`).

**Evolution (product decisions locked):** see [`docs/CRYPTO-EVOLUTION.md`](docs/CRYPTO-EVOLUTION.md) — Feldman VSS → threshold decryption without reconstructing `x` → Rust parity → EC P-521 → DKG. TSE alignment is **primitives/parameters** (P-521, SHA-512, ECDSA P-521+SHA-512, AES+HMAC-SHA-2), not an identical electoral protocol.

### ElGamal Encryption

- Safe prime generation: p = 2q + 1
- Subgroup generator of order q
- Vote counts encoded as g^m mod p for bounded homomorphic tallying
- Discrete log decoding is bounded to known maximum ballot counts only

### Shamir Secret Sharing

- Private exponent x is split immediately after key generation
- Field prime is dynamically generated greater than x (not fixed 128-bit)
- Threshold t-of-n reconstruction required for decryption
- Share payloads include SHA-256 checksums
- **Feldman VSS (Fase 1)** is active for new Key Authority ceremonies (`modp-elgamal-feldman-v1`); legacy Shamir elections remain archive-only. Pedersen / DKG remain later phases.

### Private Key Policy

- Full private key x is **not persisted** by default
- Reconstructed x exists only in memory during tally decryption (to be prohibited after threshold partial-decryption ships)
- x is never written to database, logs, or exports
- Reconstructed variables are cleared after use

### Share Storage Encryption

- Shares are encrypted at rest using a key derived from WordPress salts
- This is a basic storage protection abstraction only
- **Not a substitute** for hardware-backed key custody (HSM, KMS)
- Future versions may integrate libsodium, OpenSSL, or HSM backends

## Access Control

| Action | Required Capability |
|--------|---------------------|
| Admin operations | `manage_options` |
| Official/share holder | `edit_posts` |
| Voting | Logged-in user with `read` (subscriber) |

## Audit Log

- Append-only hash-chained audit log
- Never logs: private key x, Shamir share values, plaintext votes
- Chain integrity verifiable from admin Audit Log page

## Reporting Vulnerabilities

Report security issues to your election system administrator. Do not disclose cryptographic vulnerabilities publicly before coordinated review.

## Export Security

- All state-changing requests require WordPress nonces
- Downloads use `admin-post.php` handlers (not fragile AJAX blob downloads)
- Full private key export disabled by default; requires explicit admin setting and confirmation
