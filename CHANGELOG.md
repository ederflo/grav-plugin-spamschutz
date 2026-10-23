# v1.1.0
## 10/23/2026

1. [](#new)
    * Everyday-word list is configurable (`alltagswoerter`, `sprachpruefung`), so the plugin works for sites in other languages
    * Log entries expire after `protokoll_tage` days (default 30)
2. [](#improved)
    * Default threshold 6; submitting too fast or too often adds points equal to the threshold and rejects on its own
3. [](#bugfix)
    * `X-Forwarded-For` is only trusted with `proxy_vertrauen: true`; before, clients could fake it to dodge the rate limit

# v1.0.0
## 09/16/2026

1. [](#new)
    * Initial release: points-based scoring of form submissions with log
