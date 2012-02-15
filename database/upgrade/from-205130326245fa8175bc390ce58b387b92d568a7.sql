--
-- Upgrade the database from commit ID 205130326245fa8175bc390ce58b387b92d568a7
--
-- Run this from the top-level directory
--


\i database/config.sql
\c :db_name

BEGIN;
	DROP FUNCTION finish_task( INT , INT , TEXT );
	CREATE FUNCTION finish_task( t_id INT , u_id INT , n_text TEXT )
			RETURNS INT
			STRICT VOLATILE
			SECURITY INVOKER
		AS $finish_task$
	BEGIN
		PERFORM 1 FROM tasks_single_view t
			WHERE id = t_id AND badness = 0;
		IF NOT FOUND THEN
			RETURN 2;
		END IF;

		BEGIN
			INSERT INTO completed_tasks ( task_id , user_id )
				VALUES ( t_id , u_id );
		EXCEPTION
			WHEN unique_violation THEN
				RETURN 1;
		END;

		UPDATE tasks SET user_id_assigned = NULL WHERE task_id = t_id;
		INSERT INTO notes ( task_id , user_id , note_text )
			VALUES ( t_id , u_id , n_text );
		RETURN 0;
	END;
	$finish_task$ LANGUAGE plpgsql;
	REVOKE EXECUTE ON FUNCTION finish_task( INT , INT , TEXT ) FROM PUBLIC;
	GRANT EXECUTE ON FUNCTION finish_task( INT , INT , TEXT ) TO :webapp_user;
COMMIT;
