# Cycling Commons — web app (`api/`)

Symfony 7.4 application serving content pages for `cyclingcommons.org`. Server-rendered HTML via Twig, styles and scripts managed by AssetMapper (no Node build step required).

## Running

From the **repo root**:

```sh
make app-install   # composer install
make app-serve     # php -S 127.0.0.1:8000 -t public
make app-test      # phpunit + phpstan + psalm + SPDX/licence gates
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

`.env` holds placeholder values only and is committed. Copy the values you need into `api/.env.local` (gitignored) to override locally. Never commit real secrets.

## Licensing

- **App code** — [PolyForm Shield 1.0.0](../LICENSE): source-available, non-compete
- **Data** — [ODbL 1.0](../licenses/COMMONS-DATA-LICENSE.md)
- **Media** — [CC BY-SA 4.0](../licenses/COMMONS-MEDIA-LICENSE.md)

Each vendored package's licence notice is preserved under `vendor/`.
