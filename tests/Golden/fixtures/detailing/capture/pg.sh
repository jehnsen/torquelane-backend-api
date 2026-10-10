#!/bin/sh
# psql inside the local docker Postgres. Usage: pg.sh <database> [psql args...]; SQL on stdin when no -c.
db="$1"; shift
exec docker exec -i torquelane-api-postgres-1 psql -U torquelane -d "$db" -q "$@"
