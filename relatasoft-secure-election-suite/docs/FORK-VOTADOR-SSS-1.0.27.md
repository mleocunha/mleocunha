# Fork lab: Votador ↔ WordPress @ 1.0.27 (SSS)

**Branch:** `cursor/votador-wp-sss-1.0.27-2eb1`  
**Frozen plugin version:** `1.0.27` crypto baseline (`33fcc6a`); lab patches through **`1.0.27.10`** (admin can clear submitted fractions per election)  
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

## Lab findings (integration)

Cherry-pick candidates for the evolved line (no crypto):

1. **Login success on `/id.php`** — Votador must treat “still on the configured login URL” (or `#login_error`) as failure, not only `wp-login.php`. Shared helper: `votador-prova-de-conceito/src/lib/wpLogin.js`.
2. **Composite receipt hash** — Booth “already voted” used a single `vote_hash` row; cast receipt is `sha256(concat vote_hashes)`. Fixed in `EncryptedVoteRepository::rses_get_receipt_hash`.
3. **Journey provision includes booth** — `VoterJourney::rses_provision_pages()` now creates the voting-booth page with `[rses_voting_booth]` (query override `?election_id=&round_id=`).
4. **Journey cache / booth selectors** — Fill journey URLs only when empty under concurrency; discover booth from `a.rses-open-election-link` / JSON only (not generic primary CTAs).
5. **Ballot choice selection** — Failures looking like “Chrome too fast / wrong password” were actually Playwright `locator.check()` on opacity-0 radios (`.rses-choice-input`). Fix: set `input.checked` in-page (never `locator.check`).
6. **UI log replay** — SSE `/api/events` replayed the last 200 events on every connect, so old `locator.check` failures looked like the current run. Fix: per-run `runId`, `log_reset` on start, UI ignores other runs.
7. **Multi-booth page cells** — GET must not override pinned shortcodes; Votador discovers booth cells on first elector (`booth-cells-1`).
8. **Login / CSV parity** — importer and Votador share cell normalization; hardened parallel login; email≠login collisions refused (`login-csv-parity-1`).
9. **macOS caffeinate -d** — Votador starts `caffeinate -d` at the beginning of each run so the display does not sleep mid-vote (`caffeinate-d-1`).
10. **Electoral-roll false max-rows (1.0.27.1)** — double-submit / retried chunks can append the CSV twice (~5000×2 → “mais de 10000 linhas”). Idempotent chunk append, JS busy guard, truncate oversized assembly to declared size (same fix as evolved 1.0.28, version kept under SSS lab lineage).
11. **WP login fill race (`login-fill-1`)** — headed Chrome autofill can put the saved password into `#user_login` after Playwright `fill()`. Credentials are now set via DOM + re-asserted before submit; PoC admin form uses `autocomplete=off`. Admin login at run start remains intentional (open-elections scrape only).
12. **Voting export OOM (1.0.27.2)** — ZIP/JSON export loaded every ciphertext into RAM then pretty-printed (128M fatal). Votes stream to a temp file in compact JSON and ZIP attaches from disk.
13. **Tally import white screen (1.0.27.3 / .4)** — Importação da apuração loaded `encrypted-votes.json` into PHP + LONGTEXT and exhausted 128M. Never read vote ZIP members into PHP; list imports without `import_manifest_json`; SQL-purge oversized manifests from failed earlier attempts; show plugin version on the import page.
14. **Tally import “Failed to parse” (1.0.27.5)** — after raising PHP memory, ZIP opened but members were not found (subfolder / name mismatch / JSON-in-ZIP). Index by basename, strip BOM, keep stable ZIP entry names on export, surface entry list in the error.
15. **Tally import rejected validation (1.0.27.6)** — empty `encrypted_tallies` after low-memory close. Stream tally compute on close/export; on import rebuild tallies from `encrypted-votes.json` when missing; normalize public_key aliases; persist validation errors in the stored manifest.
16. **Tally UI election identity (1.0.27.7)** — denormalize `election_title` / `round_title` / `ballot_count` on import; show them on Import, Share Submission, and Certification so multiple concurrent elections are distinguishable.
17. **Shamir fractions ↔ elections (1.0.27.8)** — export/import UIs show which elections each key is linked to; share JSON packages include `key_label` + `linked_elections` (crypto `share` unchanged); Voting Public Keys / Export list key identity; pt_BR Shamir UI uses “fração/frações” instead of “parte(s)”.
18. **Fraction submit bound to imported election (1.0.27.9)** — Tallying Share Submission only lists verified imports; each card is one election; submit validates share `public_key` against that import (fingerprint), rejects wrong-election pastes even when key labels collide across servers; one fraction per official per election.
19. **Admin clear fractions (1.0.27.10)** — Administrators can clear all submitted Shamir fractions for one imported election (with confirm), discarding cached decryption so mistaken submissions can be corrected.

Password-change PoC still requires `[enviar_redefinicao_senha]` on the welcome page (documented in Redirections admin copy).
