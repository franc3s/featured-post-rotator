# Contributing

Thanks for your interest in Featured Post Rotator.

## Reporting a bug or asking for a feature

Open an [issue](https://github.com/franc3s/featured-post-rotator/issues/new/choose) and pick the matching template. For bugs, include your WordPress and PHP versions, how many slots you use and the steps to reproduce.

Security problems are the exception: follow [SECURITY.md](SECURITY.md) and don't open a public issue.

## Pull requests

- Open an issue first for anything larger than a small fix, so we can agree on the approach.
- Follow the [WordPress PHP coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) used in the existing code.
- Keep the `BMFR_` class prefix and the `bmfr_` option and hook prefix.
- Wrap every user-facing string in a translation function with the `featured-post-rotator` text domain.
- Sanitize input, escape output, and check nonces and capabilities on every admin action.
- Note your change in `CHANGELOG.md`.

The plugin runs on live sites, so changes to rotation logic or data upgrades need to be safe to run more than once.

## License

By contributing, you agree that your contributions are licensed under the GPL-2.0-or-later, the same license as the plugin.
