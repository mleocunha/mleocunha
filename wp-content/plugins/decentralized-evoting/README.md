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

Bundled **phpseclib 3** ships under `vendor/` — no Composer required on the server.

## End-to-end workflow

| Step | Node | Action |
|------|------|--------|
| 1 | **generator** | Generate keys (default 3-of-5), download public key + share JSON files |
| 2 | **polling** | Create running, paste public key JSON, import elector tokens, set status **Open** |
| 3 | **polling** | Publish page with `[evote_poll id="RUNNING_ID"]` — voters encrypt in browser |
| 4 | **polling** | **Polling Tools** → download ballot export JSON (checksum included) |
| 5 | **tally** | Reconstruct private key from t shares |
| 6 | **tally** | Paste ballot export + private key → **Verify import & run tally** |

## Phase 3 — Import / export / voting / tally

### Polling station (Node 2)

- **Polling Tools:** import elector tokens (hashed), export `evote-ballot-export` JSON
- **Shortcode:** `[evote_poll id="123"]` — client-side modular ElGamal (`assets/js/evote-crypto-client.js`)
- Ballots stored encrypted in `{prefix}evote_ballots`; tokens in `{prefix}evote_electors`

### Tally board (Node 3)

- Import ballot box with **SHA-256 checksum** verification
- Batch decrypt and produce `evote-tally-result` JSON
- Encrypt/decrypt helpers retained for spot checks

### Phase 2 — Crypto (summary)

- Modular ElGamal, RFC 3526 Group 14, Shamir t-of-n (2-of-3 … n≤99, default 3-of-5)

## Roadmap / limits

- **Ranked voting** modality is configured but not yet tallied differently from single/multiple.
- **Homomorphic tally** (sum without per-ballot decrypt) is not implemented — decrypt-and-count model.
- Run generator and tally on isolated networks; never deploy private keys or shares on the polling node.

## Development

```bash
composer install --no-dev   # optional, vendor is committed
php bin/crypto-self-test.php
```
