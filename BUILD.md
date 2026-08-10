# Releasing

The distributable plugin is the `sf-image-resizer/` directory and nothing else.
Everything in the repository root (`tests/`, `phpunit.xml.dist`,
`phpcs.xml.dist`, `composer.json`, `BUILD.md`, `TEST-REPORT.md`, `README.md`)
is development material and must not be shipped to the WordPress.org plugin
directory.

**The release archive is never committed to the repository.** It is built by
`.github/workflows/release.yml` and attached to the matching GitHub Release.
A zip inside the tree would stay in the git history forever and grow with
every release, so `.gitignore` blocks `download/` and `*.zip`.

## Releasing a new version

1. Bump the version in **three** places and keep them identical:
   - `sf-image-resizer/sf-image-resizer.php` — the `Version:` header
   - `sf-image-resizer/sf-image-resizer.php` — the `SFIR_VERSION` constant
   - `sf-image-resizer/readme.txt` — `Stable tag:`
2. Add a `== Changelog ==` entry for that version to `readme.txt`.
3. Regenerate the translation template:
   ```bash
   wp i18n make-pot sf-image-resizer sf-image-resizer/languages/sf-image-resizer.pot --domain=sf-image-resizer
   ```
4. Commit and push. CI runs the unit tests, the coding standards, the full
   WordPress integration suite and Plugin Check on every push.
5. Tag the commit and push the tag:
   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```

That last push is the whole release. The workflow then:

* checks that the tag, the two version numbers in the plugin file, the
  `Stable tag:` in `readme.txt` and the changelog entry all agree, and fails
  the release if they do not;
* builds `SFimageResizer-<version>.zip`;
* verifies the archive — it opens, it has exactly one top level directory
  named `sf-image-resizer`, and it carries no `tests/`, `vendor/`,
  `node_modules/`, `composer.json`, `composer.lock` or dotfiles;
* creates the GitHub Release named `SFimageResizer <version>` with the archive
  attached and its sha256 in the notes.

Re-running the workflow for an existing tag (Actions → Release → Run workflow,
with the tag name as input) replaces the asset instead of failing.

## Building the archive by hand

Only needed for a local check; the release itself always goes through the
workflow.

```bash
cd /path/to/repository
zip -r SFimageResizer-1.0.0.zip sf-image-resizer \
    -x '*.DS_Store' -x '__MACOSX/*' -x '*/.git/*'
unzip -l SFimageResizer-1.0.0.zip
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

## Verifying an archive

Install it into a WordPress instance and run Plugin Check against the
installed copy:

```bash
wp plugin install SFimageResizer-1.0.0.zip --activate
wp plugin check sf-image-resizer \
    --categories=general,plugin_repo,security,performance,accessibility \
    --include-experimental
```

Install the plugin as a real directory rather than a symlink when you verify
it. A symlinked plugin directory changes how `plugin_dir_url()` resolves, and
a long running dev server can keep a stale realpath cache after you swap one
for the other.

## Publishing to WordPress.org

The GitHub Release asset is the same archive that gets uploaded to the
WordPress.org SVN repository. Nothing else in this repository is published.
