#!/usr/bin/env python3
"""Checks that every translation in a .po has exactly the placeholders of its original.

msgfmt --check-format catches a wrong type or a missing one in strings it knows to be format
strings. This also catches one that was added, and compares as a set, so that a translation may
reorder positional placeholders freely but may not drop, repeat or invent one.

    placeholders.py languages/soapbox-for-blygger-de_DE.po

Standard library only. Not part of the plugin.
"""
import re
import sys

PLACEHOLDER = re.compile(r"%(?:\d+\$)?[sd]")


def entries(path):
    for block in open(path, encoding="utf-8").read().split("\n\n"):
        fields, field = {}, None
        for line in block.split("\n"):
            m = re.match(r'(msgid_plural|msgid|msgstr(?:\[\d\])?) "(.*)"$', line)
            if m:
                field = m.group(1)
                fields[field] = m.group(2)
            elif field and line.startswith('"'):
                fields[field] += line[1:-1]
            elif not line.startswith("#"):
                field = None
        if fields.get("msgid"):
            yield fields


def main(path):
    bad = 0
    for e in entries(path):
        for key, text in e.items():
            if not key.startswith("msgstr") or not text:
                continue
            # A plural form is compared with the plural original, the singular with the singular.
            source = e["msgid_plural"] if key not in ("msgstr", "msgstr[0]") and "msgid_plural" in e else e["msgid"]
            want, have = sorted(PLACEHOLDER.findall(source)), sorted(PLACEHOLDER.findall(text))
            # Languages with one plural form translate the plural original only.
            if want != have and sorted(PLACEHOLDER.findall(e.get("msgid_plural", ""))) != have:
                bad += 1
                print("%s: placeholders differ\n  msgid:  %s\n  %s: %s" % (path.split("/")[-1], e["msgid"], key, text))
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1]))
