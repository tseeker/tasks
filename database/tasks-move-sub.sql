/*
 * View that represents possible "move down" targets for a task
 */
DROP VIEW IF EXISTS tasks_move_down_targets;
CREATE VIEW tasks_move_down_targets
	AS SELECT t.task_id , tgt.task_id AS target_id , tgt.task_title AS target_title
		FROM tasks t
			LEFT OUTER JOIN completed_tasks tct
				USING ( task_id )
			INNER JOIN tasks tgt
				USING ( ltc_id )
			INNER JOIN logical_task_containers ltc
				ON ltc.task_id = tgt.task_id
			LEFT OUTER JOIN tasks tsubs
				ON tsubs.ltc_id = ltc.ltc_id
					AND LOWER( tsubs.task_title ) = LOWER( t.task_title )
			LEFT OUTER JOIN completed_tasks csubs
				ON csubs.task_id = tgt.task_id
		WHERE tgt.task_id <> t.task_id
			AND tsubs.task_id IS NULL
			AND tct.task_id IS NULL
			AND csubs.task_id IS NULL;

GRANT SELECT ON tasks_move_down_targets TO :webapp_user;


/*
 * View that represents all tasks which can be moved up one level
 */
DROP VIEW IF EXISTS tasks_can_move_up CASCADE;
CREATE VIEW tasks_can_move_up
	AS SELECT t.task_id
		FROM tasks t
			LEFT OUTER JOIN completed_tasks tct
				USING ( task_id )
			INNER JOIN logical_task_containers ptc
				USING ( ltc_id )
			INNER JOIN tasks tgt
				ON tgt.task_id = ptc.task_id
			LEFT OUTER JOIN tasks psubs
				ON psubs.ltc_id = tgt.ltc_id
					AND LOWER( psubs.task_title ) = LOWER( t.task_title )
		WHERE tct IS NULL
			AND psubs IS NULL;



/*
 * Move the task to its grand-parent, if:
 * - there are no dependencies and reverse dependencies,
 * - or the _force parameter is true.
 */
DROP FUNCTION IF EXISTS tasks_move_up( _task INT , _force BOOLEAN );
CREATE FUNCTION tasks_move_up( _task INT , _force BOOLEAN )
		RETURNS BOOLEAN
		LANGUAGE PLPGSQL
		STRICT VOLATILE
		SECURITY DEFINER
	AS $tasks_move_up$

DECLARE
	_gp_container	INT;
	_gp_lcontainer	INT;

BEGIN
	PERFORM 1 FROM tasks_can_move_up WHERE task_id = _task;
	IF NOT FOUND THEN
		RETURN FALSE;
	END IF;

	PERFORM 1 FROM task_dependencies
		WHERE ( task_id = _task OR task_id_depends = _task ) AND NOT _force;
	IF FOUND THEN
		RETURN FALSE;
	END IF;

	SELECT INTO _gp_container , _gp_lcontainer
		gp.item_id , gp.ltc_id
		FROM tasks t 
			INNER JOIN logical_task_containers lt
				USING ( ltc_id )
			INNER JOIN tasks gp
				ON gp.task_id = lt.task_id
		WHERE t.task_id = _task;

	DELETE FROM task_dependencies
		WHERE task_id = _task OR task_id_depends = _task;
	UPDATE tasks
		SET item_id = _gp_container ,
			ltc_id = _gp_lcontainer
		WHERE task_id = _task;
	RETURN TRUE;
END;
$tasks_move_up$;

REVOKE EXECUTE ON FUNCTION tasks_move_up( INT , BOOLEAN ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION tasks_move_up( INT , BOOLEAN ) TO :webapp_user;


/*
 * Move the task into one of its siblings, if:
 * - there are no dependencies and reverse dependencies,
 * - or the _force parameter is true.
 */
DROP FUNCTION IF EXISTS tasks_move_down( _task INT , _sibling INT , _force BOOLEAN );
CREATE FUNCTION tasks_move_down( _task INT , _sibling INT , _force BOOLEAN )
		RETURNS BOOLEAN
		LANGUAGE PLPGSQL
		STRICT VOLATILE
		SECURITY DEFINER
	AS $tasks_move_down$

DECLARE
	_s_container	INT;
	_s_lcontainer	INT;

BEGIN
	PERFORM 1 FROM tasks_move_down_targets
		WHERE task_id = _task AND target_id = _sibling;
	IF NOT FOUND THEN
		RETURN FALSE;
	END IF;

	PERFORM 1 FROM task_dependencies
		WHERE ( task_id = _task OR task_id_depends = _task ) AND NOT _force;
	IF FOUND THEN
		RETURN FALSE;
	END IF;

	SELECT INTO _s_container item_id FROM tasks
		WHERE task_id = _sibling;
	SELECT INTO _s_lcontainer ltc_id
		FROM logical_task_containers
		WHERE task_id = _sibling;

	DELETE FROM task_dependencies
		WHERE task_id = _task OR task_id_depends = _task;
	UPDATE tasks
		SET item_id = _s_container ,
			ltc_id = _s_lcontainer
		WHERE task_id = _task;
	RETURN TRUE;
END;
$tasks_move_down$;

REVOKE EXECUTE ON FUNCTION tasks_move_down( INT , INT , BOOLEAN ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION tasks_move_down( INT , INT , BOOLEAN ) TO :webapp_user;
