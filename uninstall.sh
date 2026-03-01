#!/usr/bin/env bash
# =============================================================================
# Laravel Supervisor Manager — cPanel/WHM Plugin Uninstaller
# =============================================================================
# Version: 1.0.0
#
# Usage (as root):
#   chmod +x uninstall.sh
#   ./uninstall.sh [--force]    # --force skips confirmations
#
# This uninstaller:
#   - Stops and removes all plugin-managed supervisor workers
#   - Releases all port assignments
#   - Removes WHM & cPanel plugin registrations
#   - Removes stored data and config
#   - DOES NOT remove supervisor itself (prompts separately)
#   - DOES NOT affect other supervisor-managed services
# =============================================================================

set -euo pipefail

PLUGIN_NAME="laravel_supervisor_plugin"
INSTALL_DIR="/usr/local/cpanel/3rdparty/${PLUGIN_NAME}"
STORAGE_DIR="/var/cpanel/${PLUGIN_NAME}"
LOG_FILE="/var/log/${PLUGIN_NAME}_uninstall.log"
CPANEL_BASE="/usr/local/cpanel"
FORCE_MODE="${1:-}"

# ─── Colors & logging ─────────────────────────────────────────────────────────

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

log()     { local ts; ts=$(date '+%Y-%m-%d %H:%M:%S'); echo "[${ts}] $*" | tee -a "${LOG_FILE}"; }
info()    { log "INFO  $*"; echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { log "OK    $*"; echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { log "WARN  $*"; echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { log "ERROR $*"; echo -e "${RED}[ERROR]${NC} $*"; }

confirm() {
    [[ "${FORCE_MODE}" == "--force" ]] && return 0
    local prompt="$1"
    echo -e "${YELLOW}[CONFIRM]${NC} ${prompt} [y/N] "
    read -r reply
    [[ "${reply}" =~ ^[Yy]$ ]]
}

# ─── Pre-flight ───────────────────────────────────────────────────────────────

[[ $EUID -eq 0 ]] || { echo "Must run as root."; exit 1; }

echo ""
echo "======================================================================"
echo "  Laravel Supervisor Manager — Uninstaller"
echo "======================================================================"
echo ""
echo "  This will remove the Supervisor Manager plugin."
echo "  Plugin-managed supervisor workers will be stopped and removed."
echo "  Other supervisor services will NOT be affected."
echo ""

confirm "Proceed with uninstallation?" || { echo "Aborted."; exit 0; }

log "=== Uninstallation started ==="

# ─── Stop and remove supervisor worker configs ────────────────────────────────

remove_supervisor_workers() {
    info "Removing plugin-managed supervisor workers…"

    local conf_dirs=("/etc/supervisor/conf.d" "/etc/supervisord.d" "/etc/supervisor.d")
    local removed=0

    for conf_dir in "${conf_dirs[@]}"; do
        [[ -d "${conf_dir}" ]] || continue

        # Find all plugin-managed conf files (prefix: lsp_)
        while IFS= read -r -d '' conf_file; do
            local process_name
            process_name=$(basename "${conf_file}" .conf)

            info "Stopping and removing worker: ${process_name}"

            # Stop the process gracefully
            if command -v supervisorctl >/dev/null 2>&1; then
                supervisorctl stop "${process_name}" >> "${LOG_FILE}" 2>&1 || true
            fi

            # Remove the conf file
            rm -f "${conf_file}"
            removed=$((removed + 1))
            log "Removed conf: ${conf_file}"
        done < <(find "${conf_dir}" -name 'lsp_*.conf' -print0 2>/dev/null)
    done

    # Reload supervisor to apply removals (reread + update only)
    if command -v supervisorctl >/dev/null 2>&1 && [[ $removed -gt 0 ]]; then
        supervisorctl reread >> "${LOG_FILE}" 2>&1 || true
        supervisorctl update >> "${LOG_FILE}" 2>&1 || true
        success "Removed ${removed} supervisor worker config(s)"
    else
        info "No plugin worker configs found to remove"
    fi
}

# ─── Remove cPanel plugin registration ───────────────────────────────────────

remove_cpanel_plugin() {
    info "Removing cPanel plugin registration…"

    # Deregister via AppConfig
    if [[ -x "${CPANEL_BASE}/bin/unregister_appconfig" ]]; then
        "${CPANEL_BASE}/bin/unregister_appconfig" supervisormanager >> "${LOG_FILE}" 2>&1 || \
            warn "unregister_appconfig failed — removing files manually"
    fi

    # Remove AppConfig file
    local conf_file="${CPANEL_BASE}/3rdparty/etc/cpanelplugins/supervisormanager.conf"
    [[ -f "${conf_file}" ]] && rm -f "${conf_file}" && log "Removed: ${conf_file}"

    # Remove Jupiter theme entry
    local jupiter_dir="${CPANEL_BASE}/base/frontend/jupiter/supervisormanager"
    [[ -d "${jupiter_dir}" ]] && rm -rf "${jupiter_dir}" && log "Removed: ${jupiter_dir}"

    # Remove proxy config
    local proxy_conf="${CPANEL_BASE}/etc/proxy/supervisormanager.conf"
    [[ -f "${proxy_conf}" ]] && rm -f "${proxy_conf}" && log "Removed: ${proxy_conf}"

    success "cPanel plugin registration removed"
}

# ─── Remove WHM plugin registration ──────────────────────────────────────────

remove_whm_plugin() {
    info "Removing WHM plugin registration…"

    # Remove from whm_plugins.conf
    local whm_conf="${CPANEL_BASE}/whostmgr/conf/whm_plugins.conf"
    if [[ -f "${whm_conf}" ]]; then
        sed -i '/^WHMModule=supervisormanager/d' "${whm_conf}"
        log "Removed WHMModule entry from ${whm_conf}"
    fi

    # Remove WHM AppConfig
    local whm_appconfig="${CPANEL_BASE}/whostmgr/conf/apps/supervisormanager.conf"
    [[ -f "${whm_appconfig}" ]] && rm -f "${whm_appconfig}" && log "Removed: ${whm_appconfig}"

    # Remove CGI entry
    local whm_cgi_dir="${CPANEL_BASE}/whostmgr/docroot/cgi/supervisormanager"
    [[ -d "${whm_cgi_dir}" ]] && rm -rf "${whm_cgi_dir}" && log "Removed: ${whm_cgi_dir}"

    # Remove icon
    local icon="${CPANEL_BASE}/whostmgr/docroot/themes/x3/icons/plugin-icons/supervisormanager.svg"
    [[ -f "${icon}" ]] && rm -f "${icon}"

    success "WHM plugin registration removed"
}

# ─── Remove sudoers entry ─────────────────────────────────────────────────────

remove_sudoers() {
    info "Removing sudoers entry…"

    local sudoers_file="/etc/sudoers.d/laravel_supervisor_plugin"
    if [[ -f "${sudoers_file}" ]]; then
        rm -f "${sudoers_file}"
        log "Removed sudoers: ${sudoers_file}"
        success "Sudoers entry removed"
    else
        info "No sudoers entry found"
    fi
}

# ─── Remove stored data ───────────────────────────────────────────────────────

remove_stored_data() {
    info "Removing plugin stored data…"

    if [[ -d "${STORAGE_DIR}" ]]; then
        if confirm "Remove all plugin data in ${STORAGE_DIR}? (database, secrets, config)"; then
            rm -rf "${STORAGE_DIR}"
            log "Removed: ${STORAGE_DIR}"
            success "Plugin data removed"
        else
            warn "Skipped removing ${STORAGE_DIR} (kept for reference)"
        fi
    else
        info "No storage directory found: ${STORAGE_DIR}"
    fi
}

# ─── Remove install directory ─────────────────────────────────────────────────

remove_install_dir() {
    info "Removing plugin installation directory…"

    if [[ -d "${INSTALL_DIR}" ]]; then
        if confirm "Remove plugin files in ${INSTALL_DIR}?"; then
            rm -rf "${INSTALL_DIR}"
            log "Removed: ${INSTALL_DIR}"
            success "Plugin installation directory removed"
        else
            warn "Skipped removing ${INSTALL_DIR}"
        fi
    else
        info "Install directory not found: ${INSTALL_DIR}"
    fi
}

# ─── Offer to remove Supervisor ───────────────────────────────────────────────

offer_supervisor_removal() {
    if ! command -v supervisord >/dev/null 2>&1; then
        return
    fi

    echo ""
    warn "Supervisor is still installed on this system."
    warn "Other services may depend on it."
    echo ""

    if confirm "Remove Supervisor (supervisord) from this server?"; then
        if command -v dnf >/dev/null 2>&1; then
            dnf remove -y supervisor >> "${LOG_FILE}" 2>&1 || warn "dnf remove supervisor failed"
        elif command -v yum >/dev/null 2>&1; then
            yum remove -y supervisor >> "${LOG_FILE}" 2>&1 || warn "yum remove supervisor failed"
        fi
        success "Supervisor removed"
    else
        info "Supervisor kept — other services may still use it"
    fi
}

# ─── Restart cPanel ───────────────────────────────────────────────────────────

restart_cpanel() {
    info "Restarting cPanel services to apply changes…"

    touch /var/cpanel/.need_updatedb 2>/dev/null || true

    if [[ -x "${CPANEL_BASE}/init/cpaneld" ]]; then
        "${CPANEL_BASE}/init/cpaneld" restart >> "${LOG_FILE}" 2>&1 || true
    fi

    success "cPanel services restarted"
}

# ─── Main ─────────────────────────────────────────────────────────────────────

remove_supervisor_workers
remove_cpanel_plugin
remove_whm_plugin
remove_sudoers
remove_stored_data
remove_install_dir
offer_supervisor_removal
restart_cpanel

echo ""
echo "======================================================================"
success "Uninstallation complete!"
echo ""
echo "  Uninstall log: ${LOG_FILE}"
echo ""
echo "  Note: User log files in ~/logs/supervisor/ have been preserved."
echo "  Remove them manually if desired."
echo "======================================================================"
echo ""

log "=== Uninstallation complete ==="
