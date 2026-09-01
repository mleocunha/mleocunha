# Security Policy

## Production Warning

**Voto Eletrônico / RelataSoft Secure Election Suite** is engineered toward
production-grade use. The cryptography **has not been independently reviewed** and
must not be used for binding public elections without:

- Independent cryptographic review
- Penetration testing
- Operational security review
- Electoral and legal certification

## Cryptographic Design

### ElGamal Encryption

- Safe prime generation: p = 2q + 1
- Subgroup generator of order q
- Vote counts encoded as g^m mod p for bounded homomorphic tallying
- Discrete log decoding is bounded to known maximum ballot counts only

### Shamir Secret Sharing

- Private exponent x is split immediately after key generation
- Field prime is dynamically generated greater than x
- Threshold t-of-n reconstruction required for decryption
- Share payloads include SHA-256 checksums

### Private Key Policy

- Full private key x is **not persisted** by default
- Reconstructed x exists only in memory during tally decryption
- x is never written to durable stores, logs, or exports
- Reconstructed variables are cleared after use

### Share Storage Encryption (standalone)

- Per-node data lives under `VE_DATA` (file JSON / secrets tree)
- Protect `VE_DATA` with OS permissions, disk encryption, and backup isolation
- **One node must never read another node's secrets**
- File encryption of shares is a storage abstraction only — **not** a substitute
  for HSM/KMS custody

## Access Control (standalone HTTP)

| Action | Requirement |
|--------|-------------|
| Operator panel `/painel` | Authenticated session after `/login` |
| Admin bootstrap | First admin from `VE_ADMIN_LOGIN` / `VE_ADMIN_PASS` |
| Voting journey `/voto` | Voting-mode node; enrolment per cadastro / session rules |

Do not expose the PHP listener on the public Internet without TLS and network controls.

## Audit Log

- Append-only / hash-chained audit where enabled
- Never logs: private key x, Shamir share values, plaintext votes

## Reporting Vulnerabilities

Report security issues to your election system administrator. Do not disclose
cryptographic vulnerabilities publicly before coordinated review.
