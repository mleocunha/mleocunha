# MVP acceptance checklist

Run on a real Virtualmin host with a disposable test domain.

| # | Criterion | Command / check |
|---|---|---|
| 1 | Parent exists with Mail | `virtualmin list-domains --domain DOM --multiline` |
| 2 | `webmail.DOM` created | `install DOM` |
| 3 | Child of parent | `Parent domain: DOM` |
| 4 | Website on | Features include `web` |
| 5 | Mail off on sub | Features **exclude** `mail` |
| 6 | Full SnappyMail in docroot | `diagnose` → snappymail_present |
| 7 | PHP works | browse / php-fpm mode |
| 8–9 | HTTPS + cert | `diagnose` https / LE |
| 10 | Login identity = parent | domain.ini whitelist parent |
| 11–12 | IMAP/SMTP | diagnose endpoints |
| 13 | No mailboxes on webmail.* | `list-users --domain webmail.DOM` empty/absent |
| 14 | No MX for webmail.* | DNS check |
| 15 | status lists install | `status --all` |
| 16 | diagnose works | `diagnose DOM` |
| 17–18 | backup+restore | see BACKUP-RESTORE.md |
| 19 | discover after restore | `discover` |
| 20 | adopt non-destructive | `adopt DOM` |
| 21 | Webmin module installed | `install-to-system.sh` → module under Webmin root |
| 22 | GUI status page loads | Virtualmin → SnappyMail |
| 23 | Feature plugin listed | Features and Plugins → SnappyMail webmail |

Cloud agent note: items requiring live Virtualmin are validated here via fake-client tests; full checklist needs the production host.
