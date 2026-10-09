#!/usr/bin/env python3
"""Makes a pseudo-locale .po from the plugin's .pot.

Every translation is the original wrapped in ⟦ ⟧ and padded to about 140% of its length, with
placeholders left exactly as they were. A string that shows on screen without the brackets was
never passed through a translation function; a layout that breaks was built for English lengths.

    pseudo.py languages/soapbox-for-blygger.pot > soapbox-for-blygger-xx_XX.po

Standard library only. Not part of the plugin.
"""
import math
import re
import sys

PLACEHOLDER = re.compile(r"%(?:\d+\$)?[sd]|%%")


def pseudo(text):
    if not text:
        return text
    words = len(PLACEHOLDER.sub("", text))
    pad = "·" * max(1, math.ceil(words * 0.4))
    return "⟦" + text + pad + "⟧"


def unquote(lines):
    return "".join(line.strip()[1:-1] for line in lines)


def quote(text):
    return '"' + text + '"'


def main(path):
    entries = open(path, encoding="utf-8").read().split("\n\n")
    out = [
        'msgid ""\nmsgstr ""\n"Language: xx_XX\\n"\n"MIME-Version: 1.0\\n"\n'
        '"Content-Type: text/plain; charset=UTF-8\\n"\n"Content-Transfer-Encoding: 8bit\\n"\n'
        '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"\n"X-Domain: soapbox-for-blygger\\n"'
    ]
    for entry in entries[1:]:
        lines = entry.strip("\n").split("\n")
        keep, field, fields = [], None, {}
        for line in lines:
            if line.startswith("#"):
                keep.append(line)
                continue
            m = re.match(r'(msgctxt|msgid_plural|msgid|msgstr(?:\[\d\])?) (".*")$', line)
            if m:
                field = m.group(1)
                fields[field] = [m.group(2)]
            elif field and line.startswith('"'):
                fields[field].append(line)
        if "msgid" not in fields:
            continue
        block = keep[:]
        if "msgctxt" in fields:
            block.append("msgctxt " + quote(unquote(fields["msgctxt"])))
        msgid = unquote(fields["msgid"])
        block.append("msgid " + quote(msgid))
        if "msgid_plural" in fields:
            plural = unquote(fields["msgid_plural"])
            block.append("msgid_plural " + quote(plural))
            block.append("msgstr[0] " + quote(pseudo(msgid)))
            block.append("msgstr[1] " + quote(pseudo(plural)))
        else:
            block.append("msgstr " + quote(pseudo(msgid)))
        out.append("\n".join(block))
    sys.stdout.write("\n\n".join(out) + "\n")


if __name__ == "__main__":
    main(sys.argv[1])
