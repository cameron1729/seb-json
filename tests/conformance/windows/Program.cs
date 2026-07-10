/*
 * SPDX-FileCopyrightText: 2026 Cameron Ball
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Reflection;
using System.Runtime.Serialization;
using System.Runtime.Serialization.Json;
using System.Text;
using SafeExamBrowser.Configuration.ConfigurationData;

internal static class Program
{
    private static readonly Encoding StrictUtf8 = new UTF8Encoding(false, true);

    private static int Main(string[] args)
    {
        if (args.Length != 2)
        {
            Console.Error.WriteLine("Usage: Reference.exe <vectors> <results>");
            return 2;
        }

        var serialize = typeof(Json).GetMethod(
            "Serialize",
            BindingFlags.NonPublic | BindingFlags.Static,
            null,
            new[] { typeof(object), typeof(StreamWriter) },
            null);

        if (serialize == null)
        {
            Console.Error.WriteLine("Could not find the upstream Json.Serialize value method.");
            return 2;
        }

        var vectors = ReadVectors(args[0]);
        var names = new HashSet<string>(StringComparer.Ordinal);
        var results = new List<string>();

        foreach (var vector in vectors)
        {
            if (string.IsNullOrEmpty(vector.Name) || vector.Value == null || !names.Add(vector.Name))
            {
                throw new InvalidDataException("Every vector must have a unique non-empty name and a value.");
            }

            var value = BuildValue(vector.Value);

            using (var stream = new MemoryStream())
            {
                using (var writer = new StreamWriter(stream, new UTF8Encoding(false), 1024, true))
                {
                    serialize.Invoke(null, new[] { value, writer });
                    writer.Flush();
                }

                results.Add(vector.Name + "\tok\t" + Convert.ToBase64String(stream.ToArray()));
            }
        }

        File.WriteAllLines(args[1], results, new UTF8Encoding(false));
        return 0;
    }

    private static List<Vector> ReadVectors(string file)
    {
        var serializer = new DataContractJsonSerializer(typeof(VectorDocument));

        using (var stream = File.OpenRead(file))
        {
            var document = serializer.ReadObject(stream) as VectorDocument;

            if (document == null || document.Vectors == null)
            {
                throw new InvalidDataException("Could not read conformance vectors.");
            }

            return document.Vectors;
        }
    }

    private static object BuildValue(ValueNode node)
    {
        switch (node.Type)
        {
            case "string":
                return StrictUtf8.GetString(Convert.FromBase64String(Required(node.Base64, node.Type)));
            case "integer":
                return BuildInteger(Required(node.Decimal, node.Type));
            case "float":
                return BuildFloat(Required(node.Hex, node.Type));
            case "boolean":
                return node.Boolean ?? throw new InvalidDataException("Boolean vector has no value.");
            case "null":
                return null;
            case "list":
                return BuildList(node.Items);
            case "map":
                return BuildMap(node.Entries);
            default:
                throw new InvalidDataException($"Unknown vector type: {node.Type ?? "<null>"}");
        }
    }

    private static object BuildInteger(string value)
    {
        if (int.TryParse(value, NumberStyles.Integer, CultureInfo.InvariantCulture, out var integer))
        {
            return integer;
        }

        if (long.TryParse(value, NumberStyles.Integer, CultureInfo.InvariantCulture, out var longInteger))
        {
            return longInteger;
        }

        throw new InvalidDataException($"Invalid integer vector: {value}");
    }

    private static double BuildFloat(string value)
    {
        if (!ulong.TryParse(value, NumberStyles.HexNumber, CultureInfo.InvariantCulture, out var bits))
        {
            throw new InvalidDataException($"Invalid IEEE-754 vector: {value}");
        }

        return BitConverter.Int64BitsToDouble(unchecked((long)bits));
    }

    private static List<object> BuildList(List<ValueNode> items)
    {
        if (items == null)
        {
            throw new InvalidDataException("List vector has no items.");
        }

        var result = new List<object>();

        foreach (var item in items)
        {
            result.Add(BuildValue(item));
        }

        return result;
    }

    private static Dictionary<string, object> BuildMap(List<MapEntry> entries)
    {
        if (entries == null)
        {
            throw new InvalidDataException("Map vector has no entries.");
        }

        var result = new Dictionary<string, object>(StringComparer.Ordinal);

        foreach (var entry in entries)
        {
            var key = StrictUtf8.GetString(Convert.FromBase64String(Required(entry.Key, "map key")));
            result.Add(key, BuildValue(entry.Value ?? throw new InvalidDataException("Map entry has no value.")));
        }

        return result;
    }

    private static string Required(string value, string field)
    {
        return value ?? throw new InvalidDataException($"{field} vector is missing data.");
    }
}

[DataContract]
internal sealed class VectorDocument
{
    [DataMember(Name = "vectors")]
    public List<Vector> Vectors;
}

[DataContract]
internal sealed class Vector
{
    [DataMember(Name = "name")]
    public string Name;

    [DataMember(Name = "value")]
    public ValueNode Value;
}

[DataContract]
internal sealed class ValueNode
{
    [DataMember(Name = "type")]
    public string Type;

    [DataMember(Name = "base64", EmitDefaultValue = false)]
    public string Base64;

    [DataMember(Name = "decimal", EmitDefaultValue = false)]
    public string Decimal;

    [DataMember(Name = "hex", EmitDefaultValue = false)]
    public string Hex;

    [DataMember(Name = "boolean", EmitDefaultValue = false)]
    public bool? Boolean;

    [DataMember(Name = "items", EmitDefaultValue = false)]
    public List<ValueNode> Items;

    [DataMember(Name = "entries", EmitDefaultValue = false)]
    public List<MapEntry> Entries;
}

[DataContract]
internal sealed class MapEntry
{
    [DataMember(Name = "key")]
    public string Key;

    [DataMember(Name = "value")]
    public ValueNode Value;
}
