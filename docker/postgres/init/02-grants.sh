#!/bin/bash
# Privilegi di locker_app su entrambi i database.
#
# locker_app riceve SELECT/INSERT/UPDATE/DELETE sulle tabelle create da locker_owner,
# ma NON CREATE sullo schema: non possiede nulla, quindi le policy RLS lo governano
# sempre (piano §3.2). In F1 alcuni privilegi verranno ristretti ulteriormente
# (audit_logs: REVOKE UPDATE, DELETE — append-only imposto dal DB, §14).
set -e

for db in locker locker_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<-EOSQL
    GRANT CONNECT ON DATABASE "$db" TO locker_app;
    GRANT USAGE ON SCHEMA public TO locker_app;

    -- Tutto ciò che locker_owner creerà d'ora in poi è leggibile/scrivibile da locker_app.
    ALTER DEFAULT PRIVILEGES FOR ROLE locker_owner IN SCHEMA public
      GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO locker_app;
    ALTER DEFAULT PRIVILEGES FOR ROLE locker_owner IN SCHEMA public
      GRANT USAGE, SELECT ON SEQUENCES TO locker_app;
EOSQL
done
