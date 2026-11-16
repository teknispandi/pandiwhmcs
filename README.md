# PANDI EPP Registrar Module for WHMCS

A [WHMCS](https://www.whmcs.com/) registrar module for registries that speak
EPP, such as the `.id` registry operated by PANDI. It talks to the registry
over EPP-over-TLS with a client certificate (RFC 5734) and covers the WHMCS
domain lifecycle, DNSSEC, child nameservers, redemption restore and secure
transfer codes.

License: GPL-3.0-or-later (see [License](#license)).

## Standards

| Area | Standard | What the module does |
|---|---|---|
| Protocol | RFC 5730 | Logs in with only the services the server greeting announces. Treats every 1xxx result as success (including 1001 "pending"). Shows the registry message for 2xxx errors, with an explanation. Processes and acknowledges the poll queue. |
| Transport | RFC 5734, BCP 195 | Uses mutual TLS 1.2+ and verifies the registry certificate by default. Frame lengths are counted in bytes. |
| Domains | RFC 5731 | Builds frames in schema order. Sends `curExpDate` as a date. Sends only the difference when updating nameservers and statuses. Creates missing external host objects before delegating to them. Sends IDNs as A-labels. |
| Hosts | RFC 5732 | Manages child nameservers with IPv4/IPv6 glue and rejects private addresses. |
| Contacts | RFC 5733 | Uses contact IDs of 3–16 characters and always sends `authInfo`. Sends postal info as `loc` (UTF-8) and `int` (ASCII). Phone numbers use the `+CC.NNN` format. |
| DNSSEC | RFC 5910, RFC 8624 | Manages DS records through secDNS-1.1. Algorithms and digest types follow RFC 8624: no SHA-1 or deprecated algorithms for new records. |
| Restore | RFC 3915 | Sends an RGP restore request, followed by the restore report when the registry requires one. |
| Transfer codes | RFC 9154, ICANN Transfer Policy | Generates a random code with at least 128 bits on request, sets it at the registry, and invalidates it after a configurable lifetime. With an RFC 9154 registry, new domains have no code. |

Every EPP command the module sends is validated against the official IANA
XML schemas in the test suite.

## Features

**Domains.** Availability check and suggestions (including IDN). Register,
transfer, renew, delete, and sync of status and expiry date. Transfer sync.

**Nameservers and contacts.** Read and save nameservers. Register, modify and
delete child nameservers. Read and save contact details.

**Locks and codes.** Registrar lock (`clientTransferProhibited` +
`clientDeleteProhibited`). EPP/transfer code.

**Admin area buttons.** Put Domain On Hold / Remove Domain Hold (`clientHold`)
and Restore Domain (RGP).

**Client area pages.** Child nameserver list and DNSSEC DS record management.

**Optional cron scripts.** Parking and "domain expired" pages through the
PowerDNS API.

Not supported: ID protection, DNS hosting (`GetDNS`/`SaveDNS`), `ReleaseDomain`,
approving or rejecting outbound transfers from WHMCS, the DNSSEC key-data
interface, and the fee and launch-phase extensions.

## Requirements

- WHMCS 8.x
- PHP 7.4 or newer with the `dom`, `intl` and `openssl` extensions; the optional
  crons also need `curl`
- An EPP account at the registry, its client certificate (PEM) and, if the
  registry certificate is not issued by a public CA, the registry CA certificate

## Installation

1. Copy the repository to `<whmcs>/modules/registrars/pandiwhmcs/`. The
   directory **must** be named `pandiwhmcs`.
2. Put the client certificate and key outside the web root, for example in
   `/etc/whmcs/epp/registrar.pem`, readable by the PHP user only. (Files in
   `lib/certificate/` also appear in the *Certificate* dropdown, but the web
   server must then never serve that directory; see [Security](#security).)
3. In WHMCS, go to **System Settings → Domain Registrars**, then activate and
   configure **PANDI EPP Registrar**:

| Setting | Notes |
|---|---|
| EPP Server / Server Port | Registry host name and port (usually 700). |
| Client ID / Password | EPP credentials. |
| Certificate Path, Private Key Path, Key Passphrase | Absolute paths to your client certificate and key. The path overrides the *Certificate* dropdown. |
| CA Bundle | CA that issued the registry server certificate. Leave empty to use the system CA store. |
| Skip TLS Verification | Test environments only. RFC 5734 requires verification. |
| AuthInfo Mode | *Generate* (default, RFC 9154) or *Show the code stored at the registry* (legacy). |
| AuthInfo Length | 20 by default (about 129 bits). Lower it only if the registry limits the length. |
| AuthInfo Lifetime (days) | 14 by default. Expired codes are invalidated by the WHMCS domain sync. 0 disables this. |
| Domain Contacts | The contacts linked to new registrations. Use the minimum that the registry policy requires. |
| Omit Transfer Period | Turn this on for registries that reject `<domain:period>` in transfer requests. |
| Debug Log | Also write the EPP exchange to `lib/logs/`. |

4. Enable **Domain Sync** in WHMCS (Automation Settings). The sync updates expiry
   dates, processes the registry message queue, and expires AuthInfo codes.

The WHMCS module log (*System Logs → Module Log*) shows every EPP command and
response. Passwords and AuthInfo values are masked there.

## Security

- `lib/` contains code, logs and possibly certificates. It ships with an
  `.htaccess` that denies web access (Apache). On nginx, add:

  ```nginx
  location ~ ^/modules/registrars/pandiwhmcs/(lib|tests)/ { deny all; }
  ```

- The cron scripts and `config.php` refuse to run outside the CLI.
- Never commit `config.local.php`, certificates or logs. The `.gitignore`
  excludes them.

## Optional: parking and expired-domain pages (PowerDNS)

These two scripts point zones on your own PowerDNS server at a parking page or
an "expired" page:

- `cron.php` handles new domains without a hosting service.
- `expire-cron.php` handles domains past their expiry date.

They change only zones hosted on that PowerDNS server.

1. Copy `config.local.php.example` to `config.local.php` and fill it in, or set
   the same names as environment variables.
2. Create the table that `cron.php` uses:

   ```sql
   CREATE TABLE IF NOT EXISTS tbldomain_parking (
       id INT AUTO_INCREMENT PRIMARY KEY,
       domain VARCHAR(255) NOT NULL,
       isParking BOOLEAN,
       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       UNIQUE KEY domain (domain)
   );
   ```

3. Schedule both scripts:

   ```cron
   */5 * * * * php -q /var/www/whmcs/modules/registrars/pandiwhmcs/cron.php
   0 0 * * *   php -q /var/www/whmcs/modules/registrars/pandiwhmcs/expire-cron.php
   ```

The page served at the expired IP should state that the domain has expired and
explain how to renew it, as ICANN's Expired Registration Recovery Policy
requires for gTLDs. DNS is not switched back automatically after a renewal.

## Tests

```sh
php tests/run.php
```

The suite runs every WHMCS hook against an in-process EPP registry simulator.
It validates each command against the IANA XSDs, which it downloads into
`tests/xsd/` the first time, and treats PHP warnings and deprecations as
failures. Do not deploy `tests/` to production.

## Upgrading from earlier versions

See [CHANGELOG.md](CHANGELOG.md). In short:

- The registry certificate is now verified. If the registry uses a private
  CA, set **CA Bundle**.
- Domain hold no longer writes to the domain's admin notes. Remove the
  leftover `clientHold` notes by hand.
- The PowerDNS settings moved to `config.local.php` and the constants were
  renamed with a `PANDIWHMCS_` prefix.
- The contact details form uses the standard WHMCS fields *First Name* and
  *Last Name* instead of *Full Name*.

## License

Copyright (C) 2023-2026 PANDI (Pengelola Nama Domain Internet Indonesia) and
contributors.

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version. See [LICENSE](LICENSE).

The module bundles a modified copy of
[php-epp2](https://github.com/AfriCC/php-epp2) (GPL-3.0-or-later) in `lib/EPP/`.
See [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

---

## Ringkasan (Bahasa Indonesia)

Modul registrar WHMCS untuk registry berbasis EPP, seperti registry `.id`
yang dikelola PANDI. Modul ini mengikuti RFC 5730–5734, 5910, 3915 dan 9154,
serta praktik ICANN untuk kode transfer.

**Instalasi:**

1. Salin repositori ini ke `modules/registrars/pandiwhmcs`.
2. Simpan sertifikat klien di luar web root, lalu isi **Certificate Path**.
3. Aktifkan modul di **System Settings → Domain Registrars**.
4. Aktifkan **Domain Sync** di WHMCS.

Sertifikat server registry kini diverifikasi. Jika registry memakai CA
privat, isi **CA Bundle**.

Jalankan test dengan `php tests/run.php`.

Lisensi: GPL-3.0-or-later, karena modul ini membundel php-epp2 yang berlisensi
GPLv3.
