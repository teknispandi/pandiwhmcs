# lib/EPP: bundled php-epp2

This directory contains a modified copy of **php-epp2 2.0.0**
(<https://github.com/AfriCC/php-epp2>), Copyright (c) Günter Grodotzki and
contributors. It is licensed under the GNU General Public License v3.0 or
later; see `LICENSE` in the root of this repository.

## Modifications

As required by section 5(a) of the GPL v3, the changes to the upstream code are listed below.

**2023 (Ahmad Rofai, PANDI)**

- Namespace changed from `AfriCC\EPP` to
  `WHMCS\Module\Registrar\Pandiwhmcs\EPP`, so that WHMCS autoloads the
  library from the module directory.

**2026-11-16 (PANDI)**

- `Client::setupContext()`
  - The server certificate is verified by default (`verify_peer`); upstream hard-coded `verify_peer => false` and `allow_self_signed => true`, which RFC 5734 section 9 does not allow.
  - Only TLS 1.2 and 1.3 are offered (BCP 195 / RFC 9325).
  - SNI is enabled, the peer name is set, and TLS compression is disabled.
  - The context is created with `stream_context_create()` instead of `stream_context_set_option()`, which is deprecated in PHP 8.4.
- `Client::setupSocket()`: TLS handshake failures (for example "certificate verify failed") are included in the exception message.
- `Client::connect()`: calls `negotiateServices()` with the greeting.
- `Client`: frame lengths are counted in bytes with `strlen()`/`substr()` instead of `mb_strlen(…, 'ASCII')`/`mb_substr()` (RFC 5734 section 4).
- `Client::send()`: fixed an exception that was built with `sprintf` arguments but without calling `sprintf`.
- `AbstractClient`
  - New `verify_peer` option (default `true`).
  - `negotiateServices()`: the `<login>` only asks for objURIs/extURIs that the server greeting announced (RFC 5730 section 2.9.1.1).
  - New getters for the greeting services and the negotiated extensions.
  - A frame observer hook, used for redacted logging.
  - Client transaction IDs include random bytes, so they are unique across processes.
- `DOM\DOMElement::hasChildNodes()`: declares the `bool` return type (PHP 8.1 deprecation).
- Explicit nullable parameter types (`?ObjectSpec`, `?DOMDocument`) in `AbstractClient`, `Client`, `HTTPClient`, `AbstractFrame`, `Frame\Hello`, `Frame\ResponseFactory` and `Frame\Command\Logout` (PHP 8.4 deprecation).
