# AGENTS.md

## Cursor Cloud specific instructions

As of this writing, `dijitaloperation` (DOP — Dijital Operasyon Platformu) is a
**documentation-only, pre-scaffold repository**. The entire tracked tree is:

- `README.md` — one-line product pitch
- `docs/current-state/*.md` — Turkish "current state" docs describing the
  intended (not yet implemented) product

There is intentionally **no** application code, package manager, manifest
(`package.json`, `requirements.txt`, `pyproject.toml`, `go.mod`, etc.),
`Dockerfile`/`docker-compose`, `Makefile`, `.devcontainer`, or CI workflow.

Practical implications for future agents:

- There are **no dependencies to install** and **no services to run**. The VM
  startup/update script is a deliberate no-op until a real toolchain is added.
- There are **no build/test/lint/run commands** defined. Do not assume a stack
  (Node, Python, .NET, etc.); none has been chosen yet. See
  `docs/current-state/RUNBOOK.md` for the explicit list of undetermined items.
- Once real application code is added, replace the no-op update script (via the
  environment setup flow) with the actual dependency install command(s), and
  update this section with how to run/build/test/lint the new service(s).
