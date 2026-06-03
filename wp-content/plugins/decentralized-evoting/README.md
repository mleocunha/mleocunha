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

## Phase 4 — Brazil 2026 modalities (v0.3)

- **Numeric ballot** (2–5 digits by office): keypad, confirm with photo + party logo, Limpar / Branco / Nulo
- **Modalities:** FPTP (default for mayors), ballotage R1/R2, open-list PR (Brazilian quota + médias)
- **Admin:** cargo, vagas, % ballotage, qualified codes for R2, PR formula, blank/null/timeout toggles
- **Tally engines:** `fptp`, `ballotage`, `pr_brazilian` (D’Hondt/Sainte-Laguë/Hare stubbed for later)
- Decrypt-then-count by default; optional homomorphic prototype (v0.3.1) for FPTP/ballotage

## Homomorphic prototype (v0.3.1)

Exponential ElGamal one-hot ballots (`br-exp-one-hot`): each voter encrypts one bit per candidate slot; tally multiplies ciphertexts per slot and runs a small discrete log once per candidate (no per-ballot decrypt for counts).

1. On the running, set **Apuração homomórfica** → *one-hot exponencial* (max **12** numbered candidates).
2. Voters use the same numeric UI; the browser builds one-hot ciphertexts (`version` 2).
3. Tally import uses the export’s `homomorphic_mode`; results include `verify_match` against decrypt-then-count on slot bits.

Referendum mode (`br-exp-bit`) is available for yes/no experiments. PR tally still uses decrypt-then-count.

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
- **Homomorphic PR/STV** and Paillier-style additive tallies are not implemented — one-hot prototype only for FPTP/ballotage.
- Run generator and tally on isolated networks; never deploy private keys or shares on the polling node.

## Development

```bash
composer install --no-dev   # optional, vendor is committed
php bin/crypto-self-test.php
```
