# Setup & Installation

## Prerequisites

| Requirement | Version | Notes |
|-------------|---------|-------|
| EspoCRM | 9.x | Must already be installed and running |
| PHP | 8.3+ | Extensions: `pdo_mysql`, `curl`, `json`, `mbstring` |
| cc-inventory | Any | MySQL database accessible from the EspoCRM server |
| Network | — | EspoCRM server must reach cc-inventory MySQL host on port 3306 (or custom port) |
| Cron | — | Must run `cron.php` every minute as the web server user |

EspoCRM must be fully installed and functional before adding this module.

## Installation

### From a Release ZIP (recommended)

```bash
# Copy the ZIP to your EspoCRM server
scp espocrm-inventory-v1.0.0.zip user@server:/tmp/

# On the server: unzip and install
cd /path/to/espocrm
unzip -o /tmp/espocrm-inventory-v1.0.0.zip
bash scripts/install.sh --espo-path /path/to/espocrm
```

### From Source

```bash
git clone https://github.com/coreconduit/espocrm-inventory.git
cd espocrm-inventory
scripts/install.sh --espo-path /path/to/espocrm
```

### What the Installer Does

1. Copies `custom/Espo/Modules/Inventory/` into EspoCRM's `custom/` directory
2. Copies `client/custom/modules/inventory/` into EspoCRM's `client/custom/` directory
3. Runs `php command.php rebuild` to register metadata, create DB tables, and rebuild caches
4. Runs `php command.php clear-cache`
5. Prints post-installation instructions

### Installer Options

| Flag | Description |
|------|-------------|
| `--espo-path PATH` | **(Required)** Path to EspoCRM root |
| `--skip-rebuild` | Skip the rebuild step (useful when deploying to a staging environment where rebuild runs later) |

## Post-Installation Configuration

### 1. Configure the CC Inventory Database Connection

In EspoCRM: **Admin → Integrations → CC Inventory**

| Field | Description |
|-------|-------------|
| Host | cc-inventory MySQL hostname (default: `localhost`) |
| Port | MySQL port (default: `3306`) |
| Database | cc-inventory database name |
| Username | MySQL user with SELECT access to cc-inventory tables |
| Password | MySQL password |

Click **Save**, then click **Test Connection** to verify. If the test fails:
- Check that the EspoCRM server can reach the MySQL host (try `mysql -h host -u user -p`)
- Verify the user has SELECT privilege on the cc-inventory database
- Check EspoCRM logs: `data/logs/espo.log`

### 2. Enable the Integration

Toggle the **Enabled** switch on the CC Inventory integration page and click **Save**.

### 3. Enable the Scheduled Job

**Admin → Scheduled Jobs** → find **"Inventory: Sync from CC Inventory"** → set status to **Active**.

The default schedule is nightly. Adjust the cron expression to suit your data freshness requirements.

### 4. Run the Initial Sync

**Admin → Integrations → CC Inventory → Sync Now**

This runs a full import of all categories, products, customers, suppliers, orders, POs, and stock adjustments. For large datasets this may take several minutes. Progress is not shown in real time — check `data/logs/espo.log` for details.

### 5. Configure System Cron

EspoCRM requires a system cron job to run scheduled tasks. If not already set up:

```bash
# Add as root or via sudo (use the web server user — www-data on Ubuntu, nginx on RHEL)
crontab -u www-data -e
```

Add:
```
* * * * * php /path/to/espocrm/cron.php > /dev/null 2>&1
```

Verify it's working: check `data/logs/espo.log` for entries from the job runner.

## Upgrading

1. Download the new release ZIP
2. Run the installer with the same `--espo-path`:
   ```bash
   unzip -o espocrm-inventory-v1.1.0.zip
   bash scripts/install.sh --espo-path /path/to/espocrm
   ```
3. The installer overwrites module files and runs rebuild automatically.
4. Check the release notes for any required manual migration steps.

## Uninstalling

1. Remove the module directories:
   ```bash
   rm -rf /path/to/espocrm/custom/Espo/Modules/Inventory
   rm -rf /path/to/espocrm/client/custom/modules/inventory
   ```
2. Rebuild:
   ```bash
   cd /path/to/espocrm
   php command.php rebuild
   php command.php clear-cache
   ```
3. The Inventory entity tables (`inventory_product`, etc.) remain in the database. Drop them manually if needed via MySQL.

## Development Setup

Tests require a local EspoCRM installation for the `Espo\Core\*` namespace:

```bash
# From the espocrm-inventory project root:
ESPO_PATH=/path/to/espocrm \
  /path/to/espocrm/vendor/bin/phpunit \
  --configuration phpunit.xml \
  --no-coverage
```

The test bootstrap (`tests/bootstrap.php`) looks for EspoCRM's `vendor/autoload.php` at `ESPO_PATH`. If `ESPO_PATH` is unset, it defaults to `../espocrm` relative to the project root.

## Building a Release

```bash
scripts/release.sh --version 1.1.0 --espo-path /path/to/espocrm
# Output: releases/espocrm-inventory-v1.1.0.zip
#         releases/espocrm-inventory-v1.1.0.zip.sha256
```

Options:
| Flag | Description |
|------|-------------|
| `--version X.Y.Z` | Release version (defaults to latest git tag) |
| `--espo-path PATH` | Path to EspoCRM (for transpilation and tests) |
| `--skip-tests` | Skip PHPUnit test run |
| `--skip-transpile` | Skip JS transpilation step |

The release script:
1. Validates the version format (must be `X.Y.Z`)
2. Transpiles client JS via EspoCRM's `js/transpile.js`
3. Runs the PHPUnit test suite (if tests exist)
4. Stages all module files, the install script, and docs/
5. Strips dev artifacts (`.map` files, `.gitignore`, test files)
6. Creates a versioned ZIP in `releases/`
7. Generates a SHA-256 checksum file alongside the ZIP
