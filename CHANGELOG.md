# Changelog

## 1.0.1

* Security: the panel database connection disables `LOCAL INFILE`.
* The relay socket has no trigger limit any more (`TriggerLimitIntervalSec=0`); a flood of local connections is capped by `MaxConnections` and can no longer leave the socket failed. Run `./mail-design.sh /var/www/froxlor on` again to apply it.

## 1.0.0

Initial public release.

* Styles the system mails Froxlor sends (header with logo or company name and
  server name, readable body, login details as a box, links, footer, dark mode)
  via a local SMTP relay on 127.0.0.1:2530; no Froxlor file is changed.
* `mail-design.sh on|off|status` sets up the socket-activated systemd units
  `froxlor-mail-design.socket` / `froxlor-mail-design@.service` and switches
  Froxlor's SMTP settings, backing up and restoring the previous values.
* The relay serves only root, the panel user and explicitly allowed users,
  and requires SMTP AUTH with credentials stored in Froxlor's settings.
* Logo upload (PNG, JPG, GIF up to 400 KB) stored as `img/mail-logo.<ext>`.
* English and German.
