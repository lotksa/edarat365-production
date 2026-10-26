#!/bin/sh
# Read-only release/build gate. Does not bootstrap Laravel or access its data.
set -eu

fail() { printf 'PHP security check failed: %s\n' "$*" >&2; exit 1; }
php_exe=$(command -v "${PHP_BIN:-php}") || fail 'PHP executable missing'
"$php_exe" -r 'exit(PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600 && in_array(ini_get("session.upload_progress.enabled"), ["", "0"], true) ? 0 : 1);' \
    || fail 'require PHP 8.5.12+ (8.5 branch) and upload progress disabled at startup'
"$php_exe" -r 'exit(extension_loaded("curl") ? 0 : 1);' || fail 'cURL extension missing'
"$php_exe" -r 'echo "PHP=",PHP_VERSION," upload_progress=",ini_get("session.upload_progress.enabled"),PHP_EOL;'

brotli_enabled=$("$php_exe" -r 'echo (curl_version()["features"] & CURL_VERSION_BROTLI) ? "1" : "0";') \
    || fail 'cannot inspect cURL decoder capabilities'
case "$brotli_enabled" in 0|1) ;; *) fail 'invalid cURL decoder capability result';; esac
if [ "$brotli_enabled" = 1 ]; then
    extension_dir=$("$php_exe" -r 'echo ini_get("extension_dir");')
    linked=$(ldd "$php_exe") || fail 'cannot inspect PHP linked libraries'
    if [ -f "$extension_dir/curl.so" ]; then
        linked="$linked
$(ldd "$extension_dir/curl.so")"
    fi
    decoders=$(printf '%s\n' "$linked" | awk '$1 ~ /^libbrotlidec[.]so/ && $2 == "=>" {print $3}' | sort -u)
    [ -n "$decoders" ] || fail 'Brotli enabled but its decoder package cannot be identified'
    . /etc/os-release
    for decoder in $decoders; do
        decoder=$(readlink -f "$decoder")
        if [ "${ID:-}:${VERSION_ID:-}" = 'debian:13' ] && command -v dpkg-query >/dev/null 2>&1; then
            package=$(dpkg-query -S "$decoder" | sed 's/: \/.*//')
            case "$package" in libbrotli1|libbrotli1:*) ;; *) fail 'unexpected decoder package';; esac
            version=$(dpkg-query -W -f='${Version}' "$package")
            # Debian DSA-6582-1 backports the fix without changing 1.1.0.
            dpkg --compare-versions "$version" ge '1.1.0-2+deb13u1' || fail 'unpatched Debian Brotli'
            verification=$(dpkg -V "$package") || fail 'cannot verify Brotli package files'
            # Official slim images may remove documentation; verify runtime libraries.
            verification=$(printf '%s\n' "$verification" | awk '$NF ~ /^\/(usr\/)?lib(64)?\//')
            [ -z "$verification" ] || fail 'Brotli package files differ from installed package'
        elif [ "${ID:-}" = almalinux ] && command -v rpm >/dev/null 2>&1; then
            package=$(rpm -qf --qf '%{NAME}' "$decoder") || fail 'decoder is not package managed'
            version=$(rpm -q --qf '%{VERSION}' "$package")
            rpm -V "$package" || fail 'Brotli package files differ from installed package'
            if ! "$php_exe" -r 'exit(version_compare($argv[1], "1.2.1", ">=") ? 0 : 1);' "$version"; then
                # Only accept an older native version with recorded vendor/backport evidence.
                rpm -q --changelog "$package" | grep -qi 'CVE-2026-111497' \
                    || fail 'older RPM Brotli has no recorded CVE-2026-111497 fix'
            fi
            version=$(rpm -q "$package")
        else
            fail 'unsupported package manager; native decoder requires manual verification'
        fi
        printf 'BROTLI_FIXED package=%s version=%s library=%s\n' "$package" "$version" "$decoder"
    done
else
    printf 'BROTLI_DECODER_DISABLED\n'
fi
printf 'PHP_RUNTIME_SECURITY_OK\n'
