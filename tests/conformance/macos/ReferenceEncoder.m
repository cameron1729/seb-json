/*
 * SPDX-FileCopyrightText: 2026 Cameron Ball
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

#import <Foundation/Foundation.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>

#define DDLogError(...) do { } while (0)

@interface NSString (SEBReferenceComparison)

- (NSComparisonResult)caseInsensitiveOrdinalCompare:(NSString *)string;

@end

@implementation NSString (SEBReferenceComparison)

#include "upstream-caseInsensitiveOrdinalCompare.inc"

@end


@interface NSUserDefaults (SEBReferenceDefaults)

- (NSDictionary *)getDefaultDictionaryForKey:(NSString *)dictionaryKey;

@end

@implementation NSUserDefaults (SEBReferenceDefaults)

- (NSDictionary *)getDefaultDictionaryForKey:(NSString *)dictionaryKey
{
    (void)dictionaryKey;
    return @{};
}

@end


@interface SEBReferenceEncoder : NSObject

- (NSString *)jsonStringForObject:(id)object;
- (NSDictionary *)getConfigKeyDictionaryForKey:(NSString *)dictionaryKey
                                     dictionary:(NSDictionary *)sourceDictionary
                               containedKeysPtr:(NSMutableDictionary **)containedKeysPtr
                                        jsonPtr:(NSMutableString **)jsonStringPtr
                        initializeContainedKeys:(BOOL)initializeContainedKeys;
- (NSArray *)getConfigKeyArrayForKey:(NSString *)dictionaryKey
                               array:(NSArray *)sourceArray
                    containedKeysPtr:(NSMutableDictionary **)containedKeysPtr
                             jsonPtr:(NSMutableString **)jsonStringPtr
             initializeContainedKeys:(BOOL)initializeContainedKeys;
- (void)presentPreferencesCorruptedError;
- (void)resetSEBUserDefaults;

@end

@implementation SEBReferenceEncoder

#include "upstream-jsonStringForObject.inc"
#include "upstream-getConfigKeyDictionaryForKey.inc"
#include "upstream-getConfigKeyArrayForKey.inc"

- (void)presentPreferencesCorruptedError
{
}

- (void)resetSEBUserDefaults
{
}

@end


static id BuildValue(NSDictionary *node, NSString **errorMessage);

static NSString *RequiredString(NSDictionary *node, NSString *key, NSString **errorMessage)
{
    id value = node[key];

    if (![value isKindOfClass:NSString.class]) {
        *errorMessage = [NSString stringWithFormat:@"Vector is missing string field %@.", key];
        return nil;
    }

    return value;
}

static NSString *DecodeString(NSDictionary *node, NSString *key, NSString **errorMessage)
{
    NSString *base64 = RequiredString(node, key, errorMessage);

    if (base64 == nil) {
        return nil;
    }

    NSData *bytes = [[NSData alloc] initWithBase64EncodedString:base64 options:0];

    if (bytes == nil) {
        *errorMessage = [NSString stringWithFormat:@"Invalid Base64 UTF-8 field %@.", key];
        return nil;
    }

    NSString *value = [[NSString alloc] initWithData:bytes encoding:NSUTF8StringEncoding];

    if (value == nil) {
        *errorMessage = [NSString stringWithFormat:@"Invalid UTF-8 field %@.", key];
        return nil;
    }

    return value;
}

static NSNumber *BuildInteger(NSDictionary *node, NSString **errorMessage)
{
    NSString *decimal = RequiredString(node, @"decimal", errorMessage);
    long long value = 0;
    NSScanner *scanner = [NSScanner scannerWithString:decimal ?: @""];

    if (decimal == nil || ![scanner scanLongLong:&value] || !scanner.isAtEnd) {
        *errorMessage = [NSString stringWithFormat:@"Invalid integer vector: %@", decimal];
        return nil;
    }

    return [NSNumber numberWithLongLong:value];
}

static NSNumber *BuildFloat(NSDictionary *node, NSString **errorMessage)
{
    NSString *hex = RequiredString(node, @"hex", errorMessage);
    unsigned long long scannedBits = 0;
    NSScanner *scanner = [NSScanner scannerWithString:hex ?: @""];

    if (hex.length != 16 || ![scanner scanHexLongLong:&scannedBits] || !scanner.isAtEnd) {
        *errorMessage = [NSString stringWithFormat:@"Invalid IEEE-754 vector: %@", hex];
        return nil;
    }

    uint64_t bits = scannedBits;
    double value = 0;
    memcpy(&value, &bits, sizeof(value));

    return [NSNumber numberWithDouble:value];
}

static NSArray *BuildList(NSDictionary *node, NSString **errorMessage)
{
    id items = node[@"items"];

    if (![items isKindOfClass:NSArray.class]) {
        *errorMessage = @"List vector has no items.";
        return nil;
    }

    NSMutableArray *result = [NSMutableArray arrayWithCapacity:[items count]];

    for (id item in items) {
        if (![item isKindOfClass:NSDictionary.class]) {
            *errorMessage = @"List item is not a value node.";
            return nil;
        }

        id value = BuildValue(item, errorMessage);

        if (value == nil) {
            return nil;
        }

        [result addObject:value];
    }

    return [result copy];
}

static NSDictionary *BuildMap(NSDictionary *node, NSString **errorMessage)
{
    id entries = node[@"entries"];

    if (![entries isKindOfClass:NSArray.class]) {
        *errorMessage = @"Map vector has no entries.";
        return nil;
    }

    NSMutableDictionary *result = [NSMutableDictionary dictionaryWithCapacity:[entries count]];

    for (id entry in entries) {
        if (![entry isKindOfClass:NSDictionary.class]) {
            *errorMessage = @"Map entry is not an object.";
            return nil;
        }

        NSString *key = DecodeString(entry, @"key", errorMessage);
        id valueNode = entry[@"value"];

        if (key == nil || ![valueNode isKindOfClass:NSDictionary.class]) {
            *errorMessage = *errorMessage ?: @"Map entry has no value.";
            return nil;
        }

        id value = BuildValue(valueNode, errorMessage);

        if (value == nil || result[key] != nil) {
            *errorMessage = *errorMessage ?: @"Map contains a duplicate key.";
            return nil;
        }

        result[key] = value;
    }

    return [result copy];
}

static id BuildValue(NSDictionary *node, NSString **errorMessage)
{
    NSString *type = RequiredString(node, @"type", errorMessage);

    if ([type isEqualToString:@"string"]) {
        return DecodeString(node, @"base64", errorMessage);
    }
    if ([type isEqualToString:@"integer"]) {
        return BuildInteger(node, errorMessage);
    }
    if ([type isEqualToString:@"float"]) {
        return BuildFloat(node, errorMessage);
    }
    if ([type isEqualToString:@"boolean"]) {
        id value = node[@"boolean"];

        if (![value isKindOfClass:NSNumber.class]) {
            *errorMessage = @"Boolean vector has no value.";
            return nil;
        }

        return [NSNumber numberWithBool:[value boolValue]];
    }
    if ([type isEqualToString:@"null"]) {
        return NSNull.null;
    }
    if ([type isEqualToString:@"list"]) {
        return BuildList(node, errorMessage);
    }
    if ([type isEqualToString:@"map"]) {
        return BuildMap(node, errorMessage);
    }

    *errorMessage = [NSString stringWithFormat:@"Unknown vector type: %@", type];
    return nil;
}

static NSString *EncodeValue(SEBReferenceEncoder *encoder, id value)
{
    if ([value isKindOfClass:NSDictionary.class]) {
        NSMutableDictionary *containedKeys = [NSMutableDictionary new];
        NSMutableString *json = [NSMutableString new];
        [encoder getConfigKeyDictionaryForKey:@"vector"
                                   dictionary:value
                             containedKeysPtr:&containedKeys
                                      jsonPtr:&json
                      initializeContainedKeys:NO];
        return [json copy];
    }

    if ([value isKindOfClass:NSArray.class]) {
        NSMutableDictionary *containedKeys = [NSMutableDictionary new];
        NSMutableString *json = [NSMutableString new];
        [encoder getConfigKeyArrayForKey:@"vector"
                                   array:value
                        containedKeysPtr:&containedKeys
                                 jsonPtr:&json
                 initializeContainedKeys:NO];
        return [json copy];
    }

    return [encoder jsonStringForObject:value];
}

static BOOL ContainsNull(id value)
{
    if (value == NSNull.null) {
        return YES;
    }

    if ([value isKindOfClass:NSArray.class]) {
        for (id item in value) {
            if (ContainsNull(item)) {
                return YES;
            }
        }
    }

    if ([value isKindOfClass:NSDictionary.class]) {
        for (id item in [value allValues]) {
            if (ContainsNull(item)) {
                return YES;
            }
        }
    }

    return NO;
}

int main(int argc, const char *argv[])
{
    @autoreleasepool {
        if (argc != 3) {
            fprintf(stderr, "Usage: reference <vectors> <results>\n");
            return 2;
        }

        NSData *contents = [NSData dataWithContentsOfFile:[NSString stringWithUTF8String:argv[1]]];
        NSError *error = nil;
        id document = contents == nil ? nil : [NSJSONSerialization JSONObjectWithData:contents options:0 error:&error];
        id vectors = [document isKindOfClass:NSDictionary.class] ? document[@"vectors"] : nil;

        if (![vectors isKindOfClass:NSArray.class]) {
            fprintf(stderr, "Could not read vectors: %s\n", error.localizedDescription.UTF8String ?: "invalid document");
            return 2;
        }

        SEBReferenceEncoder *encoder = [SEBReferenceEncoder new];
        NSMutableSet *names = [NSMutableSet new];
        NSMutableString *results = [NSMutableString new];

        for (id vector in vectors) {
            NSString *name = [vector isKindOfClass:NSDictionary.class] ? vector[@"name"] : nil;
            id valueNode = [vector isKindOfClass:NSDictionary.class] ? vector[@"value"] : nil;
            NSString *errorMessage = nil;

            if (![name isKindOfClass:NSString.class] || name.length == 0 || [names containsObject:name] ||
                ![valueNode isKindOfClass:NSDictionary.class]) {
                fprintf(stderr, "Every vector must have a unique non-empty name and a value.\n");
                return 2;
            }

            id value = BuildValue(valueNode, &errorMessage);

            if (value == nil) {
                fprintf(stderr, "Invalid vector %s: %s\n", name.UTF8String, errorMessage.UTF8String);
                return 2;
            }

            [names addObject:name];

            if (ContainsNull(value)) {
                NSData *reason = [@"macOS property lists do not support null" dataUsingEncoding:NSUTF8StringEncoding];
                [results appendFormat:@"%@\tunsupported\t%@\n", name, [reason base64EncodedStringWithOptions:0]];
                continue;
            }

            NSString *encoded = EncodeValue(encoder, value);
            NSData *bytes = [encoded dataUsingEncoding:NSUTF8StringEncoding];
            NSString *base64 = [bytes base64EncodedStringWithOptions:0];
            [results appendFormat:@"%@\tok\t%@\n", name, base64];
        }

        NSString *resultsPath = [NSString stringWithUTF8String:argv[2]];

        if (![results writeToFile:resultsPath atomically:YES encoding:NSUTF8StringEncoding error:&error]) {
            fprintf(stderr, "Could not write results: %s\n", error.localizedDescription.UTF8String);
            return 2;
        }
    }

    return 0;
}
