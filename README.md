# Spamschutz: captcha-free spam protection for Grav forms

Spamschutz (German for *spam protection*) scores every form submission instead of
relying on a single rule. Only when the points reach a threshold is the submission
rejected, so one oddity (a link, an English sentence, a typo) never blocks a real
message. No captcha, no third-party service, nothing loaded from other servers.

Why: the honeypot of the Grav Form plugin no longer stops modern bots. They render
the page properly and leave the hidden field empty. Spamschutz runs after the
honeypot and looks at *how* and *what* was submitted.

## How submissions are scored

| Signal | Points |
|---|---|
| Submitted faster than `mindestzeit` seconds (default 4) after the page was shown | = threshold (rejects alone) |
| More than `max_pro_stunde` submissions per hour from the same address (default 8) | = threshold (rejects alone) |
| Foreign script (Cyrillic, Greek, Hebrew, Arabic, CJK) | 3 |
| One link | 2 |
| Two or more links | 4 |
| BBCode such as `[url=…]` | 4 |
| Spam phrase from the list, per phrase (max. 4) | 2 |
| Longest text contains none of the site language's everyday words | 2 |
| No session when submitting (bot posted without loading the page) | 2 |

Rejected at **6 points** by default (`schwelle`). Speed and volume are the most reliable
bot signals and reject on their own; everything else needs several hits. Examples:

- A registration that is just a list of names (no everyday word, 2) with a link to the
  group's website (2): **4 points, accepted**.
- An English sales pitch (2) with a link (2), sent within 2 seconds (6): **10 points, rejected**.

In production on four Grav sites since September 2026: every rejected submission so far
was sent within 0 to 2 seconds, i.e. by a bot.

## Installation

Requires Grav 1.7+ with the Form plugin. Developed and tested on Grav 2.2.12 with
Form 9.1; it only uses APIs that Grav 1.7 has as well, but 1.7 is untested.

Manual: download the latest release, unpack it to `user/plugins/spamschutz` and clear
the cache (`bin/grav clearcache`). The plugin protects every form on the site; there is
nothing to add to your form definitions.

## Configuration

Copy `spamschutz.yaml` to `user/config/plugins/spamschutz.yaml` and adjust it, or use the
plugin settings in the admin panel. The keys are German:

| Key | Default | Meaning |
|---|---|---|
| `schwelle` | `6` | Threshold: reject at this many points. Higher = more lenient. |
| `mindestzeit` | `4` | Minimum seconds between showing the form and submitting it. |
| `max_pro_stunde` | `8` | Submissions per address and hour before rejecting. |
| `melde_text` | German text | Message a rejected visitor sees (asks them to write by email instead). |
| `begriffe` | list | Spam phrases, lower case, matched as substrings. |
| `sprachpruefung` | `true` | Enable the everyday-word check. |
| `alltagswoerter` | German words | Everyday words of the site language. For an English site replace them (`the, and, is, you, with, …`) or set `sprachpruefung: false`. |
| `proxy_vertrauen` | `false` | Read the address from `X-Forwarded-For`. Only enable behind your own reverse proxy; otherwise clients can fake the header and dodge the rate limit. |
| `protokoll_tage` | `30` | Days to keep the log, `0` keeps it forever. |

## Log and privacy

Every rejection and every near miss (more than 0 points) is written as one JSON line to
`user/data/spamschutz/protokoll.log`: time, result, points, reasons, form name, IP address
and the first 400 characters of the longest text. Use it to spot false positives and tune
the phrase list. Entries older than `protokoll_tage` are removed automatically.
`takt.json` in the same folder holds submission times per address for one hour.

Because the log contains IP addresses and message text, mention it in your privacy policy
(legitimate interest in protecting the form, Art. 6(1)(f) GDPR).

## Tests

Plain PHP scripts, no Composer or PHPUnit needed. Grav, the Form plugin and the session are
replaced by small stubs in `tests/stubs.php`; all names and messages in the tests are made up.

```bash
php tests/alle.php      # everything, exit code 1 if a check fails
php tests/punkte.php    # scoring and threshold: real messages pass, typical spam does not
php tests/regeln.php    # minimum time, rate limit, proxy, everyday words, phrases, log expiry
```

Checks marked `bekannt` describe a known bug and are expected to fail until it is fixed.

## Deutsch in Kürze

Bewertet jede Formular-Einsendung nach Punkten und lehnt erst ab 6 Punkten ab. Zu schnelles
Absenden und zu viele Einsendungen reichen allein, alles andere braucht mehrere Treffer. Kein
Captcha, keine fremden Dienste. Installation: nach `user/plugins/spamschutz` entpacken, Cache
leeren. Einstellungen in `user/config/plugins/spamschutz.yaml` oder im Admin.
Tests: `php tests/alle.php` (ohne Composer, Grav wird nachgebildet).

## License

MIT, see [LICENSE](LICENSE).
