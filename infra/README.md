# Infra

Local development stack, driven from the repo root Makefile.

- `make db` starts only Postgres (host port 5440) and Redis (host port 6390).
  Other projects on this machine already hold 5432 and 6379, so we avoid them.
- `make up` starts the whole stack, with the API on http://localhost:8080.
- `infra/postgres/init.sql` creates the `tanaad_test` database on first start,
  so the Pest suite can run against the same Postgres container.
- The init script only runs when the Postgres data volume is empty. To re-run it,
  remove the volume and start again.

The test suite expects Postgres at 127.0.0.1:5440 (user and password `tanaad`) and
Redis at 127.0.0.1:6390, matching `apps/api/phpunit.xml` and the CI services.
