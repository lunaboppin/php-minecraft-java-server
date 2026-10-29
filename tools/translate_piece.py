#!/usr/bin/env python3
"""Rough translation of decompiled StructurePiece Java into this repository's PHP piece API.

Usage: translate_piece.py Piece.java > draft.php

It rewrites the mechanical parts (block states with properties, generateBox/placeBlock/
fillColumnDown calls, locals and loops) so that a port starts from vanilla's exact numbers; the
output is a draft to finish and check by hand, not generated source.
"""
import re
import sys

PROPERTY_VALUES = {
    'Direction': str.lower, 'StairsShape': str.lower, 'Half': str.lower, 'SlabType': str.lower,
    'DoorHingeSide': str.lower, 'DoubleBlockHalf': str.lower, 'RailShape': str.lower, 'Axis': str.lower,
    'ChestType': str.lower, 'AttachFace': str.lower, 'RedstoneSide': str.lower, 'WallSide': str.lower,
}


def block_name(constant):
    return constant.lower()


def translate(java):
    body = re.sub(r'^(import|package) .*$', '', java, flags=re.M)
    locals_ = set(re.findall(r'\b(?:int|boolean|float|double|long|BlockState|BlockPos|Direction|RandomSource|var|final int|final boolean)\s+([a-z]\w*)\s*[=;:,)]', body))
    locals_ |= set(re.findall(r'final \w+(?:<[^>]*>)? ([a-z]\w*)[,)]', body))
    out = body
    # Block states: Blocks.X.defaultBlockState() and DYED_TERRACOTTA.orange()
    out = re.sub(r'Blocks\.DYED_TERRACOTTA\.(\w+)\(\)\.defaultBlockState\(\)', lambda m: f"$c->state('{m.group(1)}_terracotta')", out)
    out = re.sub(r'Blocks\.(\w+)\.defaultBlockState\(\)', lambda m: f"$c->state('{block_name(m.group(1))}')", out)

    # .setValue(Cls.PROP, Value) chains -> $c->with(expr, 'prop', 'value')
    def set_value(text):
        pattern = re.compile(r'\.setValue\(\s*\w+\.(\w+)\s*,\s*([^()]+?|\w+\.\w+\(\))\s*\)')
        while True:
            m = pattern.search(text)
            if not m:
                return text
            # find the expression the call applies to: walk back over a balanced token
            end = m.start()
            depth = 0
            i = end - 1
            while i >= 0:
                ch = text[i]
                if ch == ')':
                    depth += 1
                elif ch == '(':
                    if depth == 0:
                        break
                    depth -= 1
                elif depth == 0 and ch in ' ,=\n\t[':
                    break
                i -= 1
            target = text[i + 1:end]
            prop = m.group(1).lower()
            value = m.group(2).strip()
            vm = re.match(r'(\w+)\.(\w+)$', value)
            if vm and vm.group(1) in PROPERTY_VALUES:
                value = "'" + vm.group(2).lower() + "'"
            elif value in ('true', 'false'):
                value = f"'{value}'"
            elif re.match(r'^\d+$', value):
                value = f"'{value}'"
            else:
                value = f"(string) {value}"
            text = text[:i + 1] + f"$c->with({target}, '{prop}', {value})" + text[m.end():]

    out = set_value(out)
    out = re.sub(r'\bthis\.(generateBox|placeBlock|fillColumnDown|getBlock)\(\s*level\s*,\s*chunkBB\s*,', r'$this->\1($c, $r, $b,', out)
    out = re.sub(r'\bthis\.(placeBlock|fillColumnDown)\(\s*level\s*,', r'$this->\1($c, $r,', out)
    out = re.sub(r'\bthis\.createChest\(\s*level\s*,\s*chunkBB\s*,\s*random\s*,', r'$this->createChest($c, $r, $b, $random,', out)
    out = re.sub(r'BuiltInLootTables\.(\w+)', lambda m: f"'{m.group(1).lower()}'", out)
    out = re.sub(r',\s*chunkBB\s*\)', ', $b)', out)
    out = re.sub(r'\bthis\.(\w+)\(', r'$this->\1(', out)
    out = re.sub(r'\bthis\.(\w+)', r'$this->\1', out)
    out = re.sub(r'\b(?:final )?(?:int|boolean|float|double|long|BlockState|BlockPos|Direction|RandomSource|var)\s+([a-z]\w*)\s*=', r'$\1 =', out)
    for name in sorted(locals_, key=len, reverse=True):
        out = re.sub(r'(?<![\w$>\'.])' + re.escape(name) + r'\b(?!\s*\()', '$' + name, out)
    out = re.sub(r'(\$\w+)\+\+', r'++\1', out)
    out = re.sub(r'(\$\w+)--', r'--\1', out)
    out = re.sub(r'\brandom\.', '$random->', out)
    out = re.sub(r'\$random\.', '$random->', out)
    out = out.replace('0.33F', '0.33').replace('F)', ')')
    return out


if __name__ == '__main__':
    print(translate(open(sys.argv[1]).read()))
