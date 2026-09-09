# ModulNest Modules

Official source workspaces, independently versioned module packages, and the
production-signed ModulNest module catalog.

The ModulNest Core is published separately at
[ChobitsChii/ModulNest](https://github.com/ChobitsChii/ModulNest). A module can
be released here without publishing a new Core version.

## Published modules

- Banking
- Dashboard
- DataPortability
- Homepage
- Logs
- News
- Pages
- SneakPreview
- Systeminfo
- Tools
- Wiki

Catalog clients consume `catalog/v1/root.json`. Packages use the stable path
`packages/<module-id>/<version>/<module-id>-<version>.zip`.

Tags use `<module-id>-v<version>`, for example `modulnest.wiki-v1.3.0`.

## Static mirror

`tools/sync-repository-mirror.php` downloads the signed GitHub catalog,
verifies the root signature, catalog sequence, module-index hashes, package
SHA-256 values, and package signatures, then atomically switches a static
DocumentRoot. No production private key is needed on the mirror host.
