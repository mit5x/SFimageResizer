# Building the release archive

The distributable plugin is the `sf-image-resizer/` directory and nothing else.
Everything in the repository root (`tests/`, `phpunit.xml.dist`,
`phpcs.xml.dist`, `BUILD.md`, `TEST-REPORT.md`, `README.md`) is development
material and must not be shipped to the WordPress.org plugin directory.

## Release checklist

1. Bump the version in **three** places and keep them identical:
   - `sf-image-resizer/sf-image-resizer.php` — the `Version:` header
   - `sf-image-resizer/sf-image-resizer.php` — the `SFIR_VERSION` constant
   - `sf-image-resizer/readme.txt` — `Stable tag:`
2. Add a `== Changelog ==` entry to `readme.txt`.
3. Regenerate the translation template:
   ```bash
   wp i18n make-pot sf-image-resizer sf-image-resizer/languages/sf-image-resizer.pot --domain=sf-image-resizer
   ```
4. Run the full test suite (see `TEST-REPORT.md` for the commands).
5. Build the archive.

## Building the zip

```bash
cd /path/to/repository
zip -r sf-image-resizer.zip sf-image-resizer \
    -x '*.DS_Store' -x '__MACOSX/*' -x '*/.git/*'
```

The archive must contain exactly one top level directory, `sf-image-resizer/`,
holding:

```
sf-image-resizer/
├── sf-image-resizer.php
├── uninstall.php
├── readme.txt
├── includes/
├── admin/
└── languages/
```

Verify the contents before uploading:

```bash
unzip -l sf-image-resizer.zip
```

There must be no `tests/`, no `node_modules/`, no `vendor/`, no `composer.json`
and no dotfiles in the archive.

## Verifying the archive

Install the zip into a clean WordPress instance and run Plugin Check against
the installed copy:

```bash
wp plugin install sf-image-resizer.zip --activate
wp plugin check sf-image-resizer \
    --categories=general,plugin_repo,security,performance,accessibility \
    --include-experimental
```
