#!/usr/bin/env bash
#
# Removes Mail Design from a Froxlor installation.
#
#   ./uninstall.sh /var/www/froxlor            remove the files, keep the settings
#   ./uninstall.sh /var/www/froxlor --purge    also delete the settings
#
# In both cases Froxlor's SMTP settings are restored to what they were before
# "mail-design.sh ... on", the relay's systemd units are removed and the
# uploaded logo is deleted.

set -euo pipefail

TARGET="${1:-}"
PURGE=0
[[ "${2:-}" == "--purge" ]] && PURGE=1

if [[ -z "$TARGET" ]] || [[ ! -f "$TARGET/lib/init.php" ]]; then
	echo "Usage: ./uninstall.sh <froxlor-directory> [--purge]" >&2
	exit 1
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=common.sh
. "$HERE/common.sh"
TARGET="$(md_resolve "$TARGET")"

FILES=(
	"actions/admin/settings/192.ext.maildesign.php"
	"lib/Froxlor/Ext/MailDesign"
)
for path in "${FILES[@]}" img cache; do
	md_no_symlinks "$TARGET" "$path"
done

# Switch the relay off first, while it and the scripts are still there:
# otherwise Froxlor's SMTP settings would point at a port nobody listens on.
if command -v php >/dev/null 2>&1 && [[ -r "$TARGET/lib/userdata.inc.php" ]]; then
	bash "$HERE/mail-design.sh" "$TARGET" off || true
fi

# Everything below is removed by the owner of the directories involved (see
# md_writer_for in common.sh) and never through a symbolic link.
echo "Removing files"
for path in "${FILES[@]}"; do
	if [[ -e "$TARGET/$path" ]]; then
		md_writer_for "$TARGET" "$path"
		md_as_owner rm -rf -- "${TARGET:?}/$path" </dev/null
		echo "  removed: $path"
	fi
done
# lib/Froxlor/Ext/ may be shared with other extensions: only removed when empty.
if [[ -d "$TARGET/lib/Froxlor/Ext" ]]; then
	md_writer_for "$TARGET" lib/Froxlor/Ext
	md_as_owner rmdir -- "$TARGET/lib/Froxlor/Ext" </dev/null >/dev/null 2>&1 || true
fi

# Uploaded logo. img/ itself is left alone unless it is empty.
if [[ -d "$TARGET/img" ]]; then
	md_writer_for "$TARGET" img
	md_as_owner rm -f -- "$TARGET/img/mail-logo.png" "$TARGET/img/mail-logo.jpg" "$TARGET/img/mail-logo.gif" </dev/null
	# install.sh creates img/ if it is missing; remove it again if nothing else is in it.
	md_as_owner rmdir -- "$TARGET/img" </dev/null >/dev/null 2>&1 || true
fi

if [[ -d "$TARGET/cache" ]]; then
	md_writer_for "$TARGET" cache
	md_as_owner find "$TARGET/cache" -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + </dev/null || true
fi

echo
echo "Database"
if command -v php >/dev/null 2>&1 && [[ -r "$TARGET/lib/userdata.inc.php" ]]; then
	if [[ $PURGE -eq 1 ]]; then
		md_php migrations/remove.php "$TARGET" --purge
	else
		md_php migrations/remove.php "$TARGET"
	fi
else
	echo "  Credentials not readable or php missing. If Froxlor's SMTP settings" >&2
	echo "  (Settings > System > Mail) still point at 127.0.0.1:2530, change them by hand." >&2
fi

echo
echo "Done."
