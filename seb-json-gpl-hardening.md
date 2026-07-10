# Harden the GPL Licensing Position

This document contains the concrete changes recommended to strengthen the licensing position of `cameron1729/seb-json` while keeping the package licensed as `GPL-3.0-or-later`.

## 1. Add a conformance licensing notice

Create:

```text
tests/conformance/README.md
```

Suggested contents:

```markdown
# Conformance test licensing

The `seb-json` PHP implementation and the conformance test harnesses contained in this repository are licensed under GPL-3.0-or-later.

The conformance workflow downloads selected source files from the official Safe Exam Browser repositories at test time. Those files remain governed by their respective upstream licences:

- Safe Exam Browser for Windows: MPL-2.0
- Safe Exam Browser for macOS: MPL-1.1

The GPL licence applied to this repository does not purport to relicense any Safe Exam Browser source code downloaded by the workflow.

The upstream source is used solely to build temporary reference encoders that run the package's independently authored conformance vectors. Downloaded source, generated source fragments, and compiled reference executables are not included in this repository, distributed with the Composer package, or uploaded as workflow artifacts.

Only the resulting byte-encoding test data is retained and compared with the independently written PHP encoder.
```

## 2. Mark generated macOS source fragments clearly

The macOS conformance build extracts method bodies from upstream `SEBCryptor.m` into temporary `.inc` files.

Prepend a notice to every generated source fragment:

```c
/*
 * Extracted at test time from SafeExamBrowser/seb-mac SEBCryptor.m.
 *
 * This extracted code remains subject to MPL-1.1 and its original
 * copyright notices. It is generated only for an ephemeral
 * conformance build and must not be distributed as part of seb-json.
 */
```

The build script should add this notice automatically before appending extracted source.

This helps by:

- preserving provenance;
- making the upstream licence boundary explicit;
- avoiding any implication that generated SEB code is GPL-licensed;
- protecting against a future workflow change that accidentally publishes the build output.

## 3. Keep all upstream and combined build products ephemeral

The conformance workflow must continue to upload only result data such as:

```text
windows.tsv
macos.tsv
```

Do not upload or distribute:

- upstream SEB repository checkouts;
- copied SEB source files;
- generated `.inc` files;
- temporary combined source trees;
- Windows reference executables;
- macOS reference executables;
- compiled assemblies or binaries containing SEB code;
- conformance build directories.

Check all of the following:

- GitHub Actions artifact upload paths;
- release workflows;
- Packagist archive contents;
- `.gitattributes` export rules;
- `.gitignore`;
- temporary-directory cleanup;
- any future downloadable conformance bundles.

## 4. Make the Composer distribution boundary explicit

Ensure that the Composer package contains only the independent PHP implementation and normal project material.

Consider adding `.gitattributes` entries such as:

```gitattributes
/tests export-ignore
/.github export-ignore
/phpunit.xml.dist export-ignore
/phpstan.neon.dist export-ignore
```

Only add exclusions that match the intended Packagist distribution. The important point is that no downloaded or generated SEB code can ever enter a release archive.

The package should have no runtime or development dependency on the SEB repositories in `composer.json`.

## 5. Clarify the README description of implementation provenance

Avoid wording that sounds like a source-code translation, such as:

```text
This encoder was implemented by referencing the Safe Exam Browser source files directly.
```

Prefer:

```markdown
This encoder is independently implemented in PHP. Its behaviour was determined from the SEB documentation and official implementations and is verified byte-for-byte against the current Windows and macOS reference code.
```

This wording remains honest while distinguishing:

- learning required behaviour from the reference implementation;
- independently implementing that behaviour in PHP;
- copying or translating protected implementation code.

Also avoid describing the PHP library as an “exact port” or “direct port.”

Prefer terms such as:

- independent implementation;
- compatible implementation;
- byte-compatible encoder;
- conformance-tested implementation;
- implementation of the SEB-JSON format.

## 6. Add licence comments to conformance harnesses

The checked-in conformance harnesses are independently authored and can remain GPL-licensed.

Add the standard project header to those source files where appropriate:

```text
SPDX-FileCopyrightText: 2026 Cameron Ball
SPDX-License-Identifier: GPL-3.0-or-later
```

Do not add that header to:

- downloaded SEB source;
- extracted SEB source fragments;
- generated files containing SEB method bodies;
- files copied from the SEB repositories.

Those files must retain or clearly reference their original upstream licensing.

## 7. Document that test output is data, not bundled source

The conformance documentation should explain that TSV output contains only:

- test-vector identifiers;
- success or failure markers;
- encoded result bytes, usually represented as Base64 or another transport-safe format.

It must not include:

- source snippets;
- stack traces containing substantial source;
- decompiled code;
- generated implementation code;
- copied comments or method bodies.

The result files should be treated as interoperability test data.

## 8. Add a workflow guard against accidental artifact publication

Add a CI step that fails if known generated or compiled files are present in an upload or release directory.

For example, check for extensions or paths such as:

```text
*.inc
*.dll
*.exe
*.dylib
*.o
*.a
SEBCryptor.m
Json.cs
Keys.cs
seb-win-refactoring/
seb-mac/
```

The check should be scoped carefully so it does not reject harmless project files. Its purpose is to prevent accidentally distributing temporary upstream-derived build products.

## 9. Keep the licensing argument documented internally

A concise internal rationale can be added to the conformance README or a maintainer document:

```markdown
The published PHP encoder is independently authored and does not contain SEB source code. The official implementations are used only as external test oracles during ephemeral CI builds.

The conformance workflow does not distribute upstream source, generated source fragments, or combined reference binaries. It retains only the output data required to compare observable serialization behaviour.
```

This gives future maintainers context and makes it less likely that someone will accidentally weaken the licensing boundary.

## 10. Response if the SEB authors raise a concern

Do not immediately offer to relicense the PHP package.

First determine which component they are concerned about:

1. the independently written PHP encoder;
2. the checked-in conformance harness;
3. the temporary Windows reference build;
4. the temporary macOS reference build;
5. the retained TSV result data.

Suggested response:

```text
The published package does not include or distribute SEB source code or binaries. The PHP encoder is independently written and reproduces the observable SEB-JSON byte format without copying the structure or implementation of the SEB serializers.

The conformance workflow separately downloads the official source at test time and creates temporary reference executables. Those files remain under their upstream licences, are not included in the package, and are not uploaded or distributed; only the resulting test data is retained.

I therefore do not believe the PHP implementation is a modification or derivative of SEB. Nevertheless, I am happy to adjust or further separate the conformance infrastructure if there is a specific concern about how the reference executables are built.
```

## 11. Fallback order if challenged

Use this order rather than immediately changing the PHP library licence:

1. Keep the package under `GPL-3.0-or-later` and explain the boundary.
2. Modify the conformance build to address the specific concern.
3. Move the conformance runner into a separate repository.
4. Stop compiling upstream source and use expected-output vectors supplied or approved by SEB.
5. Ask SEB to publish normative conformance vectors.
6. Only relicense the PHP implementation if the SEB copyright holders specifically object to the independent implementation itself and cooperation is more important than retaining GPL.

## Implementation checklist

- [ ] Add `tests/conformance/README.md`.
- [ ] Document Windows upstream code as MPL-2.0.
- [ ] Document macOS upstream code as MPL-1.1.
- [ ] State that downloaded upstream code is not relicensed.
- [ ] Prepend an MPL provenance notice to generated macOS `.inc` files.
- [ ] Confirm generated files and reference binaries are never uploaded.
- [ ] Confirm Packagist archives cannot contain generated or downloaded SEB code.
- [ ] Review `.gitattributes` export exclusions.
- [ ] Review GitHub Actions artifact paths.
- [ ] Update README provenance wording.
- [ ] Avoid “direct port” and “exact port.”
- [ ] Add GPL SPDX headers only to independently authored harness files.
- [ ] Add a CI guard against accidental publication of build products.
- [ ] Keep only non-source TSV result data.
- [ ] Document the internal licensing rationale.
