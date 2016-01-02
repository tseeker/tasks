--
-- Upgrade the database from commit ID bca6b99068cbf1dd4148d6bd5cb60e926162cfe1
--
-- Run this from the top-level directory
--


\i database/config.sql
\c :db_name

BEGIN;

	DROP FUNCTION IF EXISTS tasks_item_au( ) CASCADE;
	CREATE FUNCTION tasks_ltc_au( )
			RETURNS TRIGGER
			LANGUAGE PLPGSQL
			SECURITY DEFINER
		AS $tasks_ltc_au$
	BEGIN
		UPDATE tasks
			SET task_id_parent = (
				SELECT task_id
					FROM logical_task_containers
					WHERE ltc_id = NEW.ltc_id )
			WHERE task_id = NEW.task_id;
		RETURN NEW;
	END;
	$tasks_ltc_au$;

	REVOKE EXECUTE
		ON FUNCTION tasks_ltc_au( )
		FROM PUBLIC;

	CREATE TRIGGER tasks_ltc_au
		AFTER UPDATE OF ltc_id ON tasks
		FOR EACH ROW EXECUTE PROCEDURE tasks_ltc_au( );
COMMIT;
