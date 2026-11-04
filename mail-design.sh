#!/usr/bin/env bash
#
# Switches Mail Design on or off (as root).
#
#   ./mail-design.sh /var/www/froxlor on [--user NAME] [--allow-user NAME]...
#   ./mail-design.sh /var/www/froxlor off
#   ./mail-design.sh /var/www/froxlor status
#
# "on" installs two systemd units and points Froxlor's own SMTP settings at
# the relay, after backing up the previous values:
#   froxlor-mail-design.socket      systemd listens on 127.0.0.1:2530 itself
#   froxlor-mail-design@.service    one relay process per connection
# Because systemd holds the port permanently (from boot on, across restarts),
# no local user can take it over and receive the panel's mails or its SMTP
# password. "off" restores the SMTP settings and removes the units. No
# Froxlor file is changed.
#
# The relay runs as the owner of Froxlor's lib/userdata.inc.php. --user is
# only accepted when that file belongs to root (the user must be able to read
# it). The relay never runs as root. Connections are only served for root
# (Froxlor's cron), the relay user (the panel) and users given with
# --allow-user (e.g. a different PHP-FPM user of the panel).

set -euo pipefail

TARGET="${1:-}"
ACTION="${2:-status}"
if [[ $# -ge 2 ]]; then shift 2; else shift $#; fi
RUN_USER=""
ALLOW_USERS=()
while [[ $# -gt 0 ]]; do
	case "$1" in
		--user|--allow-user)
			[[ $# -ge 2 && -n "$2" ]] || { echo "$1 needs a user name." >&2; exit 1; }
			if [[ "$1" == "--user" ]]; then RUN_USER="$2"; else ALLOW_USERS+=("$2"); fi
			shift 2 ;;
		*) echo "Unknown option: $1" >&2; exit 1 ;;
	esac
done

PORT=2530
NAME=froxlor-mail-design
UNIT_DIR=/etc/systemd/system
SOCKET_UNIT="$UNIT_DIR/$NAME.socket"
SERVICE_UNIT="$UNIT_DIR/$NAME@.service"
LEGACY_UNIT="$UNIT_DIR/$NAME.service"

if [[ -z "$TARGET" ]] || [[ ! -f "$TARGET/lib/init.php" ]]; then
	echo "Usage: ./mail-design.sh <froxlor-directory> on|off|status [--user NAME] [--allow-user NAME]..." >&2
	exit 1
fi
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
# shellcheck source=common.sh
. "$HERE/common.sh"
TARGET="$(md_resolve "$TARGET")"
USERDATA="$TARGET/lib/userdata.inc.php"
CODE_DIR="$TARGET/lib/Froxlor/Ext/MailDesign"
RELAY="$CODE_DIR/mail-relay.php"
command -v php >/dev/null 2>&1 || md_die "php (CLI) not found."

# Removes the units (and the older single-service form, if present).
remove_units() {
	systemctl disable --now "$NAME.socket" >/dev/null 2>&1 || true
	systemctl stop "$NAME@*.service" >/dev/null 2>&1 || true
	if [[ -f "$LEGACY_UNIT" ]]; then
		systemctl disable --now "$NAME.service" >/dev/null 2>&1 || true
	fi
	rm -f -- "$SOCKET_UNIT" "$SERVICE_UNIT" "$LEGACY_UNIT"
	systemctl daemon-reload
	systemctl reset-failed "$NAME@*.service" >/dev/null 2>&1 || true
}

# write_unit <path>: content from stdin; written by root into a temporary file
# next to the target and renamed, mode 0644.
write_unit() {
	local tmp
	tmp="$(mktemp "$1.XXXXXX")"
	cat > "$tmp"
	chmod 0644 "$tmp"
	mv -f -- "$tmp" "$1"
}

# uid_name <uid>: user name of a uid.
uid_name() {
	getent passwd "$1" | cut -d: -f1
}

# can_read <uid> <file>: by the mode bits (the file is not executed as that user).
can_read() {
	local uid="$1" file="$2" fuid fgid mode
	read -r fuid fgid mode < <(stat -c '%u %g %a' -- "$file")
	if [[ "$fuid" -eq "$uid" ]]; then
		[[ $(( 8#$mode & 8#400 )) -ne 0 ]]
	elif id -G -- "$(uid_name "$uid")" | tr ' ' '\n' | grep -qx -- "$fgid"; then
		[[ $(( 8#$mode & 8#040 )) -ne 0 ]]
	else
		[[ $(( 8#$mode & 8#004 )) -ne 0 ]]
	fi
}

case "$ACTION" in
	on)
		[[ $EUID -eq 0 ]] || md_die "Please run as root."
		[[ -f "$RELAY" && ! -L "$RELAY" ]] || md_die "Relay not found: run ./install.sh first."
		command -v systemctl >/dev/null 2>&1 || md_die "systemd (systemctl) is required."
		command -v ss >/dev/null 2>&1 || md_die "ss (iproute2) is required to verify the listener."
		PHP="$(command -v php)"
		PHP="$(cd "$(dirname "$PHP")" && pwd -P)/$(basename "$PHP")"
		if [[ "$("$PHP" -r 'echo PHP_VERSION_ID;')" -lt 80100 ]]; then
			md_die "The relay needs PHP 8.1 or newer ($PHP is $("$PHP" -r 'echo PHP_VERSION;'))."
		fi
		md_safe_path "$TARGET"
		md_safe_path "$PHP"

		# Who runs the relay: the owner of userdata.inc.php (no symbolic link).
		[[ -L "$USERDATA" ]] && md_die "$USERDATA is a symbolic link; refusing."
		[[ -f "$USERDATA" ]] || md_die "$USERDATA not found."
		UD_UID="$(stat -c %u -- "$USERDATA")"
		if [[ "$UD_UID" -ne 0 ]]; then
			RUN_UID="$UD_UID"
			if [[ -n "$RUN_USER" && "$(id -u -- "$RUN_USER" 2>/dev/null)" != "$RUN_UID" ]]; then
				md_die "--user must be the owner of lib/userdata.inc.php (uid $RUN_UID), or be left out."
			fi
		else
			[[ -n "$RUN_USER" ]] || md_die "lib/userdata.inc.php belongs to root, and the relay must not run as root.
       Pass the (non-root) user that may read it, usually the panel user: --user NAME"
			RUN_UID="$(id -u -- "$RUN_USER" 2>/dev/null)" || md_die "unknown user: $RUN_USER"
		fi
		[[ "$RUN_UID" -ne 0 ]] || md_die "the relay must not run as root."
		RUN_USER="$(uid_name "$RUN_UID")"
		[[ "$RUN_USER" =~ ^[A-Za-z_][A-Za-z0-9_.-]*$ ]] || md_die "unsupported user name: $RUN_USER"
		can_read "$RUN_UID" "$USERDATA" || md_die "user $RUN_USER cannot read $USERDATA."

		# The relay code, and every directory from / down to it, must belong to
		# that user or to root and be writable by nobody else; otherwise another
		# user could run code as the relay user.
		if find "$CODE_DIR" -type l | grep -q .; then
			md_die "symbolic links found in $CODE_DIR; refusing."
		fi
		PATHS=("/")
		dir=""
		IFS='/' read -r -a parts <<< "${CODE_DIR#/}"
		for part in "${parts[@]}"; do
			dir="$dir/$part"
			PATHS+=("$dir")
		done
		while IFS= read -r file; do
			PATHS+=("$file")
		done < <(find "$CODE_DIR" -mindepth 1)
		for file in "${PATHS[@]}"; do
			read -r uid mode < <(stat -c '%u %a' -- "$file")
			if [[ "$uid" -ne 0 && "$uid" -ne "$RUN_UID" ]]; then
				md_die "$file belongs to uid $uid, not to $RUN_USER or root; refusing."
			fi
			if [[ $(( 8#$mode & 8#022 )) -ne 0 && $(( 8#$mode & 8#1000 )) -eq 0 ]]; then
				md_die "$file is writable by group or others; refusing."
			fi
		done

		ALLOWED="$RUN_UID"
		for u in "${ALLOW_USERS[@]+"${ALLOW_USERS[@]}"}"; do
			uid="$(id -u -- "$u" 2>/dev/null)" || md_die "unknown user: $u"
			ALLOWED="$ALLOWED,$uid"
		done

		# Sandbox. The relay hands mails to the local sendmail. With Postfix,
		# sendmail runs postdrop, which is setgid "postdrop" and writes into
		# /var/spool/postfix/maildrop, then signals the pickup service through
		# the FIFO /var/spool/postfix/public/pickup. Hence:
		# - no NoNewPrivileges= and none of the options that imply it
		#   (SystemCallFilter=, RestrictAddressFamilies=, PrivateDevices=,
		#   RestrictSUIDSGID=, ProtectKernel*=, LockPersonality=, ...): they
		#   would disable the setgid bit and break delivery;
		# - ProtectSystem=strict with those two spool directories writable.
		#   An empty capability bounding set does not affect setgid binaries.
		HOME_PROTECT=yes
		[[ "$TARGET" == /home/* || "$TARGET" == /root/* ]] && HOME_PROTECT=read-only
		IP_RULES=""
		if [[ "$(md_php migrations/db-info.php "$TARGET")" == "local" ]]; then
			IP_RULES=$'IPAddressDeny=any\nIPAddressAllow=localhost'
		fi

		SOCKET_CONTENT="[Unit]
Description=Froxlor Mail Design relay (127.0.0.1:$PORT)

[Socket]
ListenStream=127.0.0.1:$PORT
Accept=yes
MaxConnections=8
# No trigger limit: a flood of connections is capped by MaxConnections and
# must not leave the socket in the failed state until an admin restarts it.
TriggerLimitIntervalSec=0
NoDelay=true

[Install]
WantedBy=sockets.target"

		# Older form (the relay listening itself): switch Froxlor back first, so
		# it never points at the port while nobody holds it.
		if [[ -f "$LEGACY_UNIT" ]]; then
			md_php migrations/smtp-switch.php "$TARGET" off "$PORT"
			systemctl disable --now "$NAME.service" >/dev/null 2>&1 || true
			rm -f -- "$LEGACY_UNIT"
		fi

		# The service template applies to the next connection; the socket is
		# only (re)written and started when needed, so a running socket keeps
		# holding the port.
		write_unit "$SERVICE_UNIT" <<EOT
[Unit]
Description=Froxlor Mail Design relay connection
CollectMode=inactive-or-failed

[Service]
User=$RUN_USER
ExecStart=$PHP $RELAY $TARGET $ALLOWED
StandardInput=socket
StandardOutput=socket
StandardError=journal
RuntimeMaxSec=600
TasksMax=16
MemoryMax=256M
UMask=0077
CapabilityBoundingSet=
PrivateTmp=yes
PrivateIPC=yes
RemoveIPC=yes
ProtectSystem=strict
ReadWritePaths=-/var/spool/postfix/maildrop -/var/spool/postfix/public
ProtectHome=$HOME_PROTECT
InaccessiblePaths=-/var/customers
ProtectControlGroups=yes
ProtectProc=invisible
$IP_RULES
EOT
		if [[ ! -f "$SOCKET_UNIT" ]] || [[ "$(cat "$SOCKET_UNIT")" != "$SOCKET_CONTENT" ]]; then
			write_unit "$SOCKET_UNIT" <<< "$SOCKET_CONTENT"
		fi
		systemctl daemon-reload
		if ! systemctl is-active --quiet "$NAME.socket"; then
			if ! systemctl enable --now "$NAME.socket"; then
				md_php migrations/smtp-switch.php "$TARGET" off "$PORT" || true
				remove_units
				md_die "the socket could not be started; is 127.0.0.1:$PORT already in use? (ss -ltnp 'sport = :$PORT')"
			fi
		else
			systemctl enable "$NAME.socket" >/dev/null 2>&1
		fi

		# Only switch Froxlor over once it is certain that systemd itself (pid 1)
		# owns the listener, and nobody else listens on the port.
		LISTENERS="$(ss -Hltnp "sport = :$PORT" 2>/dev/null || true)"
		if [[ "$(grep -c . <<< "$LISTENERS")" -ne 1 ]] \
			|| ! grep -q "127.0.0.1:$PORT " <<< "$LISTENERS" \
			|| ! grep -q '"systemd",pid=1,' <<< "$LISTENERS"; then
			md_php migrations/smtp-switch.php "$TARGET" off "$PORT" || true
			remove_units
			md_die "the listener on port $PORT does not belong to systemd; Froxlor's SMTP settings were not switched to it:
$(tr -d "$CONTROL_CHARS" <<< "$LISTENERS")"
		fi

		if ! md_php migrations/smtp-switch.php "$TARGET" on "$PORT"; then
			# Keep the units if Froxlor points at the relay anyway (an earlier "on").
			if md_php migrations/smtp-switch.php "$TARGET" status "$PORT" >/dev/null 2>&1; then
				exit 1
			fi
			echo "Froxlor's SMTP settings were not changed; removing the units again." >&2
			remove_units
			exit 1
		fi
		echo "Done. Configure the design in the panel under Settings > Mail design."
		;;
	off)
		# Froxlor first, so it never points at a port nobody listens on.
		md_php migrations/smtp-switch.php "$TARGET" off "$PORT"
		if [[ -f "$SOCKET_UNIT" || -f "$SERVICE_UNIT" || -f "$LEGACY_UNIT" ]]; then
			if [[ $EUID -eq 0 ]]; then
				remove_units
				echo "  Relay units removed"
			else
				echo "  Run as root to remove the units of $NAME as well." >&2
			fi
		fi
		;;
	status)
		md_php migrations/smtp-switch.php "$TARGET" status "$PORT" || true
		if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet "$NAME.socket" 2>/dev/null; then
			echo "  Socket $NAME.socket: active"
		else
			echo "  Socket $NAME.socket: not active"
		fi
		;;
	*) md_die "Action: on | off | status" ;;
esac
