#!/usr/bin/env bash
# =============================================================================
# Laravel Supervisor Manager — cPanel/WHM Plugin Installer
# =============================================================================
# Version: 1.0.0
# Tested on: AlmaLinux 8/9, Rocky Linux 8/9, CloudLinux 7/8/9, CentOS 7
#
# Usage (as root):
#   chmod +x install.sh
#   ./install.sh
#
# The installer is IDEMPOTENT — safe to run multiple times.
# All actions are logged to: /var/log/laravel_supervisor_plugin_install.log
# =============================================================================

set -euo pipefail

# ─── Configuration ────────────────────────────────────────────────────────────

PLUGIN_NAME="laravel_supervisor_plugin"
INSTALL_DIR="/usr/local/cpanel/3rdparty/${PLUGIN_NAME}"
STORAGE_DIR="/var/cpanel/${PLUGIN_NAME}"
LOG_FILE="/var/log/${PLUGIN_NAME}_install.log"
CPANEL_BASE="/usr/local/cpanel"
PLUGIN_SECRET_FILE="${STORAGE_DIR}/plugin_secret"

# Source directory (where this script lives)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ─── Colors & logging ─────────────────────────────────────────────────────────

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

log()     { local ts; ts=$(date '+%Y-%m-%d %H:%M:%S'); echo "[${ts}] $*" | tee -a "${LOG_FILE}"; }
info()    { log "INFO  $*"; echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { log "OK    $*"; echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { log "WARN  $*"; echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { log "ERROR $*"; echo -e "${RED}[ERROR]${NC} $*"; }
die()     { error "$*"; exit 1; }

# ─── Pre-flight checks ────────────────────────────────────────────────────────

preflight_checks() {
    info "Running pre-flight checks…"

    [[ $EUID -eq 0 ]] || die "This installer must be run as root."

    command -v php >/dev/null 2>&1 || die "PHP is not installed or not in PATH."

    # Verify PHP version >= 8.2
    PHP_VER=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;")
    if [[ $(echo "$PHP_VER < 8.2" | bc -l 2>/dev/null || echo 0) -eq 1 ]]; then
        die "PHP 8.2+ required. Found: $PHP_VER"
    fi

    # Check cPanel is installed
    [[ -d "${CPANEL_BASE}" ]] || die "cPanel base directory not found: ${CPANEL_BASE}"
    [[ -f "${CPANEL_BASE}/version" ]] || warn "Cannot verify cPanel version file."

    success "Pre-flight checks passed (PHP ${PHP_VER})"
}

# ─── OS detection ─────────────────────────────────────────────────────────────

detect_os() {
    if [[ -f /etc/os-release ]]; then
        . /etc/os-release
        OS_ID="${ID:-unknown}"
        OS_VERSION="${VERSION_ID:-0}"
    elif [[ -f /etc/redhat-release ]]; then
        OS_ID="rhel"
        OS_VERSION=$(grep -oP '\d+' /etc/redhat-release | head -1)
    else
        OS_ID="unknown"
        OS_VERSION="0"
    fi

    info "Detected OS: ${OS_ID} ${OS_VERSION}"

    # Detect CloudLinux
    if [[ -f /etc/cloudlinux-release ]] || [[ "${OS_ID}" == "cloudlinux" ]]; then
        IS_CLOUDLINUX=1
        info "CloudLinux detected — LVE integration will be enabled"
    else
        IS_CLOUDLINUX=0
    fi

    # Package manager
    if command -v dnf >/dev/null 2>&1; then
        PKG_MGR="dnf"
    elif command -v yum >/dev/null 2>&1; then
        PKG_MGR="yum"
    else
        die "No supported package manager found (dnf/yum)."
    fi
}

# ─── Install system dependencies ─────────────────────────────────────────────

install_dependencies() {
    info "Checking and installing system dependencies…"

    # Install Supervisor
    if ! command -v supervisord >/dev/null 2>&1; then
        info "Installing Supervisor…"
        ${PKG_MGR} install -y supervisor || die "Failed to install supervisor"
        success "Supervisor installed"
    else
        info "Supervisor already installed: $(supervisord --version 2>/dev/null || echo 'unknown version')"
    fi

    # Enable and start Supervisor
    systemctl enable supervisor 2>/dev/null || systemctl enable supervisord 2>/dev/null || true
    systemctl start  supervisor 2>/dev/null || systemctl start  supervisord 2>/dev/null || true

    # Verify supervisorctl is accessible
    if ! command -v supervisorctl >/dev/null 2>&1; then
        warn "supervisorctl not in PATH — plugin will still work if Supervisor is configured correctly"
    fi

    # Ensure supervisor conf.d directory exists
    for CONF_DIR in /etc/supervisor/conf.d /etc/supervisord.d /etc/supervisor.d; do
        if [[ -d "$CONF_DIR" ]]; then
            info "Supervisor conf.d: $CONF_DIR"
            SUPERVISOR_CONF_DIR="$CONF_DIR"
            break
        fi
    done

    if [[ -z "${SUPERVISOR_CONF_DIR:-}" ]]; then
        info "Creating /etc/supervisor/conf.d"
        mkdir -p /etc/supervisor/conf.d
        SUPERVISOR_CONF_DIR="/etc/supervisor/conf.d"

        # Ensure supervisord includes conf.d
        if [[ -f /etc/supervisord.conf ]] && ! grep -q "conf.d" /etc/supervisord.conf; then
            echo $'\n[include]\nfiles = /etc/supervisor/conf.d/*.conf' >> /etc/supervisord.conf
            warn "Added conf.d include to /etc/supervisord.conf — please verify"
        fi
    fi

    # Check required PHP extensions
    PHP_EXTS_NEEDED=(pdo pdo_sqlite json mbstring openssl pcntl posix)
    MISSING_EXTS=()

    for ext in "${PHP_EXTS_NEEDED[@]}"; do
        if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
            MISSING_EXTS+=("$ext")
        fi
    done

    if [[ ${#MISSING_EXTS[@]} -gt 0 ]]; then
        warn "Missing PHP extensions: ${MISSING_EXTS[*]}"
        info "Attempting to install via ${PKG_MGR}…"

        for ext in "${MISSING_EXTS[@]}"; do
            ${PKG_MGR} install -y "php-${ext}" 2>/dev/null || \
            ${PKG_MGR} install -y "php82-php-${ext}" 2>/dev/null || \
            warn "Could not auto-install php-${ext} — install manually"
        done
    fi

    success "Dependencies verified"
}

# ─── Create directory structure ───────────────────────────────────────────────

create_directories() {
    info "Creating plugin directories…"

    # Plugin installation directory
    mkdir -p "${INSTALL_DIR}"
    chmod 755 "${INSTALL_DIR}"

    # Persistent storage
    mkdir -p "${STORAGE_DIR}"
    chmod 750 "${STORAGE_DIR}"
    chown root:root "${STORAGE_DIR}"

    # Database directory
    mkdir -p "${STORAGE_DIR}/database"
    chmod 750 "${STORAGE_DIR}/database"

    # Log and cache directories within the Laravel app
    mkdir -p "${INSTALL_DIR}/storage/logs"
    mkdir -p "${INSTALL_DIR}/storage/framework/cache"
    mkdir -p "${INSTALL_DIR}/storage/framework/sessions"
    mkdir -p "${INSTALL_DIR}/storage/framework/views"

    success "Directories created"
}

# ─── Deploy plugin files ──────────────────────────────────────────────────────

deploy_plugin_files() {
    info "Deploying plugin files to ${INSTALL_DIR}…"

    # Copy the Laravel project to the install directory (if not already there)
    if [[ "$(realpath "${SCRIPT_DIR}")" != "$(realpath "${INSTALL_DIR}")" ]]; then
        rsync -a --exclude='.git' --exclude='node_modules' --exclude='storage/logs/*' \
              "${SCRIPT_DIR}/" "${INSTALL_DIR}/"
    fi

    # Set ownership
    chown -R root:root "${INSTALL_DIR}"
    chmod -R 755 "${INSTALL_DIR}"

    # Laravel storage must be writable by the web user
    find "${INSTALL_DIR}/storage" -type d -exec chmod 775 {} \;
    find "${INSTALL_DIR}/storage" -type f -exec chmod 664 {} \;

    # scripts must be executable
    chmod 700 "${INSTALL_DIR}/scripts/supervisor_helper.php"
    chmod 700 "${INSTALL_DIR}/scripts/generate_token.php"

    # .env setup
    if [[ ! -f "${INSTALL_DIR}/.env" ]]; then
        if [[ -f "${INSTALL_DIR}/.env.example" ]]; then
            cp "${INSTALL_DIR}/.env.example" "${INSTALL_DIR}/.env"
        else
            cat > "${INSTALL_DIR}/.env" <<ENV
APP_NAME="Laravel Supervisor Manager"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost

LOG_CHANNEL=single
LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=${STORAGE_DIR}/database/plugin.sqlite

CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

SUPERVISOR_PLUGIN_STORAGE=${STORAGE_DIR}
SUPERVISOR_CONF_DIR=${SUPERVISOR_CONF_DIR}
SUPERVISOR_HELPER=${INSTALL_DIR}/scripts/supervisor_helper.php

REVERB_PORT_START=20000
REVERB_PORT_END=21000
REVERB_SSL_MODE=auto

CPANEL_PLUGIN_SECRET=$(openssl rand -hex 32)
ENV
        fi
    fi

    # Generate application key
    cd "${INSTALL_DIR}"
    php artisan key:generate --force --no-interaction >> "${LOG_FILE}" 2>&1 || \
        warn "artisan key:generate failed — check manually"

    success "Plugin files deployed"
}

# ─── Database setup ───────────────────────────────────────────────────────────

setup_database() {
    info "Setting up plugin database…"

    local db_file="${STORAGE_DIR}/database/plugin.sqlite"

    # Create SQLite database if it doesn't exist
    if [[ ! -f "${db_file}" ]]; then
        touch "${db_file}"
        chmod 660 "${db_file}"
        info "Created SQLite database: ${db_file}"
    fi

    # Run migrations
    cd "${INSTALL_DIR}"
    php artisan migrate --force --no-interaction >> "${LOG_FILE}" 2>&1 || \
        die "Database migration failed. See ${LOG_FILE}"

    # Cache config for production performance
    php artisan config:cache  --no-interaction >> "${LOG_FILE}" 2>&1 || warn "config:cache failed"
    php artisan route:cache   --no-interaction >> "${LOG_FILE}" 2>&1 || warn "route:cache failed"
    php artisan view:cache    --no-interaction >> "${LOG_FILE}" 2>&1 || warn "view:cache failed"

    success "Database migrations complete"
}

# ─── Generate plugin secret ───────────────────────────────────────────────────

setup_plugin_secret() {
    if [[ ! -f "${PLUGIN_SECRET_FILE}" ]]; then
        info "Generating plugin shared secret…"
        openssl rand -hex 32 > "${PLUGIN_SECRET_FILE}"
        chmod 600 "${PLUGIN_SECRET_FILE}"
        chown root:root "${PLUGIN_SECRET_FILE}"
        success "Plugin secret generated: ${PLUGIN_SECRET_FILE}"
    else
        info "Plugin secret already exists"
    fi

    # Update .env with the secret
    local secret
    secret=$(cat "${PLUGIN_SECRET_FILE}")
    local env_file="${INSTALL_DIR}/.env"

    if grep -q "^CPANEL_PLUGIN_SECRET=" "${env_file}" 2>/dev/null; then
        sed -i "s|^CPANEL_PLUGIN_SECRET=.*|CPANEL_PLUGIN_SECRET=${secret}|" "${env_file}"
    else
        echo "CPANEL_PLUGIN_SECRET=${secret}" >> "${env_file}"
    fi
}

# ─── Setup sudoers ────────────────────────────────────────────────────────────

setup_sudoers() {
    info "Configuring sudoers for privileged helper…"

    local sudoers_file="/etc/sudoers.d/laravel_supervisor_plugin"
    local php_bin
    php_bin=$(command -v php)
    local helper="${INSTALL_DIR}/scripts/supervisor_helper.php"

    # The sudoers rule: allow the nobody/apache/www-data user to run the helper as root
    # cPanel typically runs PHP-FPM as the cPanel user; we need the web user to sudo
    # to root only for this specific script with no arguments other than a base64 payload.
    #
    # Pattern: Cmnd_Alias constrains to exact binary + script path.
    # The base64 argument is variable — we cannot constrain it further here,
    # so the script itself validates all inputs strictly.

    local web_users="nobody,apache,www-data"

    # Try to detect the actual PHP-FPM user for this site
    if id -u cpanel >/dev/null 2>&1; then
        true  # cPanel manages FPM users per account — handled below
    fi

    cat > "${sudoers_file}" <<SUDOERS
# Laravel Supervisor Manager Plugin — Privileged Helper
# Generated by install.sh on $(date)
# DO NOT EDIT MANUALLY

Defaults!${php_bin} !requiretty

# Allow web user to run the supervisor helper as root
# This is the ONLY sudo rule the plugin requires.
${web_users} ALL=(root) NOPASSWD: ${php_bin} ${helper} *
SUDOERS

    chmod 440 "${sudoers_file}"

    # Validate sudoers syntax
    if visudo -c -f "${sudoers_file}" >> "${LOG_FILE}" 2>&1; then
        success "Sudoers configured: ${sudoers_file}"
    else
        warn "Sudoers syntax check failed — please review ${sudoers_file}"
    fi
}

# ─── Register cPanel plugin ───────────────────────────────────────────────────

register_cpanel_plugin() {
    info "Registering cPanel plugin…"

    local jupiter_dir="${CPANEL_BASE}/base/frontend/jupiter/supervisormanager"
    local appconfig_dir="${CPANEL_BASE}/3rdparty/etc/cpanelplugins"

    # Install Jupiter theme entry point
    mkdir -p "${jupiter_dir}"
    cp "${SCRIPT_DIR}/cpanel-plugin/jupiter/index.html" "${jupiter_dir}/index.html"
    chmod 644 "${jupiter_dir}/index.html"
    chown root:root "${jupiter_dir}/index.html"

    # Copy plugin icon (or create a simple SVG placeholder)
    mkdir -p "${jupiter_dir}"
    cat > "${jupiter_dir}/icon.svg" <<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#0064a3">
  <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/>
</svg>
SVG

    # Register via AppConfig if available
    mkdir -p "${appconfig_dir}"
    cp "${SCRIPT_DIR}/cpanel-plugin/supervisormanager.conf" "${appconfig_dir}/supervisormanager.conf"

    # Register via register_appconfig if available
    if [[ -x "${CPANEL_BASE}/bin/register_appconfig" ]]; then
        "${CPANEL_BASE}/bin/register_appconfig" "${appconfig_dir}/supervisormanager.conf" >> "${LOG_FILE}" 2>&1 || \
            warn "register_appconfig failed — plugin may need manual registration"
        success "cPanel AppConfig registered"
    else
        warn "register_appconfig not found — plugin registered via conf file only"
    fi

    # Setup cPanel proxying for the plugin API
    # This routes /cpanel-plugins/supervisormanager/api/* to our Laravel app
    setup_cpanel_proxy

    success "cPanel plugin registered"
}

setup_cpanel_proxy() {
    info "Setting up cPanel proxy configuration…"

    local proxy_conf="${CPANEL_BASE}/etc/proxy/supervisormanager.conf"
    mkdir -p "$(dirname "${proxy_conf}")"

    # cPanel proxy config: route plugin API requests to our Laravel app
    # served via PHP built-in server on a local port, or via FastCGI
    cat > "${proxy_conf}" <<PROXY
# Supervisor Manager Plugin Proxy
# Routes /cpanel-plugins/supervisormanager/api/* to the Laravel backend

location /cpanel-plugins/supervisormanager/ {
    alias ${INSTALL_DIR}/public/;
    try_files \$uri \$uri/ @supervisormanager;
}

location @supervisormanager {
    fastcgi_pass unix:/run/php-fpm/supervisormanager.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/public/index.php;
    fastcgi_param DOCUMENT_ROOT ${INSTALL_DIR}/public;
}
PROXY

    info "Proxy config written: ${proxy_conf}"
    info "Note: Ensure cPanel's web server includes this configuration."
}

# ─── Register WHM plugin ──────────────────────────────────────────────────────

register_whm_plugin() {
    info "Registering WHM plugin…"

    local whm_cgi_dir="${CPANEL_BASE}/whostmgr/docroot/cgi/supervisormanager"
    local whm_conf="${CPANEL_BASE}/whostmgr/conf/whm_plugins.conf"

    mkdir -p "${whm_cgi_dir}"
    cp "${SCRIPT_DIR}/whm-plugin/index.cgi" "${whm_cgi_dir}/index.cgi"
    chmod 755 "${whm_cgi_dir}/index.cgi"
    chown root:root "${whm_cgi_dir}/index.cgi"

    # Copy plugin icon to WHM icon directory
    local icon_dir="${CPANEL_BASE}/whostmgr/docroot/themes/x3/icons/plugin-icons"
    mkdir -p "${icon_dir}"
    cp "${SCRIPT_DIR}/cpanel-plugin/jupiter/icon.svg" "${icon_dir}/supervisormanager.svg" 2>/dev/null || true

    # Register in WHM plugins conf
    if [[ -f "${whm_conf}" ]]; then
        if ! grep -q "WHMModule=supervisormanager" "${whm_conf}"; then
            echo "WHMModule=supervisormanager" >> "${whm_conf}"
            success "Added WHMModule entry to ${whm_conf}"
        else
            info "WHMModule already registered in ${whm_conf}"
        fi
    else
        warn "WHM plugins.conf not found at ${whm_conf} — manual registration may be needed"
    fi

    # Also try the AppConfig registration path
    local whm_appconfig="${CPANEL_BASE}/whostmgr/conf/apps/supervisormanager.conf"
    mkdir -p "$(dirname "${whm_appconfig}")"
    cp "${SCRIPT_DIR}/whm-plugin/supervisormanager.conf" "${whm_appconfig}"

    success "WHM plugin registered"
}

# ─── Composer install / bootstrap ─────────────────────────────────────────────

install_composer() {
    if command -v composer >/dev/null 2>&1; then
        info "Composer already installed: $(composer --version --no-ansi 2>/dev/null | head -1)"
        return 0
    fi

    info "Composer not found — installing globally…"

    # Detect a download tool
    local dl_tool=""
    if command -v curl >/dev/null 2>&1; then
        dl_tool="curl"
    elif command -v wget >/dev/null 2>&1; then
        dl_tool="wget"
    else
        die "Neither curl nor wget is available. Cannot download Composer. Install one with: ${PKG_MGR} install curl"
    fi

    local tmp_dir
    tmp_dir=$(mktemp -d)
    local setup_script="${tmp_dir}/composer-setup.php"
    local expected_sig_url="https://composer.github.io/installer.sig"
    local installer_url="https://getcomposer.org/installer"

    # Download the installer
    info "Downloading Composer installer…"
    if [[ "${dl_tool}" == "curl" ]]; then
        curl -fsSL --connect-timeout 30 --retry 3 \
             -o "${setup_script}" "${installer_url}" \
             >> "${LOG_FILE}" 2>&1 || die "Failed to download Composer installer"

        # Fetch and verify the expected SHA-384 hash
        local expected_sig
        expected_sig=$(curl -fsSL --connect-timeout 10 "${expected_sig_url}" 2>/dev/null || true)
    else
        wget -q --timeout=30 --tries=3 \
             -O "${setup_script}" "${installer_url}" \
             >> "${LOG_FILE}" 2>&1 || die "Failed to download Composer installer"

        local expected_sig
        expected_sig=$(wget -qO- --timeout=10 "${expected_sig_url}" 2>/dev/null || true)
    fi

    # Verify installer integrity (skip silently if hash fetch failed — network issues)
    if [[ -n "${expected_sig}" ]]; then
        local actual_sig
        actual_sig=$(php -r "echo hash_file('sha384','${setup_script}');")
        if [[ "${actual_sig}" != "${expected_sig}" ]]; then
            rm -rf "${tmp_dir}"
            die "Composer installer integrity check failed. Expected: ${expected_sig} Got: ${actual_sig}"
        fi
        info "Composer installer integrity verified"
    else
        warn "Could not fetch Composer installer signature — skipping hash verification"
    fi

    # Run the installer
    info "Running Composer installer…"
    php "${setup_script}" \
        --install-dir=/usr/local/bin \
        --filename=composer \
        --quiet \
        >> "${LOG_FILE}" 2>&1 || die "Composer installer failed. See ${LOG_FILE}"

    rm -rf "${tmp_dir}"

    # Confirm it worked
    command -v composer >/dev/null 2>&1 \
        || die "Composer installation succeeded but 'composer' is still not in PATH. Check /usr/local/bin."

    success "Composer installed: $(composer --version --no-ansi 2>/dev/null | head -1)"
}

run_composer() {
    info "Installing PHP dependencies…"
    cd "${INSTALL_DIR}"

    COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev --optimize-autoloader --no-interaction \
        >> "${LOG_FILE}" 2>&1 || die "Composer install failed. See ${LOG_FILE}"

    success "Composer dependencies installed"
}

# ─── Restart cPanel services ──────────────────────────────────────────────────

restart_cpanel_services() {
    info "Restarting required cPanel services…"

    # Restart cPanel's tailwatch (handles plugin registration)
    if [[ -x "${CPANEL_BASE}/init/cpaneld" ]]; then
        /usr/local/cpanel/init/cpaneld restart >> "${LOG_FILE}" 2>&1 || true
    fi

    # Touch the updatedb trigger
    touch /var/cpanel/.need_updatedb 2>/dev/null || true

    # Reload supervisor config (do NOT restart the whole service)
    if command -v supervisorctl >/dev/null 2>&1; then
        supervisorctl reread >> "${LOG_FILE}" 2>&1 || true
        supervisorctl update >> "${LOG_FILE}" 2>&1 || true
    fi

    success "Services reloaded"
}

# ─── Verify installation ──────────────────────────────────────────────────────

verify_installation() {
    info "Verifying installation…"
    local errors=0

    [[ -d "${INSTALL_DIR}" ]]              || { error "Install dir missing: ${INSTALL_DIR}";        errors=$((errors+1)); }
    [[ -f "${INSTALL_DIR}/.env" ]]         || { error ".env missing";                               errors=$((errors+1)); }
    [[ -f "${PLUGIN_SECRET_FILE}" ]]       || { error "Plugin secret missing";                      errors=$((errors+1)); }
    [[ -f "${INSTALL_DIR}/scripts/supervisor_helper.php" ]] || { error "Helper script missing";     errors=$((errors+1)); }
    [[ -x "${INSTALL_DIR}/scripts/supervisor_helper.php" ]] || { error "Helper not executable";     errors=$((errors+1)); }

    # Test artisan
    cd "${INSTALL_DIR}"
    if php artisan --version >> "${LOG_FILE}" 2>&1; then
        success "Laravel artisan: OK"
    else
        error "artisan command failed"; errors=$((errors+1))
    fi

    if [[ $errors -eq 0 ]]; then
        success "Installation verified — no errors"
    else
        warn "Installation completed with ${errors} warning(s). Review ${LOG_FILE}"
    fi
}

# ─── Main ─────────────────────────────────────────────────────────────────────

main() {
    echo ""
    echo "======================================================================"
    echo "  Laravel Supervisor Manager — cPanel/WHM Plugin Installer v1.0.0"
    echo "======================================================================"
    echo ""

    log "=== Installation started ==="

    preflight_checks
    detect_os
    install_dependencies
    install_composer
    create_directories
    deploy_plugin_files
    run_composer
    setup_database
    setup_plugin_secret
    setup_sudoers
    register_cpanel_plugin
    register_whm_plugin
    restart_cpanel_services
    verify_installation

    echo ""
    echo "======================================================================"
    success "Installation complete!"
    echo ""
    echo "  Plugin installed at : ${INSTALL_DIR}"
    echo "  Storage directory   : ${STORAGE_DIR}"
    echo "  Install log         : ${LOG_FILE}"
    echo ""
    echo "  cPanel plugin URL   : https://<server>:2083/frontend/jupiter/supervisormanager/"
    echo "  WHM plugin URL      : https://<server>:2087/cgi/supervisormanager/"
    echo ""
    echo "  Next steps:"
    echo "  1. Configure package limits in WHM → Supervisor Manager"
    echo "  2. Configure Reverb port range if needed (default: 20000–21000)"
    echo "  3. Users can access the plugin from their cPanel home"
    echo "======================================================================"
    echo ""

    log "=== Installation complete ==="
}

main "$@"
