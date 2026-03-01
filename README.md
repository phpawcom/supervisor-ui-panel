# Laravel Supervisor Manager — cPanel/WHM Plugin

A production-grade cPanel + WHM plugin that enables cPanel users to manage **Laravel Supervisor workers** (Queue, Scheduler, Reverb WebSocket) with strict per-package limits enforced by WHM administrators.

---

## Architecture

```
supervisor-ui-panel/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── CPanel/
│   │   │   │   ├── DashboardController.php    # Overview, LVE, status polling
│   │   │   │   └── WorkerController.php       # CRUD workers, process control
│   │   │   └── WHM/
│   │   │       ├── PackageController.php      # Per-package limit management
│   │   │       └── SettingsController.php     # Global settings, port admin
│   │   └── Middleware/
│   │       ├── CpanelAuthMiddleware.php       # cPanel session/token validation
│   │       ├── WhmAuthMiddleware.php          # WHM root/reseller validation
│   │       └── EnsureAccountIsolation.php     # Prevent cross-user access
│   ├── Models/
│   │   ├── ManagedLaravelApp.php              # Registered Laravel apps per user
│   │   ├── SupervisorWorker.php               # Worker definitions + conf paths
│   │   ├── AssignedPort.php                   # Allocated Reverb ports
│   │   └── PackageLimit.php                   # WHM package limit definitions
│   └── Services/
│       ├── SupervisorManager.php              # Worker lifecycle, supervisorctl
│       ├── PortAllocator.php                  # Reverb port reservation engine
│       ├── PackageLimitResolver.php           # WHM package limit enforcement
│       ├── LaravelAppDetector.php             # Auto-detect Laravel apps
│       ├── LVEResourceMonitor.php             # CloudLinux LVE integration
│       └── ReverbConfigurator.php             # Reverb command + URL generation
├── config/
│   └── supervisor_plugin.php                  # All plugin configuration
├── database/migrations/
│   ├── *_create_managed_laravel_apps_table.php
│   ├── *_create_supervisor_workers_table.php
│   ├── *_create_assigned_ports_table.php
│   └── *_create_package_limits_table.php
├── resources/
│   ├── views/
│   │   ├── cpanel/                            # Jupiter theme-compatible views
│   │   │   ├── layout.blade.php
│   │   │   ├── dashboard.blade.php
│   │   │   └── workers/{index,create,show}.blade.php
│   │   └── whm/                              # WHM admin views
│   │       ├── layout.blade.php
│   │       ├── packages/{index,edit}.blade.php
│   │       └── settings/index.blade.php
│   └── supervisor/
│       └── worker.conf.template              # Supervisor conf template
├── routes/
│   ├── cpanel.php                            # cPanel user routes
│   └── whm.php                              # WHM admin routes
├── scripts/
│   ├── supervisor_helper.php                 # Root-only privileged helper
│   └── generate_token.php                   # Auth token generator
├── cpanel-plugin/
│   ├── supervisormanager.conf                # AppConfig registration
│   └── jupiter/index.html                   # Jupiter theme entry point
├── whm-plugin/
│   ├── supervisormanager.conf                # WHM plugin registration
│   └── index.cgi                            # WHM Perl CGI entry point
├── install.sh                               # Automated root installer
└── uninstall.sh                             # Safe uninstaller
```

---

## Installation

### Requirements

| Component | Version |
|-----------|---------|
| OS | AlmaLinux 8/9, Rocky Linux 8/9, CloudLinux 7/8/9 |
| cPanel | 108+ |
| PHP | 8.2+ |
| Supervisor | 4.0+ |
| Composer | 2.x |

### Quick Install (one command)

SSH into your cPanel server as root and run:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/phpawcom/supervisor-ui-panel/main/remote-install.sh)
```

Or with `wget` if `curl` is not available:

```bash
bash <(wget -qO- https://raw.githubusercontent.com/phpawcom/supervisor-ui-panel/main/remote-install.sh)
```

That's it. The remote installer will clone the repository from GitHub, verify the download, and run the full installer automatically. No manual file copying required.

### Manual Install (alternative)

If the server has no internet access or you prefer a manual approach:

```bash
# 1. Upload the plugin to the server
scp -r supervisor-ui-panel/ root@yourserver:/tmp/

# 2. Run the installer as root
ssh root@yourserver
cd /tmp/supervisor-ui-panel
chmod +x install.sh
./install.sh
```

All installs are **idempotent** — safe to run multiple times. All actions are logged to `/var/log/laravel_supervisor_plugin_install.log`.

### What the installer does

1. Detects OS (AlmaLinux, Rocky, CloudLinux, CentOS)
2. Installs Supervisor if missing (`dnf install supervisor`)
3. Checks required PHP extensions; installs missing ones
4. Creates `/var/cpanel/laravel_supervisor_plugin/` storage
5. Deploys plugin files to `/usr/local/cpanel/3rdparty/laravel_supervisor_plugin/`
6. Runs `composer install --no-dev`
7. Generates application key and runs database migrations (SQLite)
8. Generates a cryptographic shared secret for plugin tokens
9. Configures sudoers for the privileged helper (`supervisor_helper.php`)
10. Registers cPanel plugin (Jupiter theme AppConfig)
11. Registers WHM plugin (CGI entry + plugins.conf entry)
12. Restarts cPanel's tailwatch daemon

---

## Security Architecture

### Authentication

**cPanel Users** authenticate via one of:
1. Signed HMAC plugin token (generated at page load by cPanel's template)
2. cPanel UAPI token (`Authorization: cpanel user:token`)
3. `REMOTE_USER` server variable (set by cPanel's web server for CGI)

**WHM Admins** authenticate via:
1. Signed HMAC admin token (role `whm_admin`)
2. WHM root access hash (`Authorization: WHM root:<hash>`)
3. `REMOTE_USER=root` (set by WHM's web server)

All tokens are short-lived (1 hour), HMAC-signed with a server-side secret, and include expiry timestamps.

### Account Isolation

- `EnsureAccountIsolation` middleware prevents any user from accessing another user's resources
- Route model binding is validated against the authenticated user
- All shell commands use `escapeshellarg()`
- Path validation uses `realpath()` to prevent symlink escape and path traversal
- Only paths under `/home/{user}/` are accepted as valid app paths
- Symlinks in scanned directories are skipped during app detection

### Privileged Operations (Root)

All root-level operations are isolated in `scripts/supervisor_helper.php`:
- Owned by `root`, chmod `700`
- Invoked only via a tightly-scoped `sudo` rule
- Validates **all** inputs before execution:
  - Conf paths must be in `/etc/supervisor/conf.d/`
  - Log dirs must be under `/home/{user}/logs/supervisor/`
  - Process names must match `lsp_*` pattern (plugin namespace)
  - Action names are whitelisted

### Supervisor Namespace

All plugin-managed processes use the `lsp_` prefix:
```
lsp_{user}_{app}_{type}_{index}
```
This prevents the plugin from accidentally controlling unrelated supervisor processes.

---

## Configuration

### `config/supervisor_plugin.php`

| Key | Default | Description |
|-----|---------|-------------|
| `reverb.port_range_start` | `20000` | First port in Reverb allocation range |
| `reverb.port_range_end` | `21000` | Last port in allocation range |
| `reverb.reserved_ports` | `[]` | Ports to skip during allocation |
| `reverb.ssl_mode` | `auto` | `auto`, `force_https`, `force_http` |
| `default_limits.max_workers_total` | `3` | Default total worker limit |
| `default_limits.max_queue_workers` | `2` | Default queue worker limit |
| `default_limits.max_scheduler_workers` | `1` | Default scheduler limit |
| `default_limits.max_reverb_workers` | `0` | Default Reverb limit (disabled) |
| `default_limits.reverb_enabled` | `false` | Reverb disabled by default |
| `lve.enabled` | `true` | Enable LVE integration |

### `.env` Variables

```env
DB_CONNECTION=sqlite
DB_DATABASE=/var/cpanel/laravel_supervisor_plugin/database/plugin.sqlite

SUPERVISOR_PLUGIN_STORAGE=/var/cpanel/laravel_supervisor_plugin
SUPERVISOR_CONF_DIR=/etc/supervisor/conf.d
SUPERVISOR_HELPER=/usr/local/cpanel/3rdparty/laravel_supervisor_plugin/scripts/supervisor_helper.php

REVERB_PORT_START=20000
REVERB_PORT_END=21000
REVERB_SSL_MODE=auto

CPANEL_PLUGIN_SECRET=<auto-generated-64-char-hex>
```

---

## WHM Administration

### Setting Package Limits

1. Log in to WHM
2. Navigate to **Supervisor Manager** in the plugins menu
3. Click **Package Limits**
4. Find the hosting package to configure
5. Click **Edit** and set:
   - Total worker limit
   - Per-type limits (queue, scheduler, Reverb)
   - Enable/disable Reverb
   - Enable/disable multi-app support

### Reverb Port Management

- Configure the global port range in **WHM → Supervisor Manager → Settings**
- View all allocated ports in the **Port Manager** tab
- Ports are automatically allocated from the lowest available in the range
- Ports remain assigned until the worker is deleted
- Force-release orphaned ports from the WHM admin UI

### Global Settings

| Setting | Description |
|---------|-------------|
| Port Range | The range of TCP ports available for Reverb workers |
| Reserved Ports | Ports to exclude (e.g., ports used by other services) |
| SSL Mode | Controls automatic wss:// vs ws:// selection |

---

## CloudLinux LVE Notes

When CloudLinux is detected (via `/proc/lve/list` and `/usr/sbin/lvectl`):

- CPU and Memory usage is displayed in the cPanel dashboard
- A warning is shown if CPU usage exceeds 80% of LVE limit
- A blocking warning is shown if Memory usage exceeds 80% of LVE limit
- LVE data is cached for 10 seconds to prevent excessive `lvectl` calls
- LVE limits are cached for 60 seconds

If CloudLinux is **not** detected:
- LVE UI elements are hidden
- No errors are displayed to users
- Plugin functions normally

---

## Reverb SSL Notes

The `ssl_mode` setting in `config/supervisor_plugin.php` controls WebSocket protocol selection:

| Mode | Behavior |
|------|----------|
| `auto` | Checks for SSL certificate at `/var/cpanel/ssl/installed/certs/{domain}.crt` and via socket test. Uses `wss://` if found. |
| `force_https` | Always uses `wss://`, regardless of certificate status |
| `force_http` | Always uses `ws://`, regardless of certificate status |

SSL certificate detection checks:
1. `/var/cpanel/ssl/installed/certs/{domain}.crt`
2. `/etc/letsencrypt/live/{domain}/fullchain.pem`
3. TCP socket test on port 443 with `verify_peer=false`

After SSL changes (certificate install/renewal), refresh SSL status via WHM admin or wait for next worker creation.

---

## Worker Configuration Reference

### Queue Worker

```
artisan queue:work --queue={connection} --tries={tries} --timeout={timeout} --memory={memory}
```

| Option | Default | Description |
|--------|---------|-------------|
| Connection | `default` | Queue connection from `config/queue.php` |
| Tries | `3` | Max attempts before marking failed |
| Timeout | `60s` | Job timeout in seconds |
| Memory | `128MB` | Worker restarts if exceeded |
| Numprocs | `1` | Number of parallel workers |

### Scheduler Worker

```
artisan schedule:work
```
Runs the scheduler every minute. Only one scheduler per app is typically needed.

### Reverb WebSocket Worker

```
artisan reverb:start --host=0.0.0.0 --port={port} [--tls]
```

Requires `laravel/reverb` installed in the Laravel app. The port is automatically allocated from the configured range.

---

## Upgrade Guide

```bash
# 1. Download the new version
# 2. Run the installer — it is idempotent and will update files
./install.sh

# Migrations are run automatically
# Config cache is refreshed automatically
```

Manual upgrade steps if needed:

```bash
cd /usr/local/cpanel/3rdparty/laravel_supervisor_plugin
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Troubleshooting

### "supervisorctl not found"

```bash
which supervisorctl
# If not found:
dnf install supervisor
systemctl enable --now supervisord
```

### Workers not starting

1. Check the error log: `tail -50 /home/{user}/logs/supervisor/{process}_error.log`
2. Check supervisor logs: `journalctl -u supervisord -n 50`
3. Test the command manually as the user: `sudo -u {user} php /home/{user}/app/artisan queue:work`

### "Access denied" errors in plugin

1. Verify the sudoers rule exists: `cat /etc/sudoers.d/laravel_supervisor_plugin`
2. Verify the helper is executable: `ls -la /usr/local/cpanel/3rdparty/laravel_supervisor_plugin/scripts/`
3. Test manually: `sudo php /path/to/supervisor_helper.php $(echo '{"action":"supervisor_status","params":{}}' | base64 -w0)`

### Port allocation failures

1. Check port range: `cat /etc/supervisord.conf` (verify no conflicts)
2. View allocated ports: WHM → Supervisor Manager → Port Manager
3. Release orphaned ports from the WHM admin UI

### Database errors

```bash
cd /usr/local/cpanel/3rdparty/laravel_supervisor_plugin
php artisan migrate:status
php artisan migrate --force
```

### cPanel plugin not appearing

```bash
/usr/local/cpanel/bin/register_appconfig /usr/local/cpanel/3rdparty/etc/cpanelplugins/supervisormanager.conf
/usr/local/cpanel/init/cpaneld restart
```

---

## Uninstallation

```bash
cd /usr/local/cpanel/3rdparty/laravel_supervisor_plugin
chmod +x uninstall.sh
./uninstall.sh
```

Or for unattended removal:

```bash
./uninstall.sh --force
```

The uninstaller will:
- Stop all plugin-managed workers (only `lsp_*` prefixed processes)
- Remove all `lsp_*.conf` files from supervisor conf.d
- Remove cPanel and WHM plugin registrations
- Prompt before removing stored data
- Prompt before removing the install directory
- Prompt before removing Supervisor itself

User log files in `~/logs/supervisor/` are **preserved**.

---

## License

GNU General Public License v3.0 — see the `LICENSE` file.

---

## Credits

Built with [Claude Code](https://claude.ai/claude-code) by Anthropic.
