#!/usr/bin/env python3
"""
Minimal POT generator for the plugin using regex over PHP source.
Extracts strings from __(), _e(), esc_*__(), _x(), _n() with the
text domain 'virakcloud-backup'. Not a full PHP parser, but good
enough for maintaining languages/virakcloud-backup.pot in this repo.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC_DIRS = [ROOT / 'src']
FILES = [ROOT / 'virakcloud-backup.php']
OUT = ROOT / 'languages' / 'virakcloud-backup.pot'

patterns = {
    'simple': re.compile(r"(?:__|_e|esc_html__|esc_attr__)\(\s*(['\"])((?:\\.|(?!\1).)*)\1\s*,\s*(['\"])virakcloud-backup\3", re.S),
    'context': re.compile(r"_x\(\s*(['\"])((?:\\.|(?!\1).)*)\1\s*,\s*(['\"])((?:\\.|(?!\3).)*)\3\s*,\s*(['\"])virakcloud-backup\5", re.S),
    'plural': re.compile(r"_n\(\s*(['\"])((?:\\.|(?!\1).)*)\1\s*,\s*(['\"])((?:\\.|(?!\3).)*)\3\s*,\s*[^,]+,\s*(['\"])virakcloud-backup\5", re.S),
}

def unescape(s: str) -> str:
    return s.encode('utf-8').decode('unicode_escape') if '\\' in s else s

def add_ref(d, key, ref):
    if key not in d:
        d[key] = {'refs': []}
    if len(d[key]['refs']) < 3:
        d[key]['refs'].append(ref)

def scan_file(path: Path, entries):
    text = path.read_text('utf-8', errors='ignore')
    for m in patterns['simple'].finditer(text):
        msg = unescape(m.group(2))
        line = text.count('\n', 0, m.start()) + 1
        add_ref(entries['simple'], msg, f"{path.relative_to(ROOT)}:{line}")
    for m in patterns['context'].finditer(text):
        msg = unescape(m.group(2))
        ctx = unescape(m.group(4))
        line = text.count('\n', 0, m.start()) + 1
        key = (msg, ctx)
        add_ref(entries['context'], key, f"{path.relative_to(ROOT)}:{line}")
    for m in patterns['plural'].finditer(text):
        s = unescape(m.group(2))
        p = unescape(m.group(4))
        line = text.count('\n', 0, m.start()) + 1
        key = (s, p)
        add_ref(entries['plural'], key, f"{path.relative_to(ROOT)}:{line}")

def esc(s: str) -> str:
    return s.replace('\\', r'\\').replace('"', r'\"')

def main():
    entries = {
        'simple': {},
        'context': {},
        'plural': {},
    }
    for d in SRC_DIRS:
        for p in d.rglob('*.php'):
            scan_file(p, entries)
    for p in FILES:
        if p.exists():
            scan_file(p, entries)

    lines = []
    lines.append('msgid ""')
    lines.append('msgstr ""')
    lines.append('"Project-Id-Version: VirakCloud Backup\\n"')
    lines.append('"MIME-Version: 1.0\\n"')
    lines.append('"Content-Type: text/plain; charset=UTF-8\\n"')
    lines.append('"Content-Transfer-Encoding: 8bit\\n"')
    lines.append('"X-Generator: make_pot.py\\n"')
    lines.append('')

    # Simple strings
    for msg in sorted(entries['simple']):
        refs = entries['simple'][msg]['refs']
        for r in refs:
            lines.append(f"#: {r}")
        lines.append(f'msgid "{esc(msg)}"')
        lines.append('msgstr ""')
        lines.append('')

    # Context strings
    for msg, ctx in sorted(entries['context']):
        refs = entries['context'][(msg, ctx)]['refs']
        for r in refs:
            lines.append(f"#: {r}")
        lines.append(f'msgctxt "{esc(ctx)}"')
        lines.append(f'msgid "{esc(msg)}"')
        lines.append('msgstr ""')
        lines.append('')

    # Plural strings
    for sing, plur in sorted(entries['plural']):
        refs = entries['plural'][(sing, plur)]['refs']
        for r in refs:
            lines.append(f"#: {r}")
        lines.append(f'msgid "{esc(sing)}"')
        lines.append(f'msgid_plural "{esc(plur)}"')
        lines.append('msgstr[0] ""')
        lines.append('msgstr[1] ""')
        lines.append('')

    OUT.write_text('\n'.join(lines), encoding='utf-8')
    print(f"Wrote {OUT} with {sum(len(v) for v in entries.values())} entries")

if __name__ == '__main__':
    sys.exit(main())

