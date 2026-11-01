#!/usr/bin/env python3
"""Extract data literals only; never execute SQL from a supplied backup."""
import argparse
import gzip
import json
import re
from pathlib import Path

TABLES = {
    'brands': ['id', 'slug', 'name', 'short_note', 'description', 'meta_title', 'meta_description', 'sort_order'],
    'products': ['id', 'sku', 'brand_id', 'name', 'gender', 'fragrance_group', 'top_notes', 'heart_notes', 'base_notes', 'release_year', 'description', 'meta_title', 'meta_description', 'sort_order', 'in_stock_msk', 'in_stock_spb'],
    'product_prices': ['id', 'product_id', 'volume_ml', 'price', 'promo_price', 'promo_until', 'stock'],
}
ESCAPES = {'0': '\0', 'n': '\n', 'r': '\r', 't': '\t', 'b': '\b', 'Z': '\x1a'}
TOKEN = re.compile(r"\s*(?:'((?:[^'\\]|\\.|'')*)'|(NULL|-?\d+(?:\.\d+)?)|([(),;]))", re.S)


def parse_values(text, columns):
    pos, rows, row, expecting = 0, [], None, '('
    while pos < len(text):
        m = TOKEN.match(text, pos)
        if not m:
            raise ValueError('Non-literal SQL in selected INSERT')
        pos = m.end()
        string, number, punct = m.groups()
        if expecting == '(' and punct == '(':
            row, expecting = [], 'value'
        elif expecting == 'value' and (string is not None or number is not None):
            if string is not None:
                value = re.sub(r"\\(.)|'(')", lambda x: ESCAPES.get(x[1], x[1]) if x[1] is not None else "'", string, flags=re.S)
            else:
                value = None if number == 'NULL' else number
            row.append(value)
            expecting = 'separator'
        elif expecting == 'separator' and punct == ',':
            expecting = 'value'
        elif expecting == 'separator' and punct == ')':
            if len(row) != len(columns):
                raise ValueError('Unexpected column count')
            rows.append(dict(zip(columns, row)))
            expecting = 'next'
        elif expecting == 'next' and punct == ',':
            expecting = '('
        elif expecting == 'next' and punct == ';':
            return rows
        else:
            raise ValueError('Unexpected SQL token')
    raise ValueError('Incomplete INSERT')


def extract(sql):
    result = {name: [] for name in TABLES}
    for table, expected in TABLES.items():
        schema = re.search(r'CREATE TABLE `' + table + r'`\s*\((.*?)\) ENGINE', sql, re.S)
        if not schema or re.findall(r'^  `([^`]+)`', schema[1], re.M) != expected:
            raise ValueError('Unexpected schema for ' + table)
        inserts = list(re.finditer(r'^INSERT INTO `' + table + r'` VALUES\s*', sql, re.M))
        if not inserts:
            raise ValueError('No data for ' + table)
        for statement in inserts:
            result[table].extend(parse_values(sql[statement.end():], expected))
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('dump', type=Path)
    parser.add_argument('--output', type=Path, default=Path(__file__).resolve().parents[1] / 'storage/imports/catalog.json')
    args = parser.parse_args()
    opener = gzip.open if args.dump.suffix == '.gz' else open
    with opener(args.dump, 'rt', encoding='utf-8') as source:
        catalog = extract(source.read())
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open('x', encoding='utf-8') as output:
        json.dump(catalog, output, ensure_ascii=False)
    args.output.chmod(0o600)
    print(json.dumps({table: len(rows) for table, rows in catalog.items()}))
