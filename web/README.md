# Cycling Commons — web app (`web/`)

Symfony 7.4 application serving content pages for `cyclingcommons.org`. Server-rendered HTML via Twig, styles and scripts managed by AssetMapper (no Node build step required).

## Running

From the **repo root**:

```sh
make app-install   # composer install
make app-serve     # php -S 127.0.0.1:8010 -t public
make app-test      # phpunit + phpstan + psalm + cs-fixer + SPDX/licence gates
```

Or directly from `api/`:

```sh
php bin/console <command>
php bin/phpunit
```

## Quality gates

| Tool | Level | Notes |
|------|-------|-------|
| PHPUnit | — | smoke tests for every content route |
| PHPStan | level 6 | `phpstan.dist.neon` |
| Psalm | errorLevel 4 | `psalm.xml` |
| php-cs-fixer | @Symfony | `.php-cs-fixer.dist.php` |
| check-spdx.sh | — | every `.php`/`.twig` must carry an SPDX header |
| check-licenses.sh | — | fails on any copyleft (GPL/LGPL/AGPL/MPL/EUPL) dependency |

CI runs all gates on every push: `.github/workflows/ci-app.yml`. Rector (`make app-rector`) is advisory only — review its diff before committing.

## Configuration

`.env` holds placeholder values only and is committed. Copy the values you need into `web/.env.local` (gitignored) to override locally. Never commit real secrets.

## Licensing

- **App code and UI translations** - [AGPL-3.0-only](../LICENSE): free software. Fork it, run it, sell it. If you run a changed version and let people use it over a network, section 13 says those users get your source too.
- **Data** - [ODbL 1.0](../licenses/COMMONS-DATA-LICENSE.md)
- **Media** - [CC BY-SA 4.0](../licenses/COMMONS-MEDIA-LICENSE.md)
- **Name and logo** - [reserved](../TRADEMARK.md), not open licensed. AGPL v3 section 7(e) allows this.

Each vendored package's licence notice is preserved under `vendor/`.
