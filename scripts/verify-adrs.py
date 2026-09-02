#!/usr/bin/env python3
"""
verify-adrs.py — keep ADR references honest.

ADR-001/002/006 are cited from ~39 places in source. Before docs/adr/ existed,
every one of those was a dead link: the ADR files were written but a
.gitignore ordering bug (`!docs/adr/*.md` placed ABOVE the blanket `*.md`)
silently swallowed them, so they were never committed. This script fails CI
on any regression of that class.

Checks:
  1. Every `ADR-NNN` cited in source resolves to a docs/adr/NNNN-*.md file.
  2. Every `ADR-NNN §"Section"` cited resolves to a real heading in that file.
     (Renaming a heading that code references by name breaks the citation
     just as badly as deleting the file.)
  3. Every docs/adr/*.md is git-trackable — catches the .gitignore bug that
     caused this whole problem.
  4. Every repo path referenced inside an ADR body exists.
  5. Reserved ADR numbers are not silently reused.

Usage: python3 scripts/verify-adrs.py [--quiet]
Exit code 0 = pass, 1 = fail.
"""
from __future__ import annotations
import re, subprocess, sys
from pathlib import Path
from collections import defaultdict

REPO = Path(__file__).resolve().parent.parent
ADR_DIR = REPO / "docs" / "adr"

# Numbers whose original ADR was never recovered. Nothing in the codebase
# cites them, so no document was invented. Do not reuse — see docs/adr/README.md.
RESERVED = {"ADR-003", "ADR-004", "ADR-005"}

SOURCE_GLOBS = ("*.php", "*.ts", "*.tsx", "*.sql", "*.md", "*.js", "*.mjs")
SKIP_DIRS = ("/node_modules/", "/vendor/", "/.git/")

# Filename templates and quoted claims from other docs — not our assertions.
PATH_ALLOWLIST = {"NNNN-kebab-case-title.md", "CONTEXT.md"}

CITE = re.compile(r'ADR-(\d{3})')
CITE_SECTION = re.compile(r'ADR-(\d{3})\s*§\s*"([^"]+)"')
HEADING = re.compile(r'^#{2,4}\s+(.+?)\s*$', re.M)
PATHREF = re.compile(
    r'`(/?[A-Za-z0-9_./()\-]+\.(?:php|ts|tsx|sql|md|js|mjs|conf|htaccess))(?::[\d, \-]+)?`')

failures: list[str] = []
notes: list[str] = []


def adr_files() -> dict[str, Path]:
    out = {}
    if not ADR_DIR.is_dir():
        failures.append(f"docs/adr/ does not exist (expected at {ADR_DIR})")
        return out
    for p in sorted(ADR_DIR.glob("[0-9][0-9][0-9][0-9]-*.md")):
        out[f"ADR-{int(p.name[:4]):03d}"] = p
    return out


def source_files() -> list[Path]:
    files = []
    for pat in SOURCE_GLOBS:
        for p in REPO.rglob(pat):
            s = str(p)
            if any(d in s for d in SKIP_DIRS):
                continue
            if ADR_DIR in p.parents:      # ADRs citing each other are not "source"
                continue
            files.append(p)
    return files


def check_citations(known: dict[str, Path]) -> None:
    cited: dict[str, set[str]] = defaultdict(set)
    sections: dict[tuple[str, str], set[str]] = defaultdict(set)

    for p in source_files():
        try:
            text = p.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        rel = p.relative_to(REPO).as_posix()
        for n in CITE.findall(text):
            cited[f"ADR-{n}"].add(rel)
        for n, sec in CITE_SECTION.findall(text):
            sections[(f"ADR-{n}", sec)].add(rel)

    print("\n[1] ADR citations resolve to a document")
    if not cited:
        notes.append("no ADR citations found in source — nothing to verify")
    for num in sorted(cited):
        where = sorted(cited[num])
        if num in known:
            print(f"    OK   {num}  {len(where):>2} file(s) -> {known[num].name}")
        elif num in RESERVED:
            failures.append(
                f"{num} is cited by {', '.join(where[:3])} but is a RESERVED number "
                f"with no document. Either recover the original ADR or stop citing it.")
            print(f"    FAIL {num}  cited but RESERVED (no document)")
        else:
            failures.append(f"{num} cited by {', '.join(where[:3])} but no docs/adr/ file exists")
            print(f"    FAIL {num}  DANGLING — cited by {len(where)} file(s), no document")

    print("\n[2] Cited §sections exist as real headings")
    if not sections:
        print("    --   no §section citations found")
    for (num, sec), where in sorted(sections.items()):
        path = known.get(num)
        if path is None:
            continue  # already reported in [1]
        heads = HEADING.findall(path.read_text(encoding="utf-8"))
        if sec in heads:
            print(f'    OK   {num} §"{sec}"')
        else:
            failures.append(
                f'{num} §"{sec}" is cited by {", ".join(sorted(where)[:3])} but '
                f'{path.name} has no such heading. Renaming a referenced heading '
                f'breaks the citation — restore it or update every citing site.')
            print(f'    FAIL {num} §"{sec}"  heading missing in {path.name}')


def check_trackable(known: dict[str, Path]) -> None:
    print("\n[3] ADR files are git-trackable (the .gitignore regression)")
    targets = [str(p.relative_to(REPO)) for p in sorted(ADR_DIR.glob("*.md"))]
    if not targets:
        print("    --   no ADR files to check")
        return
    try:
        r = subprocess.run(["git", "check-ignore"] + targets,
                           cwd=REPO, capture_output=True, text=True)
    except FileNotFoundError:
        print("    --   git unavailable, skipped")
        return
    ignored = [l for l in r.stdout.splitlines() if l.strip()]
    if ignored:
        for f in ignored:
            failures.append(
                f"{f} is excluded by .gitignore and can never be committed. "
                f"A `!docs/adr/*.md` negation must appear BELOW the blanket `*.md` rule.")
            print(f"    FAIL {f}  IGNORED by .gitignore")
    else:
        print(f"    OK   all {len(targets)} file(s) trackable")


def check_paths(known: dict[str, Path]) -> None:
    print("\n[4] Paths referenced inside ADRs exist")
    checked = 0
    bad = 0
    for p in sorted(ADR_DIR.glob("*.md")):
        for m in sorted(set(PATHREF.findall(p.read_text(encoding="utf-8")))):
            if m.startswith("docs/adr/") or m in PATH_ALLOWLIST:
                continue
            checked += 1
            target = REPO / (m[1:] if m.startswith("/") else m)
            if not target.exists():
                bad += 1
                failures.append(f"{p.name} references `{m}` which does not exist")
                print(f"    FAIL {p.name} -> {m}")
    if not bad:
        print(f"    OK   {checked} distinct path(s) resolve")


def check_reserved(known: dict[str, Path]) -> None:
    print("\n[5] Reserved numbers not reused")
    clash = sorted(RESERVED & set(known))
    for n in clash:
        failures.append(
            f"{n} is reserved (original ADR never recovered) but {known[n].name} now uses it. "
            f"New ADRs start at 0007 — see docs/adr/README.md.")
        print(f"    FAIL {n} reused by {known[n].name}")
    if not clash:
        print(f"    OK   {', '.join(sorted(RESERVED))} still reserved")


def main() -> int:
    print("=" * 62)
    print("  ADR reference check")
    print("=" * 62)
    known = adr_files()
    if known:
        print(f"\nFound {len(known)} ADR(s): {', '.join(sorted(known))}")
    check_citations(known)
    check_trackable(known)
    check_paths(known)
    check_reserved(known)

    print("\n" + "=" * 62)
    for n in notes:
        print(f"  note: {n}")
    if failures:
        print(f"  FAIL — {len(failures)} problem(s):\n")
        for f in failures:
            print(f"    - {f}")
        print("=" * 62)
        return 1
    print("  PASS — every ADR reference resolves")
    print("=" * 62)
    return 0


if __name__ == "__main__":
    sys.exit(main())
