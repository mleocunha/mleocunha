# RelataSoft Secure Election Suite — Success Status

**Status:** End-to-end flow verified successful (as of 2026-07-28)  
**Plugin version:** `1.0.10`  
**Branch:** `cursor/secure-election-suite-2eb1`  
**PR:** https://github.com/mleocunha/mleocunha/pull/6  
**Slug / namespace / prefix:** `relatasoft-secure-election-suite` / `RelataSoft\SecureElectionSuite` / `rses_`  
**Path in repo:** `relatasoft-secure-election-suite/`

Use this file as the handoff baseline when starting a new context window for improvements. Do not re-litigate settled policy below unless the product owner changes it.

---

## What “successful” means

The suite has been exercised across its intended multi-site / multi-role workflow:

1. **Key Authority** generates an ElGamal key, splits the private exponent with Shamir, assigns shares to officials.
2. **Editors** can view, copy, and download their own Shamir share.
3. **Voting Platform** accepts encrypted ballots from enrolled **Subscriber** voters and returns a receipt hash.
4. **Tallying** accepts official share submissions; **Administrators** import packages, decrypt when threshold is met, and certify.

Known earlier bugs (blank page after lock mode, broken nonces, audit hash chain false INVALID, keygen progress bar not appearing, admins able to vote via `read` capability) were fixed on this branch.

---

## Architecture (settled)

### Three mutually exclusive locked modes

One mode per WordPress installation (locked after Mode Setup):

| Mode | Purpose |
|------|---------|
| `key_authority` | Generate keys, Shamir split, official share custody / export |
| `voting` | Public-key import, elections, encrypted casting, export tallies |
| `tallying` | Import voting packages, collect shares, decrypt, certify |

### Crypto stack

- ElGamal over safe primes `p = 2q + 1`
- Homomorphic tally via multiplicative aggregation of `g^m`-encoded counts
- Shamir Secret Sharing for private exponent custody
- Private `x` not persisted by default; reconstructed in memory at tally time
- GMP required; crypto self-test + CLI acceptance tests under `tests/`

### Chunked key generation (Key Authority)

Settled product decisions:

1. **AJAX** ticks (`rses_keygen_start|tick|status|cancel`)
2. **≤25s** wall-clock budget per request
3. **Whole pipeline** stages: `safe_prime` → `generator` → `keypair` → `persist` → `shamir` → `complete`
4. **Checkpointed mid-search** — deterministic HMAC attempt stream; resume continues the same attempt index
5. **One job per site**; admin can **Cancel** (clears secrets)
6. **Progress bar** + stage / attempt text
7. Key sizes: **2048 / 3072 / 4096** (plus 512/1024 for local testing)
8. Job option **auto-purge after 24 hours**

Key files: `KeyGenerationJob.php`, `KeyGenerationRunner.php`, `DeterministicRandom.php`, `assets/js/key-authority.js`.

---

## Role policy (settled — enforce via explicit roles)

Do **not** gate voting on `current_user_can('read')` or official actions on bare `edit_posts` alone. Use explicit role membership in `Capability.php`:

| WordPress role | Election role / powers |
|----------------|------------------------|
| **Administrator** | Mode/settings/audit; tally import; decrypt; certify; may also be assigned a Shamir share |
| **Editor** | Official: receive / view / copy / download own share (Key Authority); submit share (Tallying) |
| **Contributor** | Candidate (mapping only; not a verified voting path) |
| **Subscriber** | **Only** role that may cast a ballot |

### Voting

- Must have role slug `subscriber` on the account.
- Admin-only / editor-only **cannot** vote.
- Dual-role (e.g. Editor + Subscriber) **can** vote.
- Enforced in UI, cast endpoints, and again in `VoteEncryptionService` (identity must match current user; denials audited).

### Officials (Editors)

- **Key Authority:** menu **My Shamir Shares** — on-screen JSON, Copy, Download JSON, Download ZIP.
- **Tallying:** menu **Share Submission** always available to Editors.
- Administrators assigned a share may also access their own share.

### Tally / certification

- **Administrator** role only for import, decrypt, certify.
- Editors submit shares but cannot decrypt or certify.
- Service-layer gate in `TallyDecryptionService`.

Constants in `Capability.php`: `RSES_VOTER_ROLE`, `RSES_OFFICIAL_ROLE`, `RSES_ADMIN_ROLE`.

---

## Verified UX surfaces

### Key Authority

- Chunked generate UI with progress bar + Cancel
- Official assignment checkboxes (Editors + Administrators)
- Admin key cards + exports
- Editor **My Shamir Shares** (view / copy / download)
- Generate / Import / Export / My Shares restyled (v1.0.6) to match voting booth tokens (Source Serif/Sans, teal `#0c7c9c`, official choice cards) — **logic unchanged**

### Voting

- Shortcode generator admin page
- Shortcodes: `[rses_voting_booth]`, `[rses_voter_receipt]`, `[rses_election_status]`
- Successful cast returns a **receipt hash** (plaintext choices never stored)
- Frontend booth + receipt restyled (v1.0.5) with TotalPoll-inspired choice cards / check selectors / receipt card — **logic unchanged**
- Election / Public Keys / Shortcodes / Export admin restyled (v1.0.7) to match RelataSoft shell — **logic unchanged**
- Ballot options may include **photo / audio / video** attachments from the Media Library (v1.0.9); stored in `metadata_json`, shown on the booth — **vote encryption still keyed by option ID**

### Tallying

- Admin: Tally Import, Certification
- Editor: Share Submission
- Dashboard cards hide import/certify from non-admins
- Import / Share Submission / Certification restyled (v1.0.7) with hero, cards, threshold progress — **logic unchanged**

### Cross-cutting fixes already shipped

- Handlers registered from `Plugin::run()` (admin-post works)
- Nonce fields actually echoed
- Audit hash chain canonicalization + repair action
- Asset version `1.0.10` for cache busting

### RelataSoft admin branding (v1.0.10)

- Pinwheel mark + lockup on **admin** heroes only (Key Authority, Voting admin, Tallying, Dashboard, Mode Setup, Settings, Audit)
- Gold brand accents (`#f5a623` / `#ffb800`) on admin shell
- **Voting booth intentionally unbranded** — no RelataSoft logo/propaganda on cast/receipt screens

---

## Important paths

- Text domain: `relatasoft-secure-election-suite`
- UI locales: **pt_BR, pt_PT, fr_FR, es_ES, de_DE, nl_NL, ru_RU, zh_CN, ar, he_IL, ca** (+ English source)
- Resolution order: **browser `Accept-Language` → WordPress user locale → site/blog locale**
- Catalogs: `languages/catalogs/*.json` via `I18n\Translator` gettext filters
- RTL: Arabic / Hebrew (`dir="rtl"`, body class `rses-rtl`)

---

## Important paths

```
relatasoft-secure-election-suite/
  relatosoft-secure-election-suite.php          # bootstrap, RSES_VERSION
  includes/Bootstrap/Plugin.php                 # hooks, asset enqueue
  includes/I18n/LocaleResolver.php              # browser → user → site locale
  includes/I18n/Translator.php                  # JSON catalogs + gettext filters
  includes/Security/Capability.php              # role gates
  includes/KeyAuthority/                        # keys, shares, chunked keygen
  includes/Voting/                              # elections, cast, export, OptionMedia
  includes/Voting/OptionMedia.php               # option photo/audio/video attachments
  includes/Tallying/                            # import, shares, decrypt, certify
  includes/Admin/AdminMenu.php                  # menus by mode/role
  languages/catalogs/                           # UI translation JSON
  languages/strings-en.json                     # extracted English msgids
  assets/css/admin.css                          # Key Authority / admin RelataSoft UI
  assets/css/voting-front.css                   # booth + receipt frontend UI
  assets/js/key-authority.js                    # keygen AJAX UI
  assets/js/admin.js                            # copy share / shortcode helpers
  tests/crypto-acceptance.php
  tests/keygen-checkpoint-acceptance.php
  tests/locale-resolution-acceptance.php
  SUCCESS.md                                    # this file
```

---

## How to re-verify quickly

1. Update plugin from branch; hard-refresh admin (Ctrl/Cmd+Shift+R).
2. **Key Authority site:** generate key (≥2048), assign Editors → each Editor opens My Shares → copy/download.
3. **Voting site:** import public key → create election → publish shortcode → log in as **Subscriber** → cast → receipt hash.
4. Confirm Administrator without Subscriber cannot cast.
5. **Tallying site:** Admin imports package → Editors submit shares → Admin decrypts/certifies when threshold met.

CLI (GMP required):

```bash
php tests/crypto-acceptance.php
php tests/keygen-checkpoint-acceptance.php
```

---

## Suggested next-improvement themes (not started)

Use this list only as optional backlog after context reset; none of these block the declared success:

- Independent crypto review / threat model documentation
- Stronger share–submitter binding across Key Authority → Tallying sites
- Rate limiting / lockout around cast and share submit
- UI polish for Mode Setup / dashboard cards; i18n review passes; accessibility
- Automated WordPress integration tests (role matrix)
- Production hardening checklist (HTTPS, salts, backup of share custody procedure)

---

## Handoff note for the next session

Start from this file and the current `README.md` / `SECURITY.md`. Prefer small, policy-preserving changes. Role and keygen chunking decisions above are **product-settled** unless the user explicitly revises them.
