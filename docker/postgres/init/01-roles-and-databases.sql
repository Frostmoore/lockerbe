-- Ruoli e database di lockerbe.
--
-- La separazione dei due ruoli NON è burocrazia: è il presupposto della Row Level
-- Security (piano §3.2). Se il ruolo applicativo fosse superuser, o proprietario
-- delle tabelle senza FORCE RLS, le policy verrebbero saltate SILENZIOSAMENTE e i
-- test di isolamento passerebbero mentendo.
--
--   locker_owner : proprietario dello schema. Esegue SOLO le migration.
--   locker_app   : ruolo di runtime. NON superuser, NON owner, NOBYPASSRLS.

CREATE ROLE locker_owner
    LOGIN PASSWORD 'locker_owner'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

CREATE ROLE locker_app
    LOGIN PASSWORD 'locker_app'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

CREATE DATABASE locker      OWNER locker_owner;
CREATE DATABASE locker_test OWNER locker_owner;
