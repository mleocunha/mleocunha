# RelataSoft Secure Election Suite

A WordPress plugin for secure election management using ElGamal encryption and Shamir Secret Sharing.

## Overview

This plugin supports three mutually exclusive modes (one per WordPress installation):

1. **Key Authority / ElGamal Key Manager** — Generate keys, split private exponents with Shamir Secret Sharing, assign shares to officials
2. **Voting Platform** — Encrypted ballot casting with homomorphic tally aggregation
3. **Tallying and Certification Platform** — Import voting exports, collect official shares, decrypt tallies, generate certification reports

## Requirements

- WordPress 6.0+
- PHP 8.1+
- GMP extension (required for cryptography)
- JSON extension
- SHA-256 hash support
- ZipArchive (optional at activation; required for ZIP exports)

## Installation

1. Upload the `relatasoft-secure-election-suite` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins screen
3. Navigate to **Election Suite → Mode Setup** and select your mode
4. Run **Crypto Self Test** to verify cryptographic operations

## WordPress Role Mapping

| WordPress Role | Election Role |
|----------------|---------------|
| Administrator  | Election administrator |
| Editor         | Election official / Shamir share holder |
| Contributor    | Candidate |
| Subscriber     | Voter |

## Shortcodes

- `[rses_voting_booth election_id="1" round_id="1"]` — Voting booth for logged-in voters
- `[rses_voter_receipt election_id="1" round_id="1"]` — Display vote receipt hash
- `[rses_election_status election_id="1"]` — Election status display

## Cryptography

- **ElGamal** encryption with safe primes (p = 2q + 1)
- **Homomorphic tallying** via multiplicative aggregation of g^m-encoded vote counts
- **Shamir Secret Sharing** for private key threshold custody
- Private exponent x is never persisted by default; reconstructed only in memory during tallying

## Internationalization

The plugin supports:

- English
- Spanish (es_ES)
- Portuguese (pt_BR)
- French (fr_FR)
- German (de_DE)
- Mandarin Chinese (zh_CN)

All UI strings use WordPress i18n functions with text domain `relatasoft-secure-election-suite`.

## Production Warning

This plugin is engineered toward production-grade use, but **do not** deploy for binding public elections without:

- Independent cryptographic review
- Penetration testing
- Operational security review
- Electoral and legal certification

See [SECURITY.md](SECURITY.md) for details.

## Development

Run PHP syntax validation:

```bash
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

Run crypto self-tests from WordPress admin: **Election Suite → Crypto Self Test**.

## License

GPL-2.0-or-later
