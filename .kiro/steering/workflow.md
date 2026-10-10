---
inclusion: always
---

# Environment & commands

## Backend
- NEVER start, restart, or run the backend yourself — it runs in Docker.
- To check its status: `docker compose ps`
- To run a command inside the backend container, use the alias: `drun <command>`

## Frontend
- Run with `bun run dev`

## Tests
- Run with `docker compose -f docker-compose.test.yml run --rm test`
- Arguments can be passed through, e.g. `docker compose -f docker-compose.test.yml run --rm test path/to/test_file`
- Don't run the full test suite, always try to only test the related tests

## Packages
- NEVER install packages or run any package manager install command.
- If a dependency is missing, stop and tell me which package is needed — I will install it.