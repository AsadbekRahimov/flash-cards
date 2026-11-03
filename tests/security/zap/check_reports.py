#!/usr/bin/env python3
"""Reads the JSON reports of zap-baseline.py (-J) and applies the owner's criterion (03.11.2026):
no High alert, every Medium reviewed.

Usage: check_reports.py <report.json>...
Exit code: 0 - no High; 1 - at least one High; 2 - no High, but Medium alerts exist and STRICT_MEDIUM=1; 3 - unreadable report.
"""
import json
import os
import sys

RISKS = {"3": "High", "2": "Medium", "1": "Low", "0": "Informational"}


def main(paths):
    if not paths:
        print("check_reports.py: no reports given", file=sys.stderr)
        return 3

    totals = {"High": 0, "Medium": 0, "Low": 0, "Informational": 0}

    for path in paths:
        try:
            with open(path, encoding="utf-8") as handle:
                report = json.load(handle)
        except (OSError, ValueError) as error:
            print(f"cannot read {path}: {error}", file=sys.stderr)
            return 3

        print(f"== {os.path.basename(path)}")
        for site in report.get("site", []):
            for alert in site.get("alerts", []):
                risk = RISKS.get(str(alert.get("riskcode")), "Informational")
                totals[risk] += 1
                if risk in ("High", "Medium"):
                    print(f"  [{risk}] {alert.get('name')} (rule {alert.get('pluginid')}, {alert.get('count', '?')} instances)")
                    for instance in alert.get("instances", [])[:3]:
                        print(f"      {instance.get('method')} {instance.get('uri')}")

    print("\nTotal: " + ", ".join(f"{name} {count}" for name, count in totals.items()))

    if totals["High"] > 0:
        print("FAIL: High alerts found - the release criterion is zero.", file=sys.stderr)
        return 1

    if totals["Medium"] > 0:
        print("Medium alerts: each must be fixed or recorded with a reason (docs/STAGING_PHASE2_CHECKLIST.md 7.2).")
        if os.environ.get("STRICT_MEDIUM") == "1":
            return 2

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
