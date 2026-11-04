# Shared helpers for install.sh, uninstall.sh and mail-design.sh (sourced, not run).
#
# These scripts usually run as root, but Froxlor's directory normally belongs
# to an unprivileged user (the panel user). That user could plant symbolic
# links or put code into files root reads. Therefore:
# - the Froxlor directory is resolved without symbolic links (pwd -P);
# - files inside it are written and removed as root only if every directory
#   from / down to the file belongs to root and is writable by nobody else;
#   otherwise as the single non-root owner of those directories, in a new
#   session (no controlling terminal), with a clean environment, stdin from
#   /dev/null, no inherited file descriptors and output stripped of control
#   characters; several different owners are refused;
# - symbolic links below the Froxlor directory are refused;
# - lib/userdata.inc.php is never executed: the PHP helpers parse it
#   (lib/Froxlor/Ext/MailDesign/DbConnection.php) and refuse a symbolic link;
# - umask 022, and tar never restores owners or permissions from the archive.

umask 022

WRITE_AS=()
CONTROL_CHARS='\000-\010\013-\037\177'

md_die() {
	echo "ERROR: $*" >&2
	exit 1
}

# md_resolve <dir>: prints the physical path (no symbolic links).
md_resolve() {
	(cd -- "$1" && pwd -P)
}

# md_safe_path <path>: only plain characters, so the path can be written into
# systemd unit files without quoting or specifier (%) problems.
md_safe_path() {
	[[ "$1" =~ ^/[A-Za-z0-9._/+-]+$ ]] || md_die "unsupported characters in path '$1' (allowed: A-Z a-z 0-9 . _ / + -)."
}

# md_no_symlinks <froxlor-dir> <relative path>
# Refuses if any existing component of the path below the Froxlor directory
# is a symbolic link.
md_no_symlinks() {
	local path="$1" rel="${2%/}" part
	local -a parts
	IFS='/' read -r -a parts <<< "$rel"
	for part in "${parts[@]}"; do
		[[ -z "$part" || "$part" == "." ]] && continue
		[[ "$part" == ".." ]] && md_die "invalid path: $rel"
		path="$path/$part"
		if [[ -L "$path" ]]; then
			md_die "$path is a symbolic link; refusing to write through it."
		fi
	done
}

md_require_tools() {
	command -v setpriv >/dev/null 2>&1 && setpriv --help 2>&1 | grep -q -- '--reset-env' \
		|| md_die "setpriv from util-linux (with --reset-env) is required to act as the owner of Froxlor's files."
	command -v setsid >/dev/null 2>&1 && setsid --help 2>&1 | grep -q -- '--wait' \
		|| md_die "setsid from util-linux (with --wait) is required."
}

# md_writer_for <froxlor-dir> <relative path>
# Chooses who writes the given path (sets WRITE_AS), looking at every
# existing directory from / down to the path:
# - exactly one non-root owner among them: write as that user;
# - none: write as root, but only if none of them is writable by group or
#   others (otherwise a less privileged user could redirect the write);
# - several different non-root owners: refuse.
md_writer_for() {
	local target="$1" rel="${2%/}" dir="" part uid gid mode owner="" owner_gid="" open_dir=""
	local -a parts dirs
	WRITE_AS=()
	[[ $EUID -eq 0 ]] || return 0
	dirs=("/")
	IFS='/' read -r -a parts <<< "${target#/}/$rel"
	for part in "${parts[@]}"; do
		[[ -z "$part" || "$part" == "." ]] && continue
		dir="$dir/$part"
		[[ -L "$dir" ]] && md_die "$dir is a symbolic link; refusing to write $rel."
		[[ -d "$dir" ]] && dirs+=("$dir")
	done
	for dir in "${dirs[@]}"; do
		read -r uid gid mode < <(stat -c '%u %g %a' -- "$dir")
		if [[ "$uid" -ne 0 ]]; then
			if [[ -n "$owner" && "$owner" != "$uid" ]]; then
				md_die "$dir belongs to uid $uid, but a directory above it to uid $owner; refusing to write $rel."
			fi
			owner="$uid"
			owner_gid="$gid"
		elif [[ $(( 8#$mode & 8#022 )) -ne 0 && -z "$open_dir" ]]; then
			open_dir="$dir"
		fi
	done
	if [[ -n "$owner" ]]; then
		md_require_tools
		WRITE_AS=(setsid --wait setpriv "--reuid=$owner" "--regid=$owner_gid" --init-groups --reset-env --)
	elif [[ -n "$open_dir" ]]; then
		md_die "$open_dir belongs to root but is writable by group or others; refusing to write $rel as root."
	fi
}

# md_as_owner <command...>: runs a command as the writer chosen by
# md_writer_for. stdin is passed through (tar reads the archive from it),
# file descriptors above 2 are closed, output is stripped of control
# characters. Returns the command's exit status.
md_as_owner() {
	(
		for fd in /proc/$BASHPID/fd/*; do
			fd="${fd##*/}"
			[[ "$fd" =~ ^[0-9]+$ && "$fd" -gt 2 ]] && eval "exec $fd>&-" 2>/dev/null
		done
		# Never start the new user in the caller's directory (usually /root): it
		# cannot enter it, and tools like find fail on their initial directory.
		cd / || exit 1
		"${WRITE_AS[@]+"${WRITE_AS[@]}"}" "$@" 2>&1 | tr -d "$CONTROL_CHARS"
	)
}

# md_php <script relative to the repository> <args...>
# Runs one of the repository's PHP helpers (as the caller, usually root).
# They only parse userdata.inc.php; stdin is /dev/null and the output is
# stripped of control characters.
md_php() {
	local script="$1"
	shift
	php "$HERE/$script" "$@" </dev/null 2>&1 | tr -d "$CONTROL_CHARS"
}
