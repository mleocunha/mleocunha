# Fork lab: Votador ↔ WordPress @ 1.0.27 (SSS)

**Branch:** `cursor/votador-wp-sss-1.0.27-2eb1`  
**Frozen plugin version:** `1.0.27` (commit `33fcc6a`)  
**Crypto:** plain Shamir (`modp-elgamal-shamir-v1` behaviour) — **no** Feldman VSS / ceremony wiring

## Purpose

Isolate work on the **Votador Prova de Conceito** and its relationship with WordPress (login, roles, shortcodes, session, scrape hooks, credentials, Roundcube password flow, timing) without mixing in the crypto evolution line.

## Parallel lines

| Line | Branch | Focus |
|------|--------|--------|
| This lab | `cursor/votador-wp-sss-1.0.27-2eb1` | Votador + WP integration on SSS |
| Evolved | `cursor/votador-prova-de-conceito-2eb1` | Electoral-roll 1.0.28+ and Feldman VSS (1.0.30+) |

## What to bring back later

- **Yes:** WP API/session/nonce/role bugs, Votador selectors/URLs/contracts, docs, small integration patches that do not touch share schemes.
- **No:** SSS-specific crypto “fixes” that fight Feldman; do not merge this branch wholesale into the VSS line.

Prefer cherry-picks or documented findings over large merges.
