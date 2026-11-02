#!/usr/bin/env python3
"""Compare a Cake 3 vs Cake 5 run-diff.sh capture pair.

Usage: python3 compare.py <outdir>   (default: this script's directory)
Normalises the ticking fake clock (DB modified stamps within 3h of the
anchor, webhook occurred_at) and prints IDENTICAL/DIFF per artefact.
Mail bodies are compared tag-stripped with whitespace collapsed (the M
slice's known trailing-newline difference is absorbed by the strip).
Exit code 0 when every artefact matches, 1 otherwise.
"""
import json
import re
import sys
from pathlib import Path

out = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).parent
fails = []


def norm_db(text):
    return re.sub(r"2031-01-15 1[5-9]:\d\d:\d\d", "<now>", text)


def cmp(name, a, b):
    if a == b:
        print(f"IDENTICAL: {name}")
    else:
        fails.append(name)
        print(f"DIFF: {name}")
        import difflib

        for line in list(difflib.unified_diff(a.splitlines(), b.splitlines(), "cake3", "cake5", lineterm=""))[:40]:
            print(line)


for f in ["before-events", "before-regs", "after1-events", "after1-regs", "after2-events", "after2-regs"]:
    cmp(f, norm_db((out / f"cake3-{f}.txt").read_text()), norm_db((out / f"cake5-{f}.txt").read_text()))


def mails(path):
    d = json.loads(Path(path).read_text())
    res = []
    for it in d["items"]:
        h = it["Content"]["Headers"]
        body = re.sub(r"<[^>]+>", " ", it["Content"].get("Body", ""))
        body = re.sub(r"&[a-z#0-9]+;", " ", body)
        body = re.sub(r"\s+", " ", body).strip()
        res.append(f"From: {h.get('From')}\nTo: {h.get('To')}\nSubject: {h.get('Subject')}\n{body}")
    return "\n\n====\n\n".join(res)


cmp("mail1", mails(out / "cake3-mail1.json"), mails(out / "cake5-mail1.json"))
cmp("mail2", (out / "cake3-mail2.json").read_text(), (out / "cake5-mail2.json").read_text())


def fakes(path):
    d = json.loads(Path(path).read_text())
    normed = []
    for e in d:
        b = e.get("body")
        if isinstance(b, str):
            try:
                b = json.loads(b)
            except ValueError:
                pass
        if isinstance(b, dict) and "occurred_at" in b:
            b = dict(b, occurred_at="<now>")
        normed.append(dict(e, body=b))
    return json.dumps(normed, indent=2, sort_keys=True)


cmp("fakes1", fakes(out / "cake3-fakes1.json"), fakes(out / "cake5-fakes1.json"))
cmp("fakes2", (out / "cake3-fakes2.json").read_text(), (out / "cake5-fakes2.json").read_text())
cmp("sink1", fakes(out / "cake3-sink1.json"), fakes(out / "cake5-sink1.json"))
print("RESULT:", "FAIL " + ",".join(fails) if fails else "ALL IDENTICAL")
sys.exit(1 if fails else 0)
