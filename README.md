# Mail Design for Froxlor 2.x

Gives the system mails Froxlor sends a proper look: a header with your logo
or company name and the server name, readable body text, login details set
off in a box, links, an optional footer, and dark mode support. Without
changing a single Froxlor file.

**Version 1.0.0** — for Froxlor 2.3 (tested against 2.3.13 and checked
against 2.3.16), PHP 8.1 or newer.

## What and why

Froxlor's mails (account information for new customers, password reset,
traffic reports, notices to the admin, test mail) are plain text: Froxlor puts
the text into the mail once as a text part and once, with `<br />`, as an HTML
part. Froxlor has no logo, colors or layout for them, and its mailer
(`Froxlor\System\Mailer`) has no hook point. Mail Design therefore styles the
mails on their way out:

* A small **local relay** accepts Froxlor's mails via SMTP on
  `127.0.0.1:2530`, rebuilds them and hands them to the local `sendmail`, the
  same way they took before. systemd itself listens on the port
  (`froxlor-mail-design.socket`) and starts one short-lived relay process
  (`lib/Froxlor/Ext/MailDesign/mail-relay.php`) per connection.
* Pointing Froxlor at the relay is pure configuration: Froxlor's own SMTP
  settings (`system.mail_use_smtp`, `_host`, `_port`, `_auth`, ...).
  `mail-design.sh` does that and restores the previous values when switched
  off.
* Only mails sent by Froxlor itself are affected, not those of customers
  (WordPress, mailboxes).

The result: a header with logo or company name and, below it, the host server
("Host server host.example.org"); the text set for reading; lines of the form
`Name: value` (username, password, address ...) as a box; addresses as links;
an optional footer. Table layout with inline CSS, 600 px wide and fluid on
phones; dark mode via `prefers-color-scheme`. The logo is embedded in the mail
(`cid:`), so it shows even when the mail client blocks remote images. The text
part is kept (with name line and footer); sender and recipient headers are not
touched. The words of the mails stay those of Froxlor's mail templates
(Admin › Email templates); Mail Design only puts them in a frame.

## Requirements

* Froxlor 2.x (2.3.13 and 2.3.16 checked); no version check, see
  [Compatibility](#compatibility).
* PHP 8.1 or newer as command line `php` (with `pdo_mysql`, `mbstring`,
  `iconv`, `tokenizer`; all required by Froxlor anyway).
* systemd (247 or newer recommended for all sandboxing options; older versions
  ignore the unknown ones), `ss` (iproute2), and a local `sendmail` (Postfix),
  as on every Froxlor server.
* Root for installing the units, and `setpriv` and `setsid` from util-linux
  (present on Debian and Ubuntu) so the scripts can act as the owner of
  Froxlor's files.

## Installation

Clone the repository **outside any web root** (for example below `/root` or
`/opt`), never into Froxlor's directory or a customer's document root:

```bash
git clone https://github.com/lichtsignale-sk/froxlor-mail-design.git
cd froxlor-mail-design
./install.sh /var/www/froxlor                 # files and default settings
./mail-design.sh /var/www/froxlor on          # as root: start the relay, point Froxlor at it
```

`install.sh` copies the files, creates `img/` if missing, clears the Twig
cache and applies the migrations. It reads the database credentials from
Froxlor's own `lib/userdata.inc.php` and connects via PHP/PDO with the same
options as Froxlor (no MySQL command line client needed). Look first with:

```bash
./install.sh /var/www/froxlor --dry-run
./install.sh /var/www/froxlor --skip-migrations   # files only
```

Then, under **Settings › Mail design**: company name, logo (upload, PNG/JPG/GIF
up to 400 KB), server name on/off, header color, accent color, footer. The
relay reads the values for every mail, changes apply immediately. The
checkbox "Style emails sent by Froxlor" switches the styling off without
changing the delivery path.

### mail-design.sh

```bash
./mail-design.sh /var/www/froxlor on       # install and start the socket, switch Froxlor's SMTP settings
./mail-design.sh /var/www/froxlor on --allow-user www-data   # also serve this (panel PHP) user
./mail-design.sh /var/www/froxlor off      # restore the previous SMTP settings, remove the units
./mail-design.sh /var/www/froxlor status
```

`on` writes two units, `froxlor-mail-design.socket` (systemd listens on
`127.0.0.1:2530`, `Accept=yes`, at most 8 connections) and
`froxlor-mail-design@.service` (one relay process per connection, the
connection on stdin/stdout, errors in the journal), starts the socket,
verifies with `ss` that the listener belongs to systemd (pid 1) and only then
switches Froxlor's SMTP settings. Running `on` again updates the service
template without releasing the port. An older single-service installation
(`froxlor-mail-design.service`) is switched off and removed first.

The relay runs as the owner of `lib/userdata.inc.php` (it must read the
database credentials); a symbolic link there is refused. Only if that file
belongs to root, `--user NAME` names the (non-root) user, who must be able to
read it. The relay code and every directory above it must belong to that user
or to root and be writable by nobody else. The relay never runs as root.

The relay serves only clients running as root (Froxlor's cron jobs), as the
relay user (the panel's PHP) and as users named with `--allow-user`, for
example when the panel's PHP-FPM pool runs as a different user than the
owner of the files.

`on` refuses to change anything if Froxlor already uses an SMTP server of its
own (Settings › System › Mail): the relay hands mails to the local
`sendmail` (that is, to Postfix and its relay host), not to that server, and
an admin's SMTP configuration must not be overwritten.

### Update

```bash
git pull && ./install.sh /var/www/froxlor
```

The migrations are idempotent; the relay uses the new code from the next
connection on. **Run `install.sh` again after every Froxlor update**: an update
cannot overwrite the extension's files (Froxlor does not know them), but an
update that replaces the whole directory removes them.

### Uninstall

```bash
./uninstall.sh /var/www/froxlor            # files gone, settings kept
./uninstall.sh /var/www/froxlor --purge    # also delete the settings
```

In both cases `mail-design.sh off` runs first (previous SMTP settings back,
units removed), and the uploaded logo is deleted.

## Configuration and stored data

All settings live in Froxlor's `panel_settings` table, group `ext_maildesign`:

| varname | meaning | default |
|---|---|---|
| `enabled` | style mails (the relay passes them through unchanged otherwise) | `0` (set to `1` by `mail-design.sh on`) |
| `company_name` | company name in the header / logo alt text | empty |
| `logo` | path of the uploaded logo, `img/mail-logo.<png\|jpg\|gif>?v=<time>` | empty |
| `show_server` | show `system.hostname` in the header | `1` |
| `header_color` | header background, `#rrggbb` | `#1f2937` |
| `accent_color` | accent line, box border, links, `#rrggbb` | `#2563eb` |
| `footer` | optional footer lines | empty |
| `smtp_backup` | only while switched on: JSON of Froxlor's previous `system.mail_*` SMTP values | — |

`mail-design.sh on` changes these Froxlor settings (group `system`) and backs
up their previous values in `smtp_backup`: `mail_use_smtp` = `1`,
`mail_smtp_host` = `127.0.0.1`, `mail_smtp_port` = `2530`,
`mail_smtp_usetls` = `0`, `mail_smtp_auth` = `1`,
`mail_smtp_user` = `froxlor-mail-design`, `mail_smtp_passwd` = a random
48-character hex string. `off` restores the backup exactly, but only if
Froxlor still points at the relay; if the admin has changed the SMTP settings
in the meantime, they are left alone.

Files outside the extension's own: the systemd units
`/etc/systemd/system/froxlor-mail-design.socket` and
`/etc/systemd/system/froxlor-mail-design@.service`, and the logo
`img/mail-logo.<png|jpg|gif>` in Froxlor's directory.

## Security model

* **systemd holds the port.** The listening socket on `127.0.0.1:2530`
  belongs to systemd from boot on and across restarts of the relay, so no
  local user can bind the port and receive the panel's mails (password reset
  links) or its SMTP password. `mail-design.sh on` refuses to switch Froxlor
  over unless `ss` shows systemd (pid 1) as the only listener.
* **Only known local users are served.** The relay looks up the uid of the
  connecting process in `/proc/net/tcp` (by the client port systemd passes in
  `REMOTE_PORT`) and serves only root, the relay user and users named with
  `--allow-user`; everybody else gets `554` before anything is read.
* **SMTP AUTH.** In addition, MAIL requires `AUTH PLAIN` with the credentials
  stored in Froxlor's `system.mail_smtp_user`/`_passwd`, checked in constant
  time against the database. Without it, any local process could send mails
  that look like panel mails, with your logo and the panel's envelope sender.
  There is no TLS on this loopback connection.
* **Robust input handling**: input goes through an own buffer read with
  `stream_select()`; an idle timeout of 30 s per wait and an absolute deadline
  checked after every read (30 s until authenticated, 5 minutes per session),
  so trickling single bytes does not keep a connection open. The greeting is
  fixed; nothing is read from the database before AUTH. Command lines are
  limited to 2 KB, messages to 10 MB (larger ones are read to the end and
  refused with 552), at most 100 recipients, at most 8 connections at a time
  (`MaxConnections=8`), each limited by `RuntimeMaxSec=600`, `TasksMax=16` and
  `MemoryMax=256M`. Three failed logins close the connection. End-of-data and
  dot-unstuffing are only recognised at the start of a line, even when a long
  line arrives in several chunks.
* **Safe fallback**: if anything goes wrong while styling (unknown format,
  broken charset, invalid UTF-8, logo not loadable, mail larger than 1 MB),
  the original mail is sent unchanged. Only a failing `sendmail` is reported
  back to Froxlor (451), which then shows its usual error.
* **No header injection**: the rebuilt mail keeps Froxlor's own header lines
  and adds only fixed text, random MIME boundaries and a MIME type from a fixed
  list. Settings and body text only end up in the body.
* **Everything placed in the HTML is escaped** (company name, server name,
  subject, body text, footer, link targets). Only `http://` and `https://`
  addresses become links. Colors are only used if they match `#rrggbb`.
* **sendmail without a shell**: the arguments are passed as an array, the
  recipients after `--`. Envelope addresses must be plain `dot-atom@host`
  addresses (ASCII, exactly one `@`, no quoted local parts, no leading dash).
  The envelope sender is `postmaster@<hostname>` (as with Froxlor's own panel
  PHP pool), and only if the hostname is a valid host name. The database
  connection is closed before sendmail starts, so it does not inherit it.
* **Logo upload**: PNG, JPEG and GIF only, recognised by content
  (`getimagesize`), never by file name or client MIME type; no SVG (mail
  clients do not show it and it can carry scripts); 400 KB and 2000 × 2000
  pixels at most; stored under the fixed name `img/mail-logo.<ext>` (nothing
  from the upload becomes part of a path), written to a temporary file and
  renamed, mode 0644. The relay only embeds an image file directly inside
  Froxlor's `img/` directory: no absolute paths, no `..`, no URLs, no
  symbolic links. The upload is only possible for admins who may change
  Froxlor's settings, protected by Froxlor's CSRF token.
* **Service account and sandbox**: the relay runs as the owner of Froxlor's
  `lib/userdata.inc.php` (see `mail-design.sh` above), never as root. The
  units are written by root to a temporary file and renamed (mode 0644);
  paths and the user name are restricted to plain characters, so nothing
  needs quoting and no systemd specifier (`%`) can sneak in. Sandbox:
  `ProtectSystem=strict` (everything read-only, including the Froxlor
  directory) with only `/var/spool/postfix/maildrop` and
  `/var/spool/postfix/public` writable, `ProtectHome=yes` (`read-only` if
  Froxlor lives below `/home`), `InaccessiblePaths=/var/customers`,
  `PrivateTmp`, `PrivateIPC`, `RemoveIPC`, `ProtectProc=invisible`,
  `ProtectControlGroups`, an empty `CapabilityBoundingSet`, `UMask=0077`, and
  `IPAddressDeny=any` + `IPAddressAllow=localhost` when the database is
  local. Deliberately **not** `NoNewPrivileges=` nor the options that imply
  it (`SystemCallFilter=`, `RestrictAddressFamilies=`, `PrivateDevices=`,
  `RestrictSUIDSGID=`, `ProtectKernel*=`, `LockPersonality=`, ...): Postfix's
  `sendmail` hands the mail to `postdrop`, which is setgid `postdrop`, writes
  into `/var/spool/postfix/maildrop` and wakes the pickup service through the
  FIFO `/var/spool/postfix/public/pickup`; without the setgid bit delivery
  fails. (Opening an existing FIFO for writing works on a read-only mount; the
  directory is listed anyway.) With another MTA, add its spool directory to
  `ReadWritePaths=` in a drop-in.
* **Root never runs or reads panel-controlled code**: `lib/userdata.inc.php`
  is parsed with PHP's tokenizer, never included; only literal assignments
  are accepted, and a symbolic link in its place is refused. Error messages
  name only the line and token type, and all output of the helpers is
  stripped of control characters (no terminal escape sequences).
* **Root never writes where a less privileged user could redirect it**: the
  Froxlor directory is resolved without symbolic links (`pwd -P`).
  `install.sh` and `uninstall.sh` write and delete files there as root only
  if every directory from `/` down belongs to root and is writable by nobody
  else; otherwise as the single non-root owner of those directories, via
  `setsid --wait setpriv --reuid … --regid … --init-groups --reset-env` (new
  session without a controlling terminal, clean environment, no inherited
  file descriptors, output stripped of control characters). Several different
  owners, or a root-owned directory writable by group or others, are refused,
  as are symbolic links below the Froxlor directory. `umask 022`; tar unpacks
  with `--no-same-owner --no-same-permissions`; only an explicit list of
  files is installed.
* **Command line only**: the relay script, the migrations and the tests
  answer HTTP requests with 404; the settings page and the German catalog do
  the same when requested directly instead of being loaded by Froxlor.
* **Database access** uses exactly the keys Froxlor writes into
  `lib/userdata.inc.php` (`host`, `port`, `socket`, `ssl.caFile`,
  `ssl.verifyServerCertificate`) and builds the connection like
  `Froxlor\Database\Database`: socket if set, otherwise host and port with
  optional TLS.
* **When the socket is stopped**, Froxlor's mails cannot be delivered (Froxlor
  shows an error); `mail-design.sh ... status` shows the state.

## Compatibility

* The only Froxlor interfaces used are the settings directory
  (`actions/admin/settings/*.php`, merged by `admin_settings.php`), the image
  setting type with `plausibility_check_method` (`Froxlor\UI\Form`,
  `Froxlor\Settings\Store::storeSettingImage`), the classes `Froxlor\Froxlor`,
  `Froxlor\Settings`, `Froxlor\UI\Response`, `Froxlor\Validate\Check`, and the
  SMTP settings read by `Froxlor\System\Mailer`. All of them are identical in
  2.3.13 and 2.3.16.
* `Froxlor\System\Mailer` (2.3.13 = 2.3.16) uses PHPMailer 6.12 with
  `SMTPAutoTLS = false` when TLS is off, and authenticates with the strongest
  advertised mechanism; the relay only offers `AUTH PLAIN`.
* No hard version check: `Froxlor::VERSION` is not consulted. A future 2.4
  that keeps the 2.x architecture needs nothing.
* PHP 8.1 to 8.5. On PHP 8.4+ the `Pdo\Mysql::ATTR_*` constants are used
  instead of the deprecated `PDO::MYSQL_ATTR_*` (as Froxlor 2.3.16 does).

## Languages

All texts (settings, upload errors, the frame texts of the mails) exist in
English and German. The settings page follows the language of the logged-in
admin (Froxlor profile, else the panel's default language): German ("Deutsch",
`de`, `de-*`) gives German, any other language English. The mail frame
("Host server ...", "This message was sent automatically ...") follows the
panel's default language. The English text is the key; German translations
are in `lib/Froxlor/Ext/MailDesign/lang/de.php`. Froxlor's own language files
stay untouched.

## Design decisions

**A relay instead of a patch.** Patching `Mailer` would be lost with every
Froxlor update. Froxlor's SMTP settings are a stable, documented interface.

**Not via `sendmail_path`.** Froxlor rewrites the PHP pool files on every
change and would drop such an entry; it would also affect customers' mails.

**sendmail, not an upstream SMTP server.** The relay hands mails to the local
MTA, just like Froxlor did before. That is why `on` refuses when Froxlor
already uses an SMTP server of its own.

**One logo at a fixed place.** Froxlor's own image upload stores every file
under a random name and keeps the old ones. Mail Design hooks into the upload
(`plausibility_check_method`) and stores exactly one file,
`img/mail-logo.<ext>`, replacing the previous one. Deleting via the checkbox is
still done by Froxlor. A logo restored by Froxlor's settings import (random
name in `img/`) is used as well.

**The settings are read for every mail.** No cache, no restart: a change in
the panel is in the next mail.

**Failure means "unstyled", never "lost".** Styling is a nice-to-have; the
mail itself is not.

## Tests

These run without Froxlor, database or mail server:

```bash
php tests/mail-design.php      # styling: structure, escaping, charsets, fallback, logo storage
php tests/relay.php            # SMTP protocol, AUTH, limits, timeouts, sendmail call, logo loading
php tests/smtp-switch.php      # switching SMTP settings and restoring them (SQLite in memory)
php tests/settings.php         # settings page, migration defaults and relay agree
php tests/db-connection.php    # userdata.inc.php read like Froxlor (socket, port, TLS block)
php tests/lang.php             # every text has a German translation, placeholders match
```

`tests/relay.php` uses `pcntl` for the SMTP conversations (skipped without
it); the tests also run as root. With the path of a Froxlor installation as argument, it additionally
sends a real mail through the relay with that installation's PHPMailer (only
read from there), configured exactly as `Froxlor\System\Mailer` does:

```bash
php tests/relay.php /var/www/froxlor
```

For a manual end-to-end test, use a disposable Froxlor (for example Froxlor's
Docker setup or a throwaway VM with Postfix): install, run
`mail-design.sh ... on`, send a test mail from Settings › System › Mail, and
look at the result in a mail client. Never test on a production server.

## Layout

```
install.sh                                  installation and update
uninstall.sh                                removal (--purge: settings too)
mail-design.sh                              relay units and SMTP switch: on | off | status
actions/admin/settings/192.ext.maildesign.php   settings page (Settings › Mail design)
lib/Froxlor/Ext/MailDesign/
  Styler.php                                builds the styled HTML mail from Froxlor's mail
  Relay.php                                 relay: peer check, settings, logo, styling, sendmail
  SmtpSession.php                           SMTP server side of one connection (AUTH, limits)
  mail-relay.php                            entry point started by systemd per connection
  LogoStore.php                             logo upload: exactly one file img/mail-logo.*
  SmtpSwitch.php                            points Froxlor's SMTP settings at the relay and back
  DbConnection.php                          PDO connection from Froxlor's userdata.inc.php
  Lang.php, lang/de.php                     English/German texts
migrations/
  001_settings.php                          default settings
  apply.php                                 applies the migrations
  smtp-switch.php                           command line for SmtpSwitch (used by mail-design.sh)
  db-info.php                               tells mail-design.sh whether the database is local
  remove.php                                database clean-up on uninstall
  connection.php                            connection for the scripts above
common.sh                                   shared helpers of the shell scripts (not installed)
tests/                                      test scripts, see above
```

`tests/` and `migrations/` are not copied into Froxlor.

## License

GPL-2.0-or-later, like Froxlor. See [LICENSE](LICENSE).

Copyright (C) Samuel Kümmel
