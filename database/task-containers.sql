/*
 * Triggers to handle the task hierarchy
 */


/*
 * Create a logical task container for each new task
 * (deleting the task will delete the container due to
 * "on delete cascade"). Also insert data about the tree's
 * current structure.
 */
DROP FUNCTION IF EXISTS tasks_tree_ai( ) CASCADE;
CREATE FUNCTION tasks_tree_ai( )
		RETURNS TRIGGER
		LANGUAGE PLPGSQL
		SECURITY DEFINER
	AS $tasks_tree_ai$
BEGIN
	INSERT INTO logical_task_containers ( task_id )
		VALUES ( NEW.task_id );

	INSERT INTO tasks_tree( task_id_parent , task_id_child , tt_depth )
		VALUES ( NEW.task_id , NEW.task_id , 0 );
	INSERT INTO tasks_tree( task_id_parent , task_id_child , tt_depth )
		SELECT x.task_id_parent, NEW.task_id, x.tt_depth + 1
			FROM tasks_tree x WHERE x.task_id_child = NEW.task_id_parent;

	RETURN NEW;
END;
$tasks_tree_ai$;

REVOKE EXECUTE
	ON FUNCTION tasks_tree_ai( )
	FROM PUBLIC;

CREATE TRIGGER tasks_tree_ai
	AFTER INSERT ON tasks
	FOR EACH ROW EXECUTE PROCEDURE tasks_tree_ai( );


/*
 * Before updates on the task hierarchy, make sure everything the changes
 * are valid
 */
DROP FUNCTION IF EXISTS tasks_tree_bu( ) CASCADE;
CREATE FUNCTION tasks_tree_bu( )
		RETURNS TRIGGER
		LANGUAGE PLPGSQL
		SECURITY DEFINER
	AS $tasks_tree_bu$
BEGIN
	PERFORM 1 FROM tasks_tree
		WHERE ( task_id_parent , task_id_child ) = ( NEW.task_id , NEW.task_id_parent );
	IF FOUND THEN
		RAISE EXCEPTION 'Update blocked, it would create a loop.';
	END IF;

	RETURN NEW;
END;
$tasks_tree_bu$;

REVOKE EXECUTE
	ON FUNCTION tasks_tree_bu( )
	FROM PUBLIC;

CREATE TRIGGER tasks_tree_bu
	BEFORE UPDATE OF task_id_parent ON tasks
	FOR EACH ROW EXECUTE PROCEDURE tasks_tree_bu( );


/*
 * After updates of the task hierarchy, make sure the tree structure cache
 * is up-to-date.
 */
DROP FUNCTION IF EXISTS tasks_tree_au( ) CASCADE;
CREATE FUNCTION tasks_tree_au( )
		RETURNS TRIGGER
		LANGUAGE PLPGSQL
		SECURITY DEFINER
	AS $tasks_tree_au$
BEGIN
	-- Remove existing lineage for the updated object and its children
	IF OLD.task_id_parent IS NOT NULL THEN
		DELETE FROM tasks_tree AS te2
			USING tasks_tree te1
			WHERE te2.task_id_child = te1.task_id_child
				AND te1.task_id_parent = NEW.task_id
				AND te2.tt_depth > te1.tt_depth;
	END IF;

	-- Create new lineage
	IF NEW.task_id_parent IS NOT NULL THEN
		INSERT INTO tasks_tree ( task_id_parent , task_id_child , tt_depth )
			SELECT te1.task_id_parent , te2.task_id_child , te1.tt_depth + te2.tt_depth + 1
				FROM tasks_tree te1 , tasks_tree te2
				WHERE te1.task_id_child = NEW.task_id_parent
					AND te2.task_id_parent = NEW.task_id;
		UPDATE tasks t1
			SET item_id = t2.item_id
			FROM tasks t2
			WHERE t1.task_id = NEW.task_id
				AND t2.task_id = NEW.task_id_parent;
	END IF;

	RETURN NEW;
END;
$tasks_tree_au$;

REVOKE EXECUTE
	ON FUNCTION tasks_tree_au( )
	FROM PUBLIC;

CREATE TRIGGER tasks_tree_au
	AFTER UPDATE OF task_id_parent ON tasks
	FOR EACH ROW EXECUTE PROCEDURE tasks_tree_au( );


/*
 * After an update on some task's containing item, update all children accordingly.
 */
DROP FUNCTION IF EXISTS tasks_item_au( ) CASCADE;
CREATE FUNCTION tasks_item_au( )
		RETURNS TRIGGER
		LANGUAGE PLPGSQL
		SECURITY DEFINER
	AS $tasks_item_au$
BEGIN
	UPDATE tasks
		SET item_id = NEW.item_id
		WHERE task_id_parent = NEW.task_id;
	RETURN NEW;
END;
$tasks_item_au$;

REVOKE EXECUTE
	ON FUNCTION tasks_item_au( )
	FROM PUBLIC;

CREATE TRIGGER tasks_item_au
	AFTER UPDATE OF item_id ON tasks
	FOR EACH ROW EXECUTE PROCEDURE tasks_item_au( );


/*
 * After an update on some task's logical container, set the task's parent ID.
 */
DROP FUNCTION IF EXISTS tasks_ltc_au( ) CASCADE;
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
