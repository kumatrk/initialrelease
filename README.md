# Simple KUMA 🐻

A self-hosted affiliate tracking system with a simple, old-school UI. Built with PHP + MySQL for easy deployment on shared hosting or VPS.

## System Requirements

- PHP 8.2 or higher
- MySQL 8.0+ or MariaDB 10.3+ (10.3+ required for JSON generated column migrations)
- Apache/Nginx web server
- Required PHP extensions:
  - mysqli
  - pdo_mysql
  - json
  - mbstring
  - curl
  - openssl
  - fileinfo
  - filter

## Installation

1. **Upload files** to your web server (e.g., `/var/www/html` or `public_html`)

2. **Create a database** via your hosting control panel (cPanel, Plesk, etc.)

3. **Set permissions** (Linux/Unix):
   ```bash
   chmod 755 config storage storage/logs storage/cache
   ```

4. **Visit the installer** in your browser (prefer document root = `public/`):
   ```
   https://yourdomain.com/install.php
   ```
   If the server points at the project root instead, use `/public/install.php`.

5. **Follow the wizard**:
   - Requirements check
   - Database credentials
   - Admin account creation
   - Complete!

## Directory Structure

```
simplekuma/
├── config/           # Configuration files (created during install)
├── database/         # Migration scripts
├── public/           # Web root (point your server here)
│   ├── index.php    # Main application entry
│   └── install.php  # Installation wizard
├── src/              # Application source code
│   └── Installer/   # Installer classes
├── storage/          # Logs, cache, uploads
│   ├── logs/
│   └── cache/
├── vendor/           # Composer dependencies
├── views/            # View templates
└── composer.json     # Dependencies
```

## Apache Configuration

If you're using Apache, make sure `mod_rewrite` is enabled:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Point your document root to the `public/` directory, or use the provided `.htaccess` for automatic routing.

## Releases

**Install from the production zip** on [GitHub Releases](https://github.com/kumatrk/initialrelease/releases) (document root = `public/`). The public GitHub tree mirrors that zip.

See [release-notes.md](release-notes.md) for changelogs. Current version: see [version.php](version.php).

## License

[GNU Affero General Public License v3.0](LICENSE)

## Support

For issues and questions, please refer to the documentation or open an issue on the GitHub repository.


