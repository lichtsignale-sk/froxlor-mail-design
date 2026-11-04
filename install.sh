#!/usr/bin/env bash
#
# Installs Mail Design into an existing Froxlor 2.x installation.
#
#   ./install.sh /var/www/froxlor
#   ./install.sh /var/www/froxlor --dry-run            write nothing, only show
#   ./install.sh /var/www/froxlor --skip-migrations    files only, no database
#
# Only new files are copied; no Froxlor file is changed. Run this script
# again after every Froxlor update.

set -euo pipefail

TARGET="${1:-}"
shift || true

DRY_RUN=0
MIGRATIONS=1
for opt in "$@"; do
	case "$opt" in
		--dry-run)         DRY_RUN=1 ;;
		--skip-migrations) MIGRATIONS=0 ;;
		*) echo "Unknown option: $opt" >&2; exit 1 ;;
	esac
done

if [[ -z "$TARGET" ]]; then
	cat >&2 <<'HELP'
Usage: ./install.sh <froxlor-directory> [--dry-run] [--skip-migrations]

Example:
    ./install.sh /var/www/froxlor
HELP
	exit 1
fi

if [[ ! -f "$TARGET/lib/init.php" ]] || [[ ! -d "$TARGET/templates/Froxlor" ]]; then
	echo "ERROR: '$TARGET' does not look like a Froxlor installation." >&2
	exit 1
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo "unknown")"
# shellcheck source=common.sh
. "$HERE/common.sh"
TARGET="$(md_resolve "$TARGET")"
md_safe_path "$TARGET"

if ! command -v php >/dev/null 2>&1; then
	md_die "php (CLI) not found."
fi
if [[ "$(php -r 'echo PHP_VERSION_ID;')" -lt 80100 ]]; then
	md_die "Mail Design needs PHP 8.1 or newer; the php command is $(php -r 'echo PHP_VERSION;')."
fi

# Exactly these files belong to the extension, listed one by one: the script
# can never overwrite or delete anything that belongs to Froxlor, and stray
# files in the working copy (editor backups ...) are never installed.
FILES=(
	"actions/admin/settings/192.ext.maildesign.php"
	"lib/Froxlor/Ext/MailDesign/DbConnection.php"
	"lib/Froxlor/Ext/MailDesign/Lang.php"
	"lib/Froxlor/Ext/MailDesign/LogoStore.php"
	"lib/Froxlor/Ext/MailDesign/Relay.php"
	"lib/Froxlor/Ext/MailDesign/SmtpSession.php"
	"lib/Froxlor/Ext/MailDesign/SmtpSwitch.php"
	"lib/Froxlor/Ext/MailDesign/Styler.php"
	"lib/Froxlor/Ext/MailDesign/mail-relay.php"
	"lib/Froxlor/Ext/MailDesign/lang/de.php"
)

# Check every source and target path before writing anything.
for path in "${FILES[@]}"; do
	[[ -f "$HERE/$path" && ! -L "$HERE/$path" ]] || md_die "missing in the repository: $path"
	md_no_symlinks "$TARGET" "$path"
done
md_no_symlinks "$TARGET" img
md_no_symlinks "$TARGET" cache

echo "Mail Design $VERSION"
echo "Target: $TARGET"
[[ $DRY_RUN -eq 1 ]] && echo "(dry run, nothing is written)"
echo

# Copied with tar instead of rsync: tar is part of every base system, rsync is
# often missing on lean servers. The archive is packed as the caller and
# unpacked as the owner of the target directories (see common.sh), without
# owners and modes from the archive.
echo "Files"
for path in "${FILES[@]}"; do
	if [[ $DRY_RUN -eq 1 ]]; then
		echo "  would copy: $path"
		continue
	fi
	md_writer_for "$TARGET" "$path"
	md_as_owner mkdir -p -- "$TARGET/$(dirname "$path")" </dev/null
	( cd "$HERE" && tar cf - -- "$path" ) \
		| md_as_owner tar -C "$TARGET" --no-same-owner --no-same-permissions -xf -
	echo "  $path"
done

# Twig caches compiled templates; clearing it is cheap and makes sure no stale
# compiled page survives an update. find does not follow symbolic links.
if [[ $DRY_RUN -eq 0 && -d "$TARGET/cache" ]]; then
	md_writer_for "$TARGET" cache
	md_as_owner find "$TARGET/cache" -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + </dev/null || true
	echo "  Twig cache cleared"
fi

# The panel stores the uploaded logo in img/ (like Froxlor's own logos). If the
# directory is missing, the panel often cannot create it under its own user.
if [[ ! -e "$TARGET/img" ]]; then
	if [[ $DRY_RUN -eq 1 ]]; then
		echo "  would create img/"
	else
		md_writer_for "$TARGET" img
		md_as_owner mkdir -- "$TARGET/img" </dev/null
		if [[ $EUID -eq 0 && ${#WRITE_AS[@]} -eq 0 && -f "$TARGET/lib/userdata.inc.php" && ! -L "$TARGET/lib/userdata.inc.php" ]]; then
			# Froxlor's directory belongs to root: give img/ to the panel user.
			chown -h "$(stat -c %u:%g "$TARGET/lib/userdata.inc.php")" "$TARGET/img" 2>/dev/null || true
		fi
		echo "  img/ created"
	fi
fi

# The relay is started per connection by systemd (socket activation), so new
# code is used from the next mail on; nothing to restart.

if [[ $MIGRATIONS -eq 0 ]]; then
	echo
	echo "Database skipped (--skip-migrations)."
	echo "Apply the migrations later with:"
	echo "    php $HERE/migrations/apply.php $TARGET"
	exit 0
fi

echo
echo "Database"

# Via PHP/PDO instead of a MySQL command line client: PHP is present on every
# Froxlor server, a mysql client is not necessarily. It also uses exactly the
# connection options from Froxlor's userdata.inc.php (parsed, not executed),
# including TLS.
if [[ -L "$TARGET/lib/userdata.inc.php" || ! -r "$TARGET/lib/userdata.inc.php" ]]; then
	echo "  ERROR: $TARGET/lib/userdata.inc.php is not readable or a symbolic link." >&2
	echo "  Run the script as root, or use --skip-migrations." >&2
	exit 1
fi

MIG_OPTS=()
[[ $DRY_RUN -eq 1 ]] && MIG_OPTS+=(--dry-run)
md_php migrations/apply.php "$TARGET" "${MIG_OPTS[@]+"${MIG_OPTS[@]}"}"

echo
echo "Done. The settings are under Settings > Mail design."
echo "To route Froxlor's mails through the relay, run once as root:"
echo "    $HERE/mail-design.sh $TARGET on"
echo
echo "Check that no Froxlor file was touched:"
echo "    cd $TARGET && git status"
