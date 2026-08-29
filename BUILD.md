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
3. If any user-visible string changed, regenerate the translation template,
   update the seven `.po` files and recompile them:
   ```bash
   wp i18n make-pot sf-image-resizer sf-image-resizer/languages/sf-image-resizer.pot --domain=sf-image-resizer
   # translate the new strings in each languages/sf-image-resizer-<locale>.po
   wp i18n make-mo sf-image-resizer/languages
   ```
   A locale whose `.po` is missing a string falls back to English for that
   string only, so a partial translation is never a broken screen — but ship
   them complete.
4. Commit and push. CI runs the unit tests, the coding standards, the full
   WordPress integration suite and Plugin Check on every push.
5. Release it, either way round:

   **From the Actions tab, with no command line.** Open **Actions → Release →
   Run workflow**, pick the branch the commit is on, type the version
   (`1.2.0`, with or without the leading `v`) and press **Run workflow**. The
   workflow creates the `v1.2.0` tag on that branch itself and carries on. This
   is the whole release; nothing has to be tagged beforehand.

   **Or by pushing a tag**, which starts the same workflow:
   ```bash
   git tag v1.2.0
   git push origin v1.2.0
   ```

Either way the workflow then:

* checks that the tag, the two version numbers in the plugin file, the
  `Stable tag:` in `readme.txt` and the changelog entry all agree, and fails
  the release if they do not;
* builds `SFimageResizer-<version>.zip`;
* verifies the archive — it opens, it has exactly one top level directory
  named `sf-image-resizer`, and it carries no `tests/`, `vendor/`,
  `node_modules/`, `composer.json`, `composer.lock` or dotfiles;
* creates the GitHub Release named `SFimageResizer <version>` with the archive
  attached and its sha256 in the notes.

The version check runs *before* the tag is created, so a manual run with a
version that does not match the plugin files fails without leaving a tag
behind. Re-running the workflow for a version that is already tagged reuses
the existing tag and replaces the asset instead of failing.

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
├── docs/
└── languages/
```

`docs/sf-image-resizer.md` is the reference the Documentation tab offers for
download, and `languages/` holds the `.pot` plus a `.po` and a compiled `.mo`
for each translated locale. Both directories ship.

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
