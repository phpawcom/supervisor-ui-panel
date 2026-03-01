#!/usr/bin/env bash
# =============================================================================
# Laravel Supervisor Manager — Remote Installer
# =============================================================================
# Version: 1.0.0
# Repo   : https://github.com/phpawcom/supervisor-ui-panel
#
# Run this single command on your cPanel server as root:
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/phpawcom/supervisor-ui-panel/main/remote-install.sh)
#
# Or with wget:
#
#   bash <(wget -qO- https://raw.githubusercontent.com/phpawcom/supervisor-ui-panel/main/remote-install.sh)
#
# What this script does:
#   1. Verifies it is running as root
#   2. Checks for curl/wget and git (or falls back to zip download)
#   3. Downloads the latest release from GitHub
#   4. Runs the bundled install.sh
#   5. Cleans up the temporary download directory
# =============================================================================

set -euo pipefail

# ─── Settings ─────────────────────────────────────────────────────────────────

REPO_URL="https://github.com/phpawcom/supervisor-ui-panel.git"
REPO_ZIP="https://github.com/phpawcom/supervisor-ui-panel/archive/refs/heads/main.zip"
REPO_TAR="https://github.com/phpawcom/supervisor-ui-panel/archive/refs/heads/main.tar.gz"
BRANCH="main"

TMP_DIR=""
LOG_FILE="/var/log/laravel_supervisor_plugin_install.log"

# ─── Colors ───────────────────────────────────────────────────────────────────

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

log()     { local ts; ts=$(date '+%Y-%m-%d %H:%M:%S'); echo "[${ts}] [remote] $*" >> "${LOG_FILE}" 2>/dev/null || true; }
info()    { log "INFO  $*"; echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { log "OK    $*"; echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { log "WARN  $*"; echo -e "${YELLOW}[WARN]${NC}  $*"; }
die()     { log "ERROR $*"; echo -e "${RED}[ERROR]${NC} $*" >&2; exit 1; }

# ─── Cleanup on exit ──────────────────────────────────────────────────────────

cleanup() {
    if [[ -n "${TMP_DIR}" && -d "${TMP_DIR}" ]]; then
        info "Cleaning up temporary directory: ${TMP_DIR}"
        rm -rf "${TMP_DIR}"
    fi
}

trap cleanup EXIT

# ─── Root check ───────────────────────────────────────────────────────────────

[[ $EUID -eq 0 ]] || die "This installer must be run as root. Try: sudo bash remote-install.sh"

# ─── Detect download tool ─────────────────────────────────────────────────────

detect_download_tool() {
    if command -v curl >/dev/null 2>&1; then
        DOWNLOAD_TOOL="curl"
        info "Download tool: curl $(curl --version | head -1 | awk '{print $2}')"
    elif command -v wget >/dev/null 2>&1; then
        DOWNLOAD_TOOL="wget"
        info "Download tool: wget $(wget --version 2>&1 | head -1 | awk '{print $3}')"
    else
        die "Neither curl nor wget is installed. Install one with: dnf install curl"
    fi
}

download_file() {
    local url="$1"
    local dest="$2"

    if [[ "${DOWNLOAD_TOOL}" == "curl" ]]; then
        curl -fsSL --connect-timeout 30 --retry 3 --retry-delay 2 \
             -o "${dest}" "${url}" \
             || die "Download failed: ${url}"
    else
        wget -q --timeout=30 --tries=3 \
             -O "${dest}" "${url}" \
             || die "Download failed: ${url}"
    fi
}

# ─── Check connectivity to GitHub ─────────────────────────────────────────────

check_github_connectivity() {
    info "Verifying connectivity to GitHub…"

    local test_url="https://github.com"

    if [[ "${DOWNLOAD_TOOL}" == "curl" ]]; then
        curl -fsSL --connect-timeout 10 --head "${test_url}" >/dev/null 2>&1 \
            || die "Cannot reach GitHub (${test_url}). Check your server's internet/firewall settings."
    else
        wget -q --timeout=10 --spider "${test_url}" >/dev/null 2>&1 \
            || die "Cannot reach GitHub (${test_url}). Check your server's internet/firewall settings."
    fi

    success "GitHub is reachable"
}

# ─── Download via git clone ───────────────────────────────────────────────────

download_via_git() {
    info "Cloning repository via git…"

    git clone --depth=1 --branch "${BRANCH}" \
        "${REPO_URL}" "${TMP_DIR}/repo" \
        >> "${LOG_FILE}" 2>&1 \
        || return 1

    success "Repository cloned"
    REPO_DIR="${TMP_DIR}/repo"
    return 0
}

# ─── Download via tarball (fallback) ─────────────────────────────────────────

download_via_tarball() {
    info "Downloading repository as tarball (git not available)…"

    local tarball="${TMP_DIR}/repo.tar.gz"

    download_file "${REPO_TAR}" "${tarball}"

    info "Extracting tarball…"
    mkdir -p "${TMP_DIR}/repo"
    tar -xzf "${tarball}" -C "${TMP_DIR}/repo" --strip-components=1 \
        >> "${LOG_FILE}" 2>&1 \
        || die "Failed to extract tarball"

    rm -f "${tarball}"
    success "Repository extracted from tarball"
    REPO_DIR="${TMP_DIR}/repo"
}

# ─── Download via zip (last resort) ──────────────────────────────────────────

download_via_zip() {
    info "Downloading repository as zip (fallback)…"

    local zipfile="${TMP_DIR}/repo.zip"

    download_file "${REPO_ZIP}" "${zipfile}"

    if command -v unzip >/dev/null 2>&1; then
        info "Extracting zip…"
        mkdir -p "${TMP_DIR}/repo"
        unzip -q "${zipfile}" -d "${TMP_DIR}/unzipped" \
            >> "${LOG_FILE}" 2>&1 \
            || die "Failed to unzip"

        # GitHub zip contains a single directory named {repo}-{branch}
        local inner_dir
        inner_dir=$(find "${TMP_DIR}/unzipped" -maxdepth 1 -mindepth 1 -type d | head -1)
        [[ -n "${inner_dir}" ]] || die "Cannot find extracted directory in zip"

        mv "${inner_dir}" "${TMP_DIR}/repo"
        rm -rf "${TMP_DIR}/unzipped" "${zipfile}"

        success "Repository extracted from zip"
        REPO_DIR="${TMP_DIR}/repo"
    else
        die "unzip is not installed. Install with: dnf install unzip"
    fi
}

# ─── Fetch the repository ─────────────────────────────────────────────────────

fetch_repository() {
    TMP_DIR=$(mktemp -d -t supervisor_plugin_XXXXXX)
    info "Temporary directory: ${TMP_DIR}"

    REPO_DIR=""

    # Try methods in order of preference
    if command -v git >/dev/null 2>&1; then
        download_via_git || {
            warn "git clone failed — falling back to tarball download"
            download_via_tarball
        }
    else
        warn "git is not installed — using tarball download"
        download_via_tarball || download_via_zip
    fi

    [[ -d "${REPO_DIR}" ]] || die "Repository directory not found after download"
    [[ -f "${REPO_DIR}/install.sh" ]] || die "install.sh not found in downloaded repository. Check the repo structure."

    success "Repository ready at: ${REPO_DIR}"
}

# ─── Verify the downloaded installer ─────────────────────────────────────────

verify_download() {
    info "Verifying downloaded files…"

    local required_files=(
        "install.sh"
        "artisan"
        "composer.json"
        "app/Services/SupervisorManager.php"
        "scripts/supervisor_helper.php"
        "cpanel-plugin/supervisormanager.conf"
        "whm-plugin/index.cgi"
    )

    local missing=0
    for f in "${required_files[@]}"; do
        if [[ ! -f "${REPO_DIR}/${f}" ]]; then
            warn "Expected file missing from download: ${f}"
            missing=$((missing + 1))
        fi
    done

    if [[ $missing -gt 0 ]]; then
        die "Download appears incomplete (${missing} files missing). Check ${LOG_FILE} for details."
    fi

    success "Downloaded files verified"
}

# ─── Run the bundled installer ────────────────────────────────────────────────

run_installer() {
    info "Launching installer from downloaded repository…"

    chmod +x "${REPO_DIR}/install.sh"

    # Hand off to the main installer, forwarding any extra arguments
    # (e.g. future flags). The installer sets its own SCRIPT_DIR via
    # BASH_SOURCE, so it will find all relative paths correctly.
    bash "${REPO_DIR}/install.sh" "$@"
}

# ─── Main ─────────────────────────────────────────────────────────────────────

main() {
    echo ""
    echo -e "${BOLD}======================================================================"
    echo "  Laravel Supervisor Manager — Remote Installer"
    echo "  Repository: ${REPO_URL}"
    echo -e "======================================================================${NC}"
    echo ""

    log "=== Remote install started ==="

    detect_download_tool
    check_github_connectivity
    fetch_repository
    verify_download
    run_installer "$@"

    # Cleanup is handled by the trap
    log "=== Remote install finished ==="
}

main "$@"
