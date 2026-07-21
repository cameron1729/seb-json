# SEB-JSON

[![CI](https://github.com/cameron1729/seb-json/actions/workflows/ci.yml/badge.svg)](https://github.com/cameron1729/seb-json/actions/workflows/ci.yml)
[![SEB for Windows v3.10.2 / SEB for macOS 3.6.1](https://github.com/cameron1729/seb-json/actions/workflows/conformance.yml/badge.svg)](https://github.com/cameron1729/seb-json/actions/workflows/conformance.yml)
[![PHP >=8.1](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Tests](https://img.shields.io/badge/tests-PHPUnit-3f9f3f)](https://phpunit.de/)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](https://github.com/cameron1729/seb-json/actions/workflows/ci.yml)
[![Static Analysis](https://img.shields.io/badge/PHPStan-level%20max-2f74c0)](https://phpstan.org/)
[![Code Style](https://img.shields.io/badge/code%20style-PSR--12-4c1)](https://www.php-fig.org/psr/psr-12/)
[![Licence](https://img.shields.io/badge/licence-GPL--3.0--or--later-blue)](LICENSE)

A small PHP encoder which converts native PHP data structures to Safe Exam Browser's JSON-ish serialisation format.

Unlike PHP's `json_encode`, this package produces strings using the byte representation expected by [Safe Exam Browser](https://safeexambrowser.org/) when calculating [Config Keys](https://safeexambrowser.org/developer/seb-config-key.html). It does not understand `.seb` configuration semantics and it does not canonicalise input: callers remain responsible for the shape and meaning of the value they pass to the encoder.

## Installation

```sh
composer require cameron1729/seb-json
```

## Usage

```php
use cameron1729\SebJson\SebJson;

$encoded = SebJson::encode([
    'browserMessagingSocket' => 'ws:\localhost:8706',
    'sendBrowserExamKey' => true,
]);
```

Output:

```text
{"browserMessagingSocket":"ws:\localhost:8706","sendBrowserExamKey":true}
```

## Why This Exists

Safe Exam Browser does not hash ordinary JSON when calculating a Config Key. It hashes a deterministic, JSON-ish byte string commonly referred to as [SEB-JSON](https://safeexambrowser.org/developer/seb-config-key.html).

<!-- TODO(#3): Once https://github.com/SafeExamBrowser/SafeExamBrowser-Website/pull/25 is merged and deployed, update the SEB-JSON link above to https://safeexambrowser.org/developer/seb-config-key.html#seb-json. -->

Despite its name, SEB-JSON is not standard JSON and is not necessarily valid JSON. Most importantly, SEB writes string contents without JSON character escaping. PHP's `json_encode` cannot be configured to do this: quotation marks, backslashes, and control characters are still escaped, changing the bytes that are ultimately hashed.

The [SEB Config Key documentation](https://safeexambrowser.org/developer/seb-config-key.html) explicitly says not to add character escaping. The [current SEB for Windows implementation](https://github.com/SafeExamBrowser/seb-win-refactoring/blob/v3.10.2/SafeExamBrowser.Configuration/ConfigurationData/Json.cs) does this literally: it writes a string by writing the opening quote, the raw string contents, and the closing quote.

For example, the PHP value:

```php
$value = ['rule' => 'say "hello"'];
```

has these byte representations:

```text
Standard JSON: {"rule":"say \"hello\""}
SEB-JSON:      {"rule":"say "hello""}
```

This distinction matters because Config Keys depend on the exact bytes. Even one additional backslash produces a different hash.

This package exists because implementations in other projects have repeatedly tried to produce SEB-JSON with ordinary JSON encoders, or by processing ordinary JSON output afterwards. That is easy to get subtly wrong. The encoder in this package is independently authored in PHP, with behaviour determined from the SEB documentation and official implementations. It remains limited to value encoding.

[MDL-78086](https://moodle.atlassian.net/browse/MDL-78086) documents one practical example in Moodle core. Moodle's SEB access rule currently uses `json_encode` with `JSON_UNESCAPED_SLASHES` and `JSON_UNESCAPED_UNICODE`. Those flags preserve forward slashes and characters outside ASCII such as `侃睦`. To preserve literal backslashes, Moodle uses an esoteric workaround where every backslash in a string value is replaced with `ؼҷҍԴ` before encoding, then that marker is changed back to a backslash afterwards (🙉). Quotes and control characters are still escaped normally, so the resulting bytes can still differ from the SEB-JSON bytes expected by Safe Exam Browser.

<!-- TODO(#4): Once MDL-78086 is integrated and Moodle uses this package, rewrite the paragraph above as historical context. -->

Other public implementations show the same trap:

- The [`certible/seb-node`](https://github.com/certible/seb-node/blob/main/src/config-key.ts) implementation notes that SEB says not to escape strings, but still uses `JSON.stringify` so that the output remains valid JSON
- The [`Chiogros/nSEA`](https://github.com/Chiogros/nSEA/blob/main/nsea.py) implementation uses Python's `json.dumps` and then removes spaces and newlines from the whole result, which still leaves JSON escaping and can alter whitespace inside setting values

## Supported Values

`SebJson::encode` accepts the following native PHP values:

- Associative arrays for SEB dictionaries
- Associative array keys are encoded in the order provided by the caller
- List arrays for SEB arrays
- Strings
- Integers from `-2147483648` through `2147483647`
- Finite floating point numbers with an unambiguous SEB representation across platforms
- Booleans

String values and string keys must contain valid UTF-8.

PHP objects, including `JsonSerializable` objects, are not accepted. Convert objects to plain arrays or scalar values before encoding.

This is intentional: SEB-JSON serialises property list values and does not define a general object serialisation mechanism comparable to PHP's `json_encode`.

Recursive PHP arrays are not accepted because SEB-JSON has no representation for references. Repeated references which do not form a cycle are encoded by value at each position.

Resources, `null`, integers outside the shared range, `NAN`, `INF` and floats represented differently by the Windows and macOS SEB implementations throw `InvalidArgumentException`.

`null` is intentionally rejected: it is not a property list value and the official Windows and macOS serialisers do not produce the same representation for it.

Common SEB values such as `0.1`, `0.2` and `1.0` have a shared representation and are accepted. Ambiguous high precision values and scientific notation are rejected instead of silently producing bytes which differ between platforms. The underlying ambiguity is tracked upstream in [Safe Exam Browser issue #1495](https://github.com/SafeExamBrowser/seb-win-refactoring/issues/1495). Float output does not depend on PHP's `precision` setting.

PHP arrays cannot distinguish an empty list from an empty SEB dictionary, so `[]` is encoded as an empty list.

## Out of Scope

This package deliberately does not:

- Parse `.seb` property list files
- Define the SEB configuration structure
- Decide which SEB settings should be included
- Sort associative array keys
- Remove `originatorVersion`
- Omit empty SEB dictionaries
- Calculate SHA-256 hashes
- Calculate Config Keys
- Calculate Browser Exam Keys

It only converts the PHP value it is given to SEB-JSON. For the meaning of SEB configuration structures, refer to the [Safe Exam Browser developer documentation](https://safeexambrowser.org/developer/) and [source code](https://github.com/SafeExamBrowser).

## Compatibility

### PHP and Moodle

The primary use case for this package is Moodle, but it works with any PHP project. Moodle is relevant to this compatibility policy only because its supported releases determine the oldest PHP version explicitly supported. This package supports every PHP minor from that version through the latest stable PHP release. The Moodle releases considered are those [still receiving general bug fixes](https://moodledev.io/general/releases#version-support), plus the newest released LTS. Other Moodle releases may continue to work, but are not part of the tested compatibility contract.

### Safe Exam Browser

[Safe Exam Browser supports only its latest release and removes older releases after a grace period](https://safeexambrowser.org/download_releases_en.html). This package follows the same model for each platform and targets the latest stable releases of SEB for Windows and macOS. The versioned conformance badge identifies the exact releases currently verified.

The SEB-JSON encoding algorithm appears stable across other modern SEB releases, so other versions may also work. They are not part of this package's tested compatibility contract, and no compatibility guarantee is made for them.

`SebJson::encode` is an independent PHP implementation of the SEB-JSON value encoder. Automated conformance tests compare its output byte for byte with output from the official Windows and macOS serialisation code. Values on which the official implementations disagree are rejected, and new stable SEB releases become supported only after those tests pass.

Reference implementations:

- SEB for Windows v3.10.2: <https://github.com/SafeExamBrowser/seb-win-refactoring/blob/v3.10.2/SafeExamBrowser.Configuration/ConfigurationData/Json.cs>
- SEB for macOS 3.6.1: <https://github.com/SafeExamBrowser/seb-mac/blob/3.6.1/Classes/Cryptography/SEBCryptor.m>

This does not target older `seb-win` code paths that use .NET's [`JavaScriptSerializer`](https://learn.microsoft.com/en-us/dotnet/api/system.web.script.serialization.javascriptserializer.serialize?view=netframework-4.8.1), which produces ordinary JSON strings.

SEB-JSON should be treated as a byte serialisation format, not as ordinary JSON.

## Licence

GPL-3.0-or-later.
