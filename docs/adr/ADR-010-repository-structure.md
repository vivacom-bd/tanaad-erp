# ADR-010: Repository structure for the monorepo

**Status:** Accepted, 2026-11-09. Executed in Phase 0.

## Context

Today the repository root holds `docs/` (untracked by design: client briefs, PDD, decks, proposals) and `tanaad-app/`, which is the pnpm workspace with `apps/web`, `packages/domain`, an empty `contracts/fixtures`, `tools/` and its own tracked `docs/`. Adding `apps/api` under a folder called `tanaad-app` would be misleading, and engineering docs are split between two `docs` folders.

## Decision

1. **Promote the workspace to the repository root** with `git mv`: `apps/`, `packages/`, `contracts/`, `tools/`, `pnpm-workspace.yaml`, `package.json`, `netlify.toml`, `vercel.json` move up one level; `tanaad-app/` disappears. Deploy configs are updated for the new base directory.
2. **Add** `apps/api` (Laravel), `infra/` (Docker Compose, Dockerfiles, nginx, Postgres init), `.github/workflows/`.
3. **Track engineering docs under `docs/`**, keep client material untracked. `.gitignore` changes from `docs/` to:
   ```
   docs/*
   !docs/adr/
   !docs/architecture/
   !docs/plans/
   !docs/README.md
   ```
   `tanaad-app/docs/*` (ADR-001, ARCHITECTURE, DESIGN_SPEC, KNOWN_GAPS, AGENT_BRIEF, screenshots) move into `docs/architecture/frontend/` and `docs/adr/`. The duplicate ADR-001 is reconciled to one file.
4. **`contracts/`** gains `openapi.yaml`, `events/` and real `fixtures/`.

## Consequences

- One history, one workspace, one place for architecture decisions.
- Paths in `tools/shot.mjs`, Netlify and Vercel configs, and the memory notes change once.
- Anyone with a clone pulls a large rename; `git log --follow` keeps file history.
