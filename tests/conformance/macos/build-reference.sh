#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Cameron Ball
# SPDX-License-Identifier: GPL-3.0-or-later

set -euo pipefail

if [[ $# -ne 3 ]]; then
    echo "Usage: build-reference.sh <SEBCryptor.m> <vectors> <results>" >&2
    exit 2
fi

source_file=$1
vectors_file=$2
results_file=$3
script_dir=$(cd "$(dirname "$0")" && pwd)
build_dir=${RUNNER_TEMP:-/tmp}/seb-json-macos-reference
binary=$build_dir/reference
source_header=$build_dir/upstream-source-header.inc

mkdir -p "$build_dir"

extract_source_header() {
    awk '
        /^[[:space:]]*#import[[:space:]]/ {
            found = 1
            exit
        }
        $0 !~ /^[[:space:]]*(\/\/.*)?$/ {
            exit 1
        }
        {
            print
        }
        END {
            if (!found) {
                exit 1
            }
        }
    ' "$source_file"
}

extract_method() {
    local start=$1
    local destination=$2

    {
        cat "$source_header"
        printf '// The method below was extracted from this upstream file for an ephemeral conformance build.\n\n'
        awk -v start="$start" '
            $0 == start {
                found++
                capture = 1
            }
            capture {
                print
            }
            capture && /^}$/ {
                capture = 0
                exit
            }
            END {
                if (found != 1 || capture) {
                    exit 1
                }
            }
        ' "$source_file"
    } > "$destination"
}

extract_source_header > "$source_header"

extract_method \
    '- (NSComparisonResult)caseInsensitiveOrdinalCompare:(NSString *)string {' \
    "$build_dir/upstream-caseInsensitiveOrdinalCompare.inc"
extract_method \
    '- (NSString *)jsonStringForObject:(id)object' \
    "$build_dir/upstream-jsonStringForObject.inc"
extract_method \
    '- (NSDictionary *) getConfigKeyDictionaryForKey:(NSString *)dictionaryKey' \
    "$build_dir/upstream-getConfigKeyDictionaryForKey.inc"
extract_method \
    '- (NSArray *) getConfigKeyArrayForKey:(NSString *)dictionaryKey' \
    "$build_dir/upstream-getConfigKeyArrayForKey.inc"

xcrun clang \
    -fobjc-arc \
    -Wall \
    -Wextra \
    -framework Foundation \
    -I "$build_dir" \
    "$script_dir/ReferenceEncoder.m" \
    -o "$binary"

"$binary" "$vectors_file" "$results_file"
