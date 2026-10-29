# AllStat

**English** | [Čeština](README.cs.md)

[![Latest version](https://img.shields.io/github/v/release/ffscz/AllStat?label=version)](https://github.com/ffscz/AllStat/releases/latest)
[![License GPLv3](https://img.shields.io/github/license/ffscz/AllStat?label=license)](LICENSE)
[![Guide](https://img.shields.io/badge/guide-online-2563eb)](https://ffscz.github.io/AllStat/en/)

![AllStat: website and social media statistics in one place, in your own database](.github/social-preview.en.png)

A self-hosted analytics dashboard (PHP + MySQL) that collects the statistics of your website and social
networks in one place and stores them in **your own database**. It runs on ordinary web hosting with PHP and
MySQL, and it includes a read-only AI connector (MCP) for Claude and ChatGPT. The interface is available in
Czech, Slovak and English.

**Guide to installing, connecting services, connecting AI and updating: https://ffscz.github.io/AllStat/en/**

## Features

- **Data sources:** Google Analytics 4, Google Search Console, Microsoft Clarity, Facebook, Instagram,
  Meta Ads, LinkedIn, YouTube, Google Ads, Microsoft Ads, Seznam Webmaster, CSV import from Meta Business Suite.
- **Multiple websites** under one installation, and for each user you can limit which websites they see.
- **Google accounts:** one or more Google accounts (an agency, clients) and a choice of websites from their
  GA4 and Search Console, with no need to copy Property IDs and Client IDs.
- **All websites** on one page: sessions, key events, revenue and clicks from Google, compared with the
  previous period.
- **Channel growth**, reports, shared links for AI analysis, data feeds.
- **Interface language:** Czech, Slovak and English, and each user chooses their own.
- **AI connection (MCP)** for Claude and ChatGPT: read-only, aggregated data without personal data,
  turned on and revoked by an administrator.
- **Security:** encrypted access tokens (AES-256-GCM), two-factor authentication, audit log.

## Requirements

- PHP 8.1 or newer (8.3 recommended) with the extensions `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json`, `ctype`
- MySQL 5.7.8+ or MariaDB 10.3+
- Apache or LiteSpeed (rules in `.htaccess`), HTTPS
- cron (once a day is enough)

## Installation

1. Download the latest version from [Releases](https://github.com/ffscz/AllStat/releases): under Assets, the file
   `allstat-X.Y.Z.zip`, not “Source code” (and verify it against the `.sha256` file).
2. Unpack it and upload the `allstat` folder to your hosting over FTP.
3. Open the folder’s address in a browser and the installation wizard starts.
4. The wizard creates an `install-token.php` file in the folder. Open it over FTP or in a file manager
   and enter the code from the `KOD:` line into the Installation code field. Without it, nobody else can
   finish the installation.

A detailed guide with screenshots is at [ffscz.github.io/AllStat/en](https://ffscz.github.io/AllStat/en/), always for
the latest version. The whole installation is summarized on one page in the
[checklist](https://ffscz.github.io/AllStat/en/#karta), and the guide can be printed or saved as a PDF.

## Updating

**With one button since version 1.2.0.** AllStat detects a new version by itself (the [`update.json`](update.json) feed),
an administrator sees it in the sidebar and installs it from the Updates page. The package is signed
(ECDSA P-256, the public key is in `lib/updater.php`), and nothing is installed without a valid signature.
The files that get overwritten are backed up, and you can roll back to the previous version.

**Manually (version 1.1.0 and older, or when PHP is not allowed to write to the application folder):** upload the
new version over the existing installation (the same place, overwrite files). The package does not contain the
`config.php` and `config-keys.php` files, so they stay unchanged. The database is completed by itself the first
time you open the application (migrations only add tables and columns, they do not delete data). The procedure is
in [chapter 8 of the guide](https://ffscz.github.io/AllStat/en/#aktualizace).

## Repository

The repository contains exactly what the installation package contains: no credentials or keys.
Each version is a `vX.Y.Z` tag and a release with the installation ZIP and a checksum (up to version 1.2.5 also with
a PDF guide). The guide lives in the `gh-pages` branch and runs on GitHub Pages.
Line endings are not changed in git (`.gitattributes`), so that the checksums in `lib/manifest.json` match.

Report bugs and ideas through [Issues](https://github.com/ffscz/AllStat/issues/new/choose), and the form will ask
for your version and hosting. Please do not report a security vulnerability publicly, but through a
[private vulnerability report](https://github.com/ffscz/AllStat/security/advisories/new), see [SECURITY.md](.github/SECURITY.md).
If you want to contribute code, read [CONTRIBUTING.md](.github/CONTRIBUTING.md) first.

## License

[GNU GPL version 3](LICENSE).
