\i database/config.sql

/*
 * Move a set of tasks from a container to another.
 * Returns the following error codes:
 *	0	No error
 *	1	Deleted tasks
 *	2	Moved tasks (not in specified container)
 *	3	Target item/task deleted
 *	4	Moving tasks to one of their children
 *	5	Dependencies would be broken, and _force is FALSE
 */
DROP FUNCTION IF EXISTS tasks_move( BOOLEAN , INT , BOOLEAN , INT , BOOLEAN , INT[] );
CREATE FUNCTION tasks_move( _fromTask BOOLEAN , _fromId INT , _toTask BOOLEAN , _toId INT , _force BOOLEAN , _tasks INT[] )
		RETURNS INT
		LANGUAGE PLPGSQL
		STRICT VOLATILE
		SECURITY DEFINER
	AS $tasks_move$

DECLARE
	_i		INT;
	_ltc_source	INT;
	_ltc_dest	INT;

BEGIN
	-- Create the temporary table for tasks
	PERFORM _tm_create_temp( _tasks );

	-- Make sure all specified tasks exists
	SELECT INTO _i COUNT( * )
		FROM _tm_tasks
			INNER JOIN tasks USING( task_id );
	IF _i <> array_length( _tasks , 1 ) THEN
		RETURN 1;
	END IF;

	-- Make sure all tasks match the specified container
	PERFORM task_id
		FROM _tm_tasks
			INNER JOIN tasks USING( task_id )
		WHERE ( task_id_parent IS NOT NULL ) <> _fromTask
			OR ( CASE
				WHEN task_id_parent IS NULL THEN
					item_id
				ELSE
					task_id_parent
				END ) <> _fromId;
	IF FOUND THEN
		RETURN 2;
	END IF;

	-- If the source and destination are the same, we're done.
	IF _fromTask = _toTask AND _fromId = _toId THEN
		RETURN 0;
	END IF;

	-- Make sure that the destination exists. Also get the target LTC ID.
	IF _toTask THEN
		SELECT INTO _ltc_dest ltc_id
			FROM logical_task_containers
			WHERE task_id = _toId;
	ELSE
		SELECT INTO _ltc_dest ltc_id
			FROM logical_task_containers
			WHERE task_id IS NULL;
		PERFORM item_id FROM items WHERE item_id = _toId;
	END IF;
	IF NOT FOUND THEN
		RETURN 3;
	END IF;

	-- If we're moving to a task, make sure it isn't a child of any of the
	-- tasks that are being moved.
	IF _toTask THEN
		PERFORM * FROM _tm_tasks _mv
			INNER JOIN tasks_tree _tree
				ON _mv.task_id = _tree.task_id_parent
			WHERE task_id_child = _toId;
		IF FOUND THEN
			RETURN 4;
		END IF;
	END IF;

	-- Get the source LTC ID.
	IF _fromTask THEN
		SELECT INTO _ltc_source ltc_id
			FROM logical_task_containers
			WHERE task_id = _fromId;
	ELSE
		SELECT INTO _ltc_source ltc_id
			FROM logical_task_containers
			WHERE task_id IS NULL;
	END IF;

	-- If we're changing the LTC, handle dependencies.
	IF _ltc_source <> _ltc_dest THEN
		-- Start with external dependencies
		IF NOT _force THEN
			-- Check them if we're not forcing the move.
			PERFORM _tdn.task_id
				FROM taskdep_nodes _tdn
					INNER JOIN _tm_tasks _tmt
						USING( task_id )
					LEFT OUTER JOIN _tm_tasks _tmt2
						ON _tmt2.task_id = _tdn.task_id_copyof
				WHERE _tdn.tnode_depth = 1 AND _tmt2.task_id IS NULL;
			IF FOUND THEN
				RETURN 5;
			END IF;
		ELSE
			-- Otherwise, break them.
			DELETE FROM task_dependencies
				WHERE taskdep_id IN (
					SELECT DISTINCT _tdn.taskdep_id
						FROM taskdep_nodes _tdn
							INNER JOIN _tm_tasks _tmt
								USING( task_id )
							LEFT OUTER JOIN _tm_tasks _tmt2
								ON _tmt2.task_id = _tdn.task_id_copyof
						WHERE _tdn.tnode_depth = 1 AND _tmt2.task_id IS NULL
				);
		END IF;

		-- Store all internal dependencies, we'll recreate them after
		-- the tasks have been moved.
		SET LOCAL client_min_messages=warning;
		DROP TABLE IF EXISTS _tm_deps;
		RESET client_min_messages;
		CREATE TEMPORARY TABLE _tm_deps(
			task_id	INT ,
			task_id_depends INT
		) ON COMMIT DROP;
		INSERT INTO _tm_deps ( task_id , task_id_depends )
			SELECT task_id , task_id_depends
				FROM task_dependencies
					INNER JOIN _tm_tasks USING ( task_id );
		DELETE FROM task_dependencies
			WHERE task_id IN ( SELECT task_id FROM _tm_tasks );
	END IF;

	-- We're ready to move the tasks themselves.
	IF _toTask THEN
		SELECT INTO _i item_id FROM tasks WHERE task_id = _toId;
	ELSE
		_i := _toId;
	END IF;
	UPDATE tasks SET item_id = _i , ltc_id = _ltc_dest
		WHERE task_id IN (
			SELECT task_id FROM _tm_tasks
		);

	-- Restore deleted dependencies
	IF _ltc_dest <> _ltc_source THEN
		INSERT INTO task_dependencies ( task_id , task_id_depends , ltc_id )
			SELECT task_id , task_id_depends , _ltc_dest
				FROM _tm_deps;
	END IF;

	RETURN 0;
END;
$tasks_move$;

REVOKE EXECUTE ON FUNCTION tasks_move( BOOLEAN , INT , BOOLEAN , INT , BOOLEAN , INT[] ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION tasks_move( BOOLEAN , INT , BOOLEAN , INT , BOOLEAN , INT[] ) TO :webapp_user;


/*
 * Function used by tasks_move to insert all tasks into a temporary table.
 */
DROP FUNCTION IF EXISTS _tm_create_temp( INT[] );
CREATE FUNCTION _tm_create_temp( _tasks INT[] )
		RETURNS VOID
		LANGUAGE PLPGSQL
		STRICT VOLATILE
		SECURITY INVOKER
	AS $_tm_create_temp$
DECLARE
	_i	INT;
BEGIN
	SET LOCAL client_min_messages=warning;
	DROP TABLE IF EXISTS _tm_tasks;
	RESET client_min_messages;

	CREATE TEMPORARY TABLE _tm_tasks(
		task_id	INT
	) ON COMMIT DROP;
	FOR _i IN array_lower( _tasks , 1 ) .. array_upper( _tasks , 1 )
	LOOP
		INSERT INTO _tm_tasks( task_id ) VALUES ( _tasks[ _i ] );
	END LOOP;
END;
$_tm_create_temp$;
REVOKE EXECUTE ON FUNCTION _tm_create_temp( INT[] ) FROM PUBLIC;
