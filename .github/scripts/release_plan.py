#!/usr/bin/env python3
"""Plan the next release from conventional commit subjects since the last v* tag.

Rules (documented in CONTRIBUTING.md):
  feat!: / BREAKING CHANGE:  -> major
  feat:                      -> minor
  fix: perf: revert:         -> patch
  docs: style: test: build: ci: chore: refactor: (or anything else) -> no release
With no earlier tag, the first release is 1.0.0.

Usage: release_plan.py [--head SHA] [--notes-file PATH] [--github-output PATH] [--self-test]
Prints one JSON object; writes release/tag/version/bump to GITHUB_OUTPUT when given.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
from dataclasses import dataclass

TAG_RE = re.compile(r"^v(\d+)\.(\d+)\.(\d+)$")
SUBJECT_RE = re.compile(
    r"^(?P<type>feat|fix|perf|refactor|docs|style|test|build|ci|chore|revert)"
    r"(?:\((?P<scope>[^)]+)\))?(?P<bang>!)?: (?P<desc>.+)$"
)
BREAKING_RE = re.compile(r"^BREAKING[ -]CHANGE:\s*(.+?)(?:\n\n|\Z)", re.M | re.S)
BUMP_BY_TYPE = {"feat": "minor", "fix": "patch", "perf": "patch", "revert": "patch"}
RANK = {"none": 0, "patch": 1, "minor": 2, "major": 3}
SECTIONS = [
    ("breaking", "Breaking changes"),
    ("feat", "Features"),
    ("fix", "Bug fixes"),
    ("perf", "Performance"),
    ("revert", "Reverts"),
    ("other", "Other changes"),
]


@dataclass
class Commit:
    sha: str
    subject: str
    body: str

    def parse(self) -> dict:
        m = SUBJECT_RE.match(self.subject.strip())
        breaking_note = None
        bm = BREAKING_RE.search(self.body)
        if bm:
            breaking_note = " ".join(bm.group(1).split())
        if not m:
            return {"type": "other", "desc": self.subject.strip(), "scope": None,
                    "breaking": bool(breaking_note), "note": breaking_note}
        return {
            "type": m.group("type"),
            "desc": m.group("desc").strip(),
            "scope": m.group("scope"),
            "breaking": bool(m.group("bang")) or bool(breaking_note),
            "note": breaking_note,
        }


def run(args: list[str], cwd: str | None = None) -> str:
    return subprocess.run(args, cwd=cwd, check=True, capture_output=True, text=True).stdout


PACKAGE = "alexhackney/laravel-zammad"


def is_ours(rev: str, cwd: str | None) -> bool:
    """True when composer.json at `rev` names this package.

    The repository history still contains codebar-ag's commits and possibly its tags (v12.9.1 ...). Only
    commits and tags whose composer.json says `alexhackney/laravel-zammad` belong to this version line.
    """
    try:
        data = json.loads(run(["git", "show", f"{rev}:composer.json"], cwd))
    except (subprocess.CalledProcessError, json.JSONDecodeError):
        return False
    return isinstance(data, dict) and data.get("name") == PACKAGE


def last_tag(head: str, cwd: str | None) -> str | None:
    out = run(["git", "tag", "--merged", head, "--list", "v*", "--sort=-v:refname"], cwd)
    for line in out.splitlines():
        tag = line.strip()
        if TAG_RE.match(tag) and is_ours(tag, cwd):
            return tag
    return None


def tags_at(head: str, cwd: str | None) -> list[str]:
    out = run(["git", "tag", "--points-at", head, "--list", "v*"], cwd)
    return [t for t in out.split() if TAG_RE.match(t)]


def commits_since(tag: str | None, head: str, cwd: str | None) -> list[Commit]:
    rng = f"{tag}..{head}" if tag else head
    raw = run(["git", "log", rng, "--first-parent", "--format=%H%x1f%s%x1f%b%x1e"], cwd)
    commits = []
    for rec in raw.split("\x1e"):
        rec = rec.strip("\n")
        if not rec.strip():
            continue
        sha, subject, body = (rec.split("\x1f") + ["", ""])[:3]
        sha = sha.strip()
        # Without an earlier tag, stop at the first commit that is not this package yet (the forked history).
        if tag is None and not is_ours(sha, cwd):
            break
        commits.append(Commit(sha, subject, body))
    return commits


def decide(commits: list[Commit]) -> str:
    best = "none"
    for c in commits:
        p = c.parse()
        bump = "major" if p["breaking"] else BUMP_BY_TYPE.get(p["type"], "none")
        if RANK[bump] > RANK[best]:
            best = bump
    return best


def next_version(tag: str | None, bump: str) -> str:
    if tag is None:
        return "1.0.0"
    major, minor, patch = (int(x) for x in TAG_RE.match(tag).groups())
    if bump == "major":
        return f"{major + 1}.0.0"
    if bump == "minor":
        return f"{major}.{minor + 1}.0"
    return f"{major}.{minor}.{patch + 1}"


NOTES_LIMIT = 100_000  # GitHub rejects release bodies above 125000 characters


def md(text: str) -> str:
    """Neutralise links and HTML in contributor-controlled text (titles, scopes, breaking notes)."""
    return re.sub(r"([\[\]<>])", r"\\\1", text)


def notes(commits: list[Commit], prev: str | None, tag: str, bump: str, repo: str | None) -> str:
    groups: dict[str, list[str]] = {key: [] for key, _ in SECTIONS}
    other_count = 0
    for c in commits:
        p = c.parse()
        scope = f"**{md(p['scope'])}:** " if p["scope"] else ""
        link = f" ([{c.sha[:7]}](https://github.com/{repo}/commit/{c.sha}))" if repo else f" ({c.sha[:7]})"
        line = f"- {scope}{md(p['desc'])}{link}"
        if p["breaking"]:
            groups["breaking"].append(line + (f"\n  - {md(p['note'])}" if p["note"] else ""))
        elif p["type"] in ("feat", "fix", "perf", "revert"):
            groups[p["type"]].append(line)
        else:
            other_count += 1
    out = [f"## {tag}", ""]
    for key, title in SECTIONS:
        if groups[key] and key != "other":
            out += [f"### {title}", "", *groups[key], ""]
    if other_count:
        out += [f"Also included: {other_count} maintenance change(s) (docs, tests, build, ci, chore, refactor).", ""]
    if bump == "major" or prev is None:
        out += ["Upgrading from `codebar-ag/laravel-zammad` or an earlier major? See "
                "[UPGRADING.md](https://github.com/%s/blob/%s/UPGRADING.md)." % (repo or "alexhackney/laravel-zammad", tag), ""]
    if prev and repo:
        out += [f"**Full diff:** https://github.com/{repo}/compare/{prev}...{tag}", ""]
    text = "\n".join(out).rstrip() + "\n"
    if len(text) > NOTES_LIMIT:
        cut = text[:NOTES_LIMIT].rsplit("\n", 1)[0]
        text = cut + "\n\n_The list was truncated; see the full diff link below._\n"
        if prev and repo:
            text += f"\n**Full diff:** https://github.com/{repo}/compare/{prev}...{tag}\n"
    return text


def tag_commit(tag: str, cwd: str | None) -> str | None:
    try:
        return run(["git", "rev-parse", "-q", "--verify", f"refs/tags/{tag}^{{commit}}"], cwd).strip()
    except subprocess.CalledProcessError:
        return None


def plan(head: str, cwd: str | None, repo: str | None) -> dict:
    sha = run(["git", "rev-parse", head], cwd).strip()
    existing = tags_at(sha, cwd)
    if existing:
        return {"release": False, "reason": f"commit already tagged {existing[0]}", "head": sha}
    prev = last_tag(sha, cwd)
    commits = commits_since(prev, sha, cwd)
    bump = decide(commits)
    if bump == "none":
        return {"release": False, "reason": "no releasable commits (feat, fix, perf, revert or breaking) since "
                + (prev or "the start"), "head": sha, "previous": prev}
    if prev is None:
        bump_label = "major" if any(c.parse()["breaking"] for c in commits) else bump
    else:
        bump_label = bump
    version = next_version(prev, bump)
    tag = f"v{version}"
    occupied = tag_commit(tag, cwd)
    if occupied is not None and occupied != sha:
        raise SystemExit(
            f"Tag {tag} already exists on {occupied[:7]} (not this commit {sha[:7]}). Inherited upstream or hand-made "
            "tags must be deleted before this version can be released."
        )
    return {"release": True, "bump": bump_label, "version": version, "tag": tag, "previous": prev, "head": sha,
            "notes": notes(commits, prev, tag, bump_label, repo), "count": len(commits)}


def self_test() -> int:
    def mk(cwd: str, msgs: list[tuple[str, str]], tag: str | None = None):
        for subject, body in msgs:
            with open(os.path.join(cwd, "f"), "a") as fh:
                fh.write(subject + "\n")
            run(["git", "add", "f"], cwd)
            run(["git", "-c", "user.name=t", "-c", "user.email=t@t", "commit", "-q", "-m", subject, "-m", body or "-"], cwd)
        if tag:
            run(["git", "tag", tag], cwd)

    def set_name(cwd: str, name: str):
        with open(os.path.join(cwd, "composer.json"), "w") as fh:
            json.dump({"name": name}, fh)
        run(["git", "add", "composer.json"], cwd)

    with tempfile.TemporaryDirectory() as d:  # forked history + upstream tag + first release
        run(["git", "init", "-q", "-b", "main"], d)
        set_name(d, "codebar-ag/laravel-zammad")
        mk(d, [("feat: old upstream feature", ""), ("fix: old upstream fix", "")], tag="v12.9.1")
        set_name(d, PACKAGE)
        mk(d, [("feat!: take over the package", "BREAKING CHANGE: new name")])
        p = plan("HEAD", d, "o/r")
        assert p["tag"] == "v1.0.0" and p["previous"] is None, p
        assert "old upstream" not in p["notes"] and "take over" in p["notes"], p
    with tempfile.TemporaryDirectory() as d:
        run(["git", "init", "-q", "-b", "main"], d)
        set_name(d, PACKAGE)
        mk(d, [("chore: init", "")])
        assert plan("HEAD", d, None)["release"] is False
        mk(d, [("feat!: first public release (#3)", "BREAKING CHANGE: renamed everything")])
        p = plan("HEAD", d, "o/r")
        assert p["release"] and p["tag"] == "v1.0.0" and p["bump"] == "major", p
        assert "Breaking changes" in p["notes"] and "renamed everything" in p["notes"]
        run(["git", "tag", "v1.0.0"], d)
        assert plan("HEAD", d, None)["release"] is False  # head is tagged
        mk(d, [("docs: readme", ""), ("ci: tweak", "")])
        assert plan("HEAD", d, None)["release"] is False
        mk(d, [("fix(ping): redact", "")])
        p = plan("HEAD", d, None); assert p["tag"] == "v1.0.1" and p["bump"] == "patch", p
        mk(d, [("feat: search limit", "")])
        p = plan("HEAD", d, None); assert p["tag"] == "v1.1.0", p
        mk(d, [("Merge branch 'x'", "")])
        p = plan("HEAD", d, None); assert p["tag"] == "v1.1.0", p
        mk(d, [("fix!: drop thing", "")])
        p = plan("HEAD", d, None); assert p["tag"] == "v2.0.0", p
        run(["git", "tag", "v2.0.0"], d)
        mk(d, [("perf: faster", ""), ("chore: x", "see the note about BREAKING CHANGE: mid-line, not a footer")])
        p = plan("HEAD", d, None); assert p["tag"] == "v2.0.1", p
        mk(d, [("refactor: tidy", "")]); mk(d, [("revert: undo", "")])
        p = plan("HEAD", d, None); assert p["tag"] == "v2.0.1" and "Reverts" in p["notes"], p
        # markdown from titles is neutralised and maintenance commits are only counted
        mk(d, [("fix: [Download](https://x.invalid/u) <b>now</b>", ""), ("docs: a", ""), ("docs: b", "")])
        p = plan("HEAD", d, "o/r")
        assert "\\[Download\\]" in p["notes"] and "\\<b\\>" in p["notes"], p["notes"]
        assert "maintenance change" in p["notes"] and "docs: a" not in p["notes"], p["notes"]
        # an occupied tag name on another commit is refused
        run(["git", "checkout", "-q", "-b", "side", "v2.0.0"], d)
        mk(d, [("fix: hand made side release", "")], tag="v2.0.1")
        run(["git", "checkout", "-q", "main"], d)
        try:
            plan("HEAD", d, None); raise AssertionError("expected collision")
        except SystemExit as e:
            assert "already exists" in str(e), e
        run(["git", "tag", "-d", "v2.0.1"], d)
        mk(d, [("chore: drop php 8.3", "BREAKING-CHANGE: php 8.4 required")])
        p = plan("HEAD", d, None); assert p["tag"] == "v3.0.0" and "php 8.4 required" in p["notes"], p
    print("self-test ok")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--head", default="HEAD")
    ap.add_argument("--expect-tag", help="fail unless the plan produces exactly this tag")
    ap.add_argument("--notes-file")
    ap.add_argument("--github-output")
    ap.add_argument("--self-test", action="store_true")
    a = ap.parse_args()
    if a.self_test:
        return self_test()
    result = plan(a.head, None, os.environ.get("GITHUB_REPOSITORY"))
    if a.expect_tag and result.get("tag") != a.expect_tag:
        raise SystemExit(f"Plan drifted: expected {a.expect_tag}, planned {result.get('tag')}")
    notes_text = result.pop("notes", None)
    if notes_text and a.notes_file:
        with open(a.notes_file, "w") as fh:
            fh.write(notes_text)
    print(json.dumps(result, indent=2))
    if a.github_output:
        with open(a.github_output, "a") as fh:
            fh.write(f"release={'true' if result['release'] else 'false'}\n")
            for key in ("tag", "version", "bump"):
                if result.get(key):
                    fh.write(f"{key}={result[key]}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
