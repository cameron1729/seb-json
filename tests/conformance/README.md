# Conformance Test Licensing

The PHP package and independently authored test material in this repository are licensed under GPL-3.0-or-later.

The conformance workflow downloads code from the official Safe Exam Browser repositories at test time. Different releases may use different versions of the Mozilla Public License. The currently pinned source files and their controlling licence notices are:

- [SEB for Windows v3.10.1 source and licence notice](https://github.com/SafeExamBrowser/seb-win-refactoring/blob/v3.10.1/SafeExamBrowser.Configuration/ConfigurationData/Json.cs)
- [SEB for macOS 3.6.1 source and licence notice](https://github.com/SafeExamBrowser/seb-mac/blob/3.6.1/Classes/Cryptography/SEBCryptor.m)

The notice in each downloaded source file governs that file. The workflow does not relicense upstream code, and generated macOS fragments preserve the header from the downloaded source file.

Downloaded source, generated fragments and compiled reference executables exist only for the temporary conformance build. They are not tracked in this repository, included in the Composer package or uploaded as workflow artifacts.

The workflow uploads only TSV result data containing:

- Test vector identifiers
- Success or unsupported status markers
- Encoded result bytes or diagnostic text represented as Base64

The verification job validates this data and compares it with the independently authored PHP encoder. The result data does not contain source snippets, generated implementation code or compiled code.
