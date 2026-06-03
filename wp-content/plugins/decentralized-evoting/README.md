# Decentralized E-Voting (WordPress)

Single plugin, three segregated deployment modes controlled by `wp-config.php`:

```php
define( 'EVOTE_NODE_TYPE', 'generator' ); // authority — key generation + SSS
define( 'EVOTE_NODE_TYPE', 'polling' );   // public voting station
define( 'EVOTE_NODE_TYPE', 'tally' );     // decryption + tally board
```

## Install

1. Copy `wp-content/plugins/decentralized-evoting` into your WordPress `wp-content/plugins/` directory.
2. Activate **Decentralized E-Voting System**.
3. Set `EVOTE_NODE_TYPE` for the role of that site.

Bundled **phpseclib 3** (and paragonie dependencies) ship under `vendor/` — no Composer required on the server.

## Phase 2: Modular ElGamal + Shamir (Node 1 & 3)

- **Group:** RFC 3526 MODP Group 14 (2048-bit), scheme `modular-elgamal`
- **SSS:** Configurable **t-of-n** from **2-of-3** up to **t-of-n** with n ≤ 99 (default **3-of-5**)
- **Node 1:** Admin → E-Voting → Key Generation — generate and download public key + share JSON files
- **Node 3:** Tally → paste share JSON to reconstruct private key; **encrypt/decrypt helpers** to verify ballots (outputs expire after 5 minutes, not stored)
- **Classes:** `EVote_Elgamal`, `EVote_Shamir`, `EVote_Crypto`

CLI check (optional): `php bin/crypto-self-test.php` from the plugin directory.

## Phase 1: Polling station data model

| Layer | Implementation |
|--------|----------------|
| `evote_running` | Election event (schedule, public key JSON, modality, ballot candidates) |
| `evote_candidate` | Candidates |
| `evote_modality` | Reusable modality templates |
| `evote_party`, `evote_slate` | Taxonomies on candidates |
| `{prefix}evote_electors` | Token hashes (no plaintext tokens stored) |
| `{prefix}evote_ballots` | Encrypted ballot ledger |

JSON schemas for cross-node payloads are defined in `includes/class-evote-json-payloads.php`.

## Roadmap

| Phase | Focus |
|-------|--------|
| **1** | CPTs, custom tables, meta boxes, node switching ✅ |
| **2** | Modular ElGamal + Shamir (Node 1 + Node 3) ✅ |
| **3** | JSON import/export, client-side encryption, tally engine |

## Development

Regenerate vendor (optional) with Composer from the plugin directory:

```bash
composer install --no-dev
```

## Security notes

- Run Node 1 and Node 3 on isolated networks.
- Never store private keys or SSS shares on the polling node.
- Plaintext votes should only be encrypted in the browser before submission (Phase 3).
