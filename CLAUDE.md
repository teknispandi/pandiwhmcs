# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

This is a WHMCS **registrar module** that talks EPP over TLS to a domain registry. It was built for PANDI's `.id` registry and aims to comply with RFC 5730–5734, 5910, 3915 and 9154. It also ships two optional PowerDNS cron scripts. It is open source under GPL-3.0-or-later; the license is forced by the bundled php-epp2 in `lib/EPP/`.

At runtime it needs WHMCS: the module is deployed as `<whmcs>/modules/registrars/pandiwhmcs/`. The test suite runs standalone.

## Commands

```sh
php tests/run.php        # full suite; downloads the IANA XSDs into tests/xsd/ on first run
for f in pandiwhmcs.php config.php cron.php expire-cron.php lib/*.php; do php -l "$f"; done
```

- There is no Composer setup, build step or linter config.
- To run a single test, comment out the other entries in the `$tests` array in `tests/run.php`. Each test is a named closure.
- The suite turns every PHP warning or deprecation into a failure.
- Code must stay PHP 7.4-compatible: no `match`, union types, nullsafe operator or `str_contains`. `/opt/homebrew/opt/php@8.0..8.4` are available for cross-version runs.

## Architecture

### Layers

- **`pandiwhmcs.php`** holds only the WHMCS hook functions (`pandiwhmcs_<Hook>`). Each hook is a thin wrapper of the form `Module::execute(__FUNCTION__, $params, fn)` → `Connection::run($params, fn(Connection $c))` → a call to a `lib/` class.
  - Do not add helper functions with the `pandiwhmcs_` prefix; WHMCS treats that prefix as a module entry point. Put helpers in `lib/` classes.
- **`lib/*.php`** are classes in the `WHMCS\Module\Registrar\Pandiwhmcs` namespace (PSR-4 from `lib/`, registered in `lib/init.php`):
  - `Module`: error handling, logging with secret masking, setting accessors.
  - `Connection`: one EPP session per hook call.
  - `Domain`, `Contact`, `Host`, `Dnssec`, `Poll`: the object operations.
  - `Helper`: normalization and parsing of the flattened responses.
  - `Store`: WHMCS DB access through Capsule.
  - `EppException`, `Logger`, and `PowerDns` (used by the crons only).
- **`lib/EPP/`** is php-epp2 2.0.0, re-namespaced and modified. Every change to it must be listed in `lib/EPP/README.md` and noted in the file header; GPL §5(a) requires this.

### Conventions that matter

- **Error handling.** `Connection::send()` throws `EppException` for any 2xxx result; `getCode()` is the EPP result code. Callers catch specific codes (e.g. 2201, 2301, 2302, 2303) where they mean something. Hooks never catch errors themselves; `Module::execute` converts them to `['error' => …]`. Pass `$rethrow = true` for hooks WHMCS expects to throw (CheckAvailability, GetDomainSuggestions).
- **Element order is schema-enforced.** Build frames in RFC schema order:
  - `<add>` → `<rem>` → `<chg>`;
  - within `<add>`/`<rem>`: ns → contact → status;
  - transfer: `<period>` before `<authInfo>`;
  - contact: postal fields → voice → fax → email → authInfo.
  The php-epp2 builder appends nodes in call order.
- **Flattened responses.** `Response::data()` flattens XML via `DOM\DOMTools`:
  - A single element becomes a scalar or associative array; repeated elements become a list. Always wrap with `Helper::toList()`.
  - Elements with a `type` attribute become keys like `contact@tech` and `postalInfo@loc`.
  - Attributes go into `'@name' => [...]`.
  - Extension data (`secDNS:infData`, `rgp:infData`) is merged into `infData`.
- **Extensions.** Only use an extension after `$connection->supports(Connection::EXT_…)`. The login requests only what the greeting announced; RFC 9154's `secure-authinfo` is requested only in the *generate* AuthInfo mode.
- **Diffs, not blind sets.** Nameserver and status updates do an info first and send only the difference. Strict registries return 2306 for adding something already present or removing something absent.
- **Secrets.** Pass any newly generated secret to `Module::addSecret()` so the WHMCS module log masks it. `Helper::redactXml()` masks `<pw>`/`<newPW>` in the transcript.

### Behaviour worth knowing

- **Sync and TransferSync**
  - Both drain the EPP poll queue first (`Poll::drain`). Outbound completed transfers set WHMCS status *Transferred Away*; inbound ones are left to TransferSync.
  - Sync also expires disclosed AuthInfo codes (`mod_pandiwhmcs_authinfo` table, created lazily).
- **GetEPPCode** sets a fresh random code at the registry by default (RFC 9154). The *registry* mode reads `authInfo` from domain info instead.
- **RenewDomain** sends no renew when the registry expiry already covers the WHMCS expiry + `regperiod`, e.g. after an auto-renew. In redemption it refuses and points to the RGP restore button.
- **RegisterDomain**
  - Creates missing external host objects (RFC 5731 host object model).
  - Skips in-bailiwick nameservers that do not exist yet and logs that.
  - Deletes the contacts it created if the domain create fails.

### Tests

- `tests/FakeRegistry.php` is a strict in-process registry. It validates every command against the IANA XSDs, enforces host objects, extension negotiation and the 2306 rules above, and keeps a poll queue.
- `FakeClient` extends the real `EPP\AbstractClient`, so login, negotiation, clTRID generation and response parsing are exercised. Only the TLS socket in `EPP\Client` is not.
- `tests/bootstrap.php` stubs the WHMCS functions and classes, including an in-memory Capsule.
- Add a test there for any hook or frame change.

## Repository notes

- Real PowerDNS settings go in `config.local.php` (gitignored), never in `config.php`.
- Comments and older commits are partly in Indonesian.
