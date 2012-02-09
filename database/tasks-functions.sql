-- Create a new task
DROP FUNCTION IF EXISTS add_task( INT , TEXT , TEXT , INT , INT ) CASCADE;
CREATE FUNCTION add_task( t_item INT , t_title TEXT , t_description TEXT , t_priority INT , t_user INT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $add_task$
DECLARE
	_container		INT;
	_logical_container	INT;
BEGIN
	SELECT INTO _container tc_id
		FROM task_containers
		WHERE item_id = t_item;
	IF NOT FOUND THEN
		RETURN 2;
	END IF;

	SELECT INTO _logical_container ltc_id
		FROM logical_task_containers
		WHERE task_id IS NULL;

	INSERT INTO tasks ( tc_id , ltc_id , task_title , task_description , task_priority , user_id )
		VALUES ( _container , _logical_container , t_title , t_description , t_priority , t_user );
	RETURN 0;
EXCEPTION
	WHEN unique_violation THEN
		RETURN 1;
END;
$add_task$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION add_task( INT , TEXT , TEXT , INT , INT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION add_task( INT , TEXT , TEXT , INT , INT ) TO :webapp_user;

-- Create a new nested task
DROP FUNCTION IF EXISTS tasks_add_nested( INT , TEXT , TEXT , INT , INT ) CASCADE;
CREATE FUNCTION tasks_add_nested( t_parent INT , t_title TEXT , t_description TEXT , t_priority INT , t_user INT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $tasks_add_nested$
DECLARE
	_container		INT;
	_logical_container	INT;
BEGIN
	SELECT INTO _container tc.tc_id
		FROM task_containers tc
			INNER JOIN tasks t USING ( task_id )
			LEFT OUTER JOIN completed_tasks ct USING ( task_id )
		WHERE t.task_id = t_parent AND ct.task_id IS NULL;
	IF NOT FOUND THEN
		RETURN 2;
	END IF;

	SELECT INTO _logical_container ltc_id
		FROM logical_task_containers
		WHERE task_id = t_parent;

	INSERT INTO tasks ( tc_id , ltc_id , task_title , task_description , task_priority , user_id )
		VALUES ( _container , _logical_container , t_title , t_description , t_priority , t_user );
	RETURN 0;
EXCEPTION
	WHEN unique_violation THEN
		RETURN 1;
END;
$tasks_add_nested$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION tasks_add_nested( INT , TEXT , TEXT , INT , INT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION tasks_add_nested( INT , TEXT , TEXT , INT , INT ) TO :webapp_user;


-- Mark a task as finished
DROP FUNCTION IF EXISTS finish_task( INT , INT , TEXT );
CREATE FUNCTION finish_task( t_id INT , u_id INT , n_text TEXT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $finish_task$
BEGIN
	PERFORM 1
		FROM tasks t
			LEFT OUTER JOIN (
				SELECT ltc.task_id , COUNT( * ) AS c
					FROM logical_task_containers ltc
						INNER JOIN tasks t
							USING ( ltc_id )
						LEFT OUTER JOIN completed_tasks ct
							ON ct.task_id = t.task_id
					WHERE ltc.task_id = t_id
						AND ct.task_id IS NULL
					GROUP BY ltc.task_id
				) s1 USING ( task_id )
			LEFT OUTER JOIN (
				SELECT td.task_id , COUNT( * ) AS c
					FROM task_dependencies td
						LEFT OUTER JOIN completed_tasks ct
							ON ct.task_id = td.task_id_depends
					WHERE td.task_id = t_id
						AND ct.task_id IS NULL
					GROUP BY td.task_id
				) s2 USING ( task_id )
		WHERE task_id = t_id AND s1.c IS NULL AND s2.c IS NULL;
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


-- Restart a task
DROP FUNCTION IF EXISTS restart_task( INT , INT , TEXT );
CREATE FUNCTION restart_task( t_id INT , u_id INT , n_text TEXT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $restart_task$
BEGIN
	PERFORM 1
		FROM tasks t
			INNER JOIN logical_task_containers ltc
				USING ( ltc_id )
			INNER JOIN completed_tasks ct
				ON ct.task_id = ltc.task_id
		WHERE t.task_id = t_id;
	IF FOUND THEN
		RETURN 2;
	END IF;

	PERFORM 1
		FROM task_dependencies td
			INNER JOIN completed_tasks ct
				USING ( task_id )
		WHERE td.task_id_depends = t_id;
	IF FOUND THEN
		RETURN 2;
	END IF;

	DELETE FROM completed_tasks WHERE task_id = t_id;
	IF NOT FOUND THEN
		RETURN 1;
	END IF;
	UPDATE tasks SET user_id_assigned = u_id
		WHERE task_id = t_id;
	INSERT INTO notes ( task_id , user_id , note_text )
		VALUES ( t_id , u_id , n_text );
	RETURN 0;
END;
$restart_task$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION restart_task( INT , INT , TEXT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION restart_task( INT , INT , TEXT ) TO :webapp_user;


-- Update a task
DROP FUNCTION IF EXISTS update_task( INT , INT , TEXT , TEXT , INT , INT );
CREATE FUNCTION update_task( t_id INT , p_id INT , t_title TEXT , t_description TEXT , t_priority INT , t_assignee INT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $update_task$

DECLARE
	tc	INT;

BEGIN
	PERFORM 1
		FROM tasks
			INNER JOIN logical_task_containers
				USING ( ltc_id )
			LEFT OUTER JOIN completed_tasks
				ON tasks.task_id = completed_tasks.task_id
		WHERE tasks.task_id = t_id
			AND logical_task_containers.task_id IS NULL
			AND completed_task_time IS NULL
		FOR UPDATE OF tasks;
	IF NOT FOUND THEN
		RETURN 4;
	END IF;

	SELECT INTO tc tc_id FROM task_containers
		WHERE item_id = p_id;
	IF NOT FOUND THEN
		RETURN 2;
	END IF;

	IF t_assignee = 0 THEN
		t_assignee := NULL;
	END IF;
	UPDATE tasks SET tc_id = tc , task_title = t_title ,
			task_description = t_description ,
			task_priority = t_priority ,
			user_id_assigned = t_assignee
		WHERE task_id = t_id;

	RETURN 0;
EXCEPTION
	WHEN unique_violation THEN
		RETURN 1;
	WHEN foreign_key_violation THEN
		RETURN 3;
END;
$update_task$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION update_task( INT , INT , TEXT , TEXT , INT , INT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION update_task( INT , INT , TEXT , TEXT , INT , INT ) TO :webapp_user;


-- Update a nested task
DROP FUNCTION IF EXISTS update_task( INT , TEXT , TEXT , INT , INT );
CREATE FUNCTION update_task( t_id INT , t_title TEXT , t_description TEXT , t_priority INT , t_assignee INT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $update_task$
BEGIN
	PERFORM 1
		FROM tasks
			INNER JOIN logical_task_containers
				USING ( ltc_id )
			LEFT OUTER JOIN completed_tasks
				ON tasks.task_id = completed_tasks.task_id
		WHERE tasks.task_id = t_id
			AND logical_task_containers.task_id IS NOT NULL
			AND completed_task_time IS NULL
		FOR UPDATE OF tasks;
	IF NOT FOUND THEN
		RETURN 4;
	END IF;

	IF t_assignee = 0 THEN
		t_assignee := NULL;
	END IF;
	UPDATE tasks
		SET task_title = t_title ,
			task_description = t_description ,
			task_priority = t_priority ,
			user_id_assigned = t_assignee
		WHERE task_id = t_id;
	RETURN 0;
EXCEPTION
	WHEN unique_violation THEN
		RETURN 1;
	WHEN foreign_key_violation THEN
		RETURN 2;
END;
$update_task$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION update_task( INT , TEXT , TEXT , INT , INT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION update_task( INT , TEXT , TEXT , INT , INT ) TO :webapp_user;
