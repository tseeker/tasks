\i database/config.sql
\c :db_name

BEGIN;

-- Tables from the main database structure
\i database/create-tables.sql

-- User functions
\i database/users-functions.sql

-- Items tree management and associated functions
\i database/items-tree-triggers.sql
\i database/items-functions.sql

-- Task management and task dependencies
\i database/task-containers.sql
\i database/tasks-functions.sql
\i database/task-dependencies.sql
\i database/tasks-move-sub.sql
\i database/tasks-view.sql

COMMIT;
