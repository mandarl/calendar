# Cake 5 upgrade: plan documents (WIP)

Work in progress. These are the planning documents for moving the calendar from CakePHP 3.10 / PHP 7.4 to
CakePHP 5 / PHP 8.4 to remediate two actively exploited PHP CVEs (CVE-2026-109618, CVE-2026-109619). Start with
`calendar-cake5-upgrade-plan.md`; section 0 holds the latest status and how to resume.

| File | What it is |
| --- | --- |
| `calendar-cake5-upgrade-plan.md` | Handoff plan: decisions, rules, status, what is done and what remains, how the slices run in parallel in Docker |
| `calendar-php-upgrade-options.md` | The options analysis (stopgap, staged, direct Cake 5, paid support, replace) and recommendation |
| `calendar-cake5-workbreakdown.md` | The 14 work slices after the foundation pass, build/test steps and gotchas |
| `calendar-cake5-slice-cards.md` | One card per slice agent: owned files, goal, proof required, state when work paused |
| `calendar-cake5-agent-brief.md`, `calendar-cake5-agent-brief-wave2.md` | Shared rules and method for the agents implementing the slices |

Notes:
- The documents were written in a parent working directory and refer to sibling files by name (the foundation report,
  the library kit, the baseline folder, per-slice working copies). Those are not part of this repository.
- The end-to-end baseline harness (`e2e/`), the Cake 5 port itself and the slice implementations are intentionally NOT in
  this commit.
