# Changelog

## 2.0.0 (unreleased)

First open-source release. It is licensed GPL-3.0-or-later because the bundled
php-epp2 library is GPLv3.

### Security

- The registry server certificate is now verified. Before, `verify_peer` was
  hard-coded to `false`, which allowed man-in-the-middle attacks on the EPP
  session. Only TLS 1.2+ is offered. A TLS failure now shows its cause, such as
  "certificate verify failed".
- The EPP password, transfer codes and generated AuthInfo values are masked in
  the WHMCS module log and in `lib/logs/`. Before, they were written there in
  clear text.
- `lib/` (certificates, logs) and `tests/` deny web access with `.htaccess`.
- Client certificates can be loaded from an absolute path outside the web
  root.
- `cron.php`, `expire-cron.php` and `config.php` only run from the CLI. Before,
  anyone could trigger them over HTTP.
- The PowerDNS URL, API key and IP addresses were removed from `config.php`;
  they now live in `config.local.php`, which is not committed.
- Client area templates escape their output, and the DNSSEC forms carry the
  WHMCS CSRF token.
- Module classes are namespaced and the global helpers (`generateRandomString`,
  `CreateLog`, …) were removed. These names could collide with other modules
  and cause fatal errors.
- The file logger no longer changes the PHP timezone of the whole WHMCS
  request.

### Standards compliance

- **RFC 5730**
  - The login only requests the services the server announced. It no longer
    sends `epp-1.0` as an object URI, and it declares secDNS-1.1 / rgp-1.0
    when available.
  - 1001 "action pending" counts as success. Before, deletes and transfers
    pending at the registry were reported as errors.
  - Error messages include the EPP code and an explanation.
  - Every poll message is acknowledged, and several are processed per sync.
- **RFC 5734**: frame lengths are counted in bytes, and every call logs out.
- **RFC 5731**
  - Nameserver updates sent `<rem>` before `<add>`, which violates the schema.
    They now send only the difference.
  - Transfer requests put `<period>` after `<authInfo>`, which violates the
    schema. Fixed.
  - Renewals sent `curExpDate` as a dateTime instead of an `xs:date`. Fixed.
  - Lock and hold only add or remove statuses that actually change.
  - Missing external nameserver host objects are created before delegation.
  - IDNs are sent as A-labels.
- **RFC 5733**
  - Contact create now always includes the mandatory `authInfo`.
  - Contact IDs are kept within 3–16 characters.
  - Phone numbers are normalised to `+CC.NNN`.
  - Empty optional fields are no longer sent.
- **RFC 5910 / RFC 8624**
  - Removing a DS record never worked: the form sent no values and the handler
    checked the wrong command.
  - New DS records are validated: no SHA-1 digests and no deprecated
    algorithms. The ECDSA P-384, Ed25519 and Ed448 algorithms were added.
- **RFC 3915**: new admin button **Restore Domain (RGP)**, which sends the
  restore request and the restore report.
- **RFC 9154 / ICANN Transfer Policy**
  - The transfer code is generated on request with at least 128 bits and set at
    the registry.
  - The code is invalidated after a configurable lifetime.
  - New domains get no code when the registry supports RFC 9154.

### Fixed

- Availability checks with more than one TLD returned wrong results.
- `GetRegistrarLock` always reported "unlocked".
- `Sync` never reported `transferredAway`. It now also reports pending deletes
  as cancelled.
- `TransferSync` sent a new transfer request whenever the query failed (`if
  ($code = 2301)`). It now determines the outcome from the registry.
- Poll processing updated the domain being synced instead of the domain in the
  message.
- `GetContactDetails` showed the registrant as the technical contact.
- `SaveContactDetails` lost the postcode (it used a `Postalcode` field). It also
  failed for contacts sponsored by another registrar; those are now replaced.
- A domain or contact with a single nameserver, status or DS record broke
  parsing.
- A failed registration left orphaned contacts at the registry.
- Renewals during the auto-renew period are now decided from the registry's
  expiry date instead of a fixed 45-day rule. Before, a renewal could be
  marked paid without being sent to the registry.
- `expire-cron.php` processed the same first 50 domains every day. `cron.php`
  skipped every domain after the first 50.

### Changed (check when upgrading)

- **Settings**
  - *Verify Peer* was replaced by *Skip TLS Verification*: verification is on
    unless that box is ticked.
  - New settings: *Certificate Path*, *Private Key Path*, *Key Passphrase*,
    *CA Bundle*, *AuthInfo Mode*, *AuthInfo Length*, *AuthInfo Lifetime*,
    *Domain Contacts* and *Omit Transfer Period*.
  - *Test Mode* is now labelled *Debug Log*.
- **Domain hold** is no longer tracked in the domain's admin notes
  (`additionalnotes`). Both hold buttons are always shown.
- **Contact details** use *First Name* / *Last Name* instead of *Full Name*.
- **PowerDNS cron settings**
  - The constants were renamed: `URL_POWERDNS` → `PANDIWHMCS_PDNS_URL`,
    `API_KEY_POWERDNS` → `PANDIWHMCS_PDNS_API_KEY`,
    `IP_PARKING` → `PANDIWHMCS_PARKING_IP`,
    `IP_EXPIREDATE` → `PANDIWHMCS_EXPIRED_IP`,
    `NS1/NS2_BISAONLINE` → `PANDIWHMCS_PARKING_NS1/NS2`.
  - Set them in `config.local.php`.
- **Requirements**: PHP 7.4 or newer with `dom`, `intl` and `openssl`.

### Added

- `tests/run.php`: end-to-end tests of every hook against a strict registry
  simulator, with XSD validation of every command.
