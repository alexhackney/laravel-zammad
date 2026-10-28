# Contributing

Contributions are welcome and will be fully credited.

Please read and understand the contribution guide before creating an issue or pull request.

## Etiquette

This project is open source, and as such, the maintainers give their free time to build and maintain the source code held within. They make the code freely available in the hope that it will be of use to other developers. It would be extremely unfair for them to suffer abuse or anger for their hard work.

Please be considerate towards maintainers when raising issues or presenting pull requests. Let's show the world that developers are civilized and selfless people.

## Viability

When requesting or submitting new features, first consider whether it might be useful to others. Open source projects are used by many developers, who may have entirely different needs to your own. Think about whether or not your feature is likely to be used by other users of the project.

This package is a Saloon-based client for the Zammad REST API. Business rules, alerting and anything not about talking to Zammad belong in the consuming application.

## Procedure

Before filing an issue:

- Attempt to replicate the problem, to ensure that it wasn't a coincidental incident.
- Check to make sure your feature suggestion isn't already present within the project.
- Check the pull requests tab to ensure that the bug doesn't have a fix in progress.
- Check the pull requests tab to ensure that the feature isn't already in progress.

Before submitting a pull request:

- Check the codebase to ensure that your feature doesn't already exist.
- Check the pull requests to ensure that another person hasn't already submitted the feature or fix.

## Requirements

- **Follow the Laravel Pint `laravel` preset** - run `vendor/bin/pint`.
- **PHPStan must pass** - run `composer analyse`. The level is 8.
- **Add tests** - your patch won't be accepted if it doesn't have tests. Run `composer test`.
- **Never call a live Zammad in tests** - mock with Saloon (`MockClient::global()` or `FakeZammad`), not `Http::fake()`, which does not affect Saloon.
- **Do not invent Zammad responses** - prefer a response captured from a real Zammad (scrub personal data first). A fixture built only from the DTO fields must say so in a comment: `// derived from DTO fields, not a captured Zammad response`.
- **Document any change in behaviour** - make sure the `README.md` and `CHANGELOG.md` are kept up to date.
- **One pull request per feature** - if you want to do more than one thing, send multiple pull requests.
- **Send coherent history** - make sure each individual commit in your pull request is meaningful.
- **Target the `main` branch** - open every pull request against `main`.
- **Plain ASCII** in hand-authored files: no em dashes, curly quotes, ellipsis or invisible characters.

## Pint before every commit

Run Pint before committing so the working tree is already formatted:

```bash
vendor/bin/pint
```

CI runs `pint --test`, PHPStan and the test suite on every pull request and on every push to `main`.

## Releases

Releases are automatic. Every push to `main` runs `.github/workflows/release.yml`: the full test matrix (the same jobs
as a pull request) runs first, and if the push contains a releasable conventional commit the next version is tagged and
published on the GitHub Releases page with generated notes (Packagist picks the tag up through its GitHub webhook).
Nothing is committed back to `main`. Only the newest commit on `main` is ever released; a re-run of an older commit
does nothing.

Merge pull requests with **squash and merge** so the commit subject is the pull request title, and give every
pull request a conventional title (the `pr-title` check enforces it): `type(scope)!: description`.

| Title | Release |
|---|---|
| `feat!: ...` or a `BREAKING CHANGE:` footer in the commit body | major |
| `feat: ...` | minor |
| `fix: ...`, `perf: ...`, `revert: ...` | patch |
| `docs:`, `style:`, `test:`, `build:`, `ci:`, `chore:`, `refactor:` | no release |

The first release is `1.0.0`. Maintenance commits are only counted in the notes, and `[` `]` `<` `>` in titles are
escaped there.

Do not create `vX.Y.Z` tags or releases by hand. The planner refuses to release if the next tag name already exists on
another commit, and only tags whose `composer.json` names this package count as a baseline, so tags inherited from the
upstream project are ignored (delete them from the remote anyway). If a run fails after the tag was created but
before the release was, finish it with `gh release create vX.Y.Z --verify-tag --generate-notes`.

## Running tests

```bash
composer install
vendor/bin/pint
composer analyse
composer test
```

**Happy coding**!
