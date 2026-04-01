# Facet Protection

Protects Drupal sites from bot abuse of faceted search URLs. Bots systematically crawl every combination of facet filter parameters, generating expensive uncached queries and bloating database cache tables.

## What it does

- **Validates facet aliases** — rejects requests using facet parameters that don't match any configured facet on the site (400 response). Valid aliases are cached and auto-invalidate when facet config changes.
- **Throttles faceted requests** — blocks requests with more than 8 facet parameters (429 response)
- **Rate limits per IP** — 30 faceted requests per minute per IP
- **Strips tracking params** — removes `srsltid` and similar params that fragment cache keys
- **Blocks crawling** — appends `robots.txt` rules to disallow faceted URLs via Composer Scaffold

All checks run as lightweight HTTP middleware before Drupal fully bootstraps. Invalid alias requests are rejected before they consume rate limit slots.

## Setup

```bash
composer config --json --merge extra.drupal-scaffold.allowed-packages '["augustash/ash_facet_protection"]' && composer require augustash/ash_facet_protection
```

Then enable the module:

```bash
drush en ash_facet_protection
```
