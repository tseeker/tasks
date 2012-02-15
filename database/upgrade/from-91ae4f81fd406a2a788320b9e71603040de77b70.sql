--
-- Upgrade the database from commit ID 91ae4f81fd406a2a788320b9e71603040de77b70
--
-- Run this from the top-level directory
--


\i database/config.sql
\c :db_name

BEGIN;

	CREATE TABLE tasks_tree (
		task_id_parent					INT NOT NULL
									REFERENCES tasks( task_id )
										ON UPDATE NO ACTION
										ON DELETE CASCADE ,
		task_id_child					INT NOT NULL
									REFERENCES tasks( task_id )
										ON UPDATE NO ACTION
										ON DELETE CASCADE ,
		tt_depth					INT NOT NULL,
		PRIMARY KEY( task_id_parent , task_id_child )
	);
	GRANT SELECT ON tasks_tree TO :webapp_user;

	DROP FUNCTION tgf_item_tc_ai( ) CASCADE;
	DROP FUNCTION tgf_task_tc_ai( ) CASCADE;


	ALTER TABLE tasks
		ADD item_id INT REFERENCES items ( item_id )
				ON UPDATE NO ACTION ON DELETE CASCADE ,
		ADD task_id_parent INT;

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

	DROP INDEX i_tasks_title;

	INSERT INTO tasks_tree
		SELECT task_id, task_id , 0
			FROM tasks;
	UPDATE tasks t
		SET task_id_parent = tc.task_id
		FROM task_containers tc
		WHERE tc.tc_id = t.tc_id;
	UPDATE tasks t
		SET item_id = tc.item_id
		FROM task_containers tc
		WHERE tc.tc_id = t.tc_id AND tc.item_id IS NOT NULL;

	ALTER TABLE tasks
		ALTER item_id SET NOT NULL;
	CREATE UNIQUE INDEX i_tasks_title_toplevel
		ON tasks ( item_id , task_title )
		WHERE task_id_parent IS NULL;
	CREATE UNIQUE INDEX i_tasks_title_subtask
		ON tasks ( task_id_parent , task_title )
		WHERE task_id_parent IS NULL;

	DROP FUNCTION add_task( INT , TEXT , TEXT , INT , INT ) CASCADE;
	CREATE FUNCTION add_task( t_item INT , t_title TEXT , t_description TEXT , t_priority INT , t_user INT )
			RETURNS INT
			STRICT VOLATILE
			SECURITY INVOKER
		AS $add_task$
	DECLARE
		_logical_container	INT;
	BEGIN
		SELECT INTO _logical_container ltc_id
			FROM logical_task_containers
			WHERE task_id IS NULL;

		INSERT INTO tasks ( item_id , ltc_id , task_title , task_description , task_priority , user_id )
			VALUES ( t_item , _logical_container , t_title , t_description , t_priority , t_user );
		RETURN 0;
	EXCEPTION
		WHEN unique_violation THEN
			RETURN 1;
		WHEN foreign_key_violation THEN
			RETURN 2;
	END;
	$add_task$ LANGUAGE plpgsql;
	REVOKE EXECUTE ON FUNCTION add_task( INT , TEXT , TEXT , INT , INT ) FROM PUBLIC;
	GRANT EXECUTE ON FUNCTION add_task( INT , TEXT , TEXT , INT , INT ) TO :webapp_user;

	DROP FUNCTION tasks_add_nested( INT , TEXT , TEXT , INT , INT ) CASCADE;
	CREATE FUNCTION tasks_add_nested( t_parent INT , t_title TEXT , t_description TEXT , t_priority INT , t_user INT )
			RETURNS INT
			STRICT VOLATILE
			SECURITY INVOKER
		AS $tasks_add_nested$
	DECLARE
		_item			INT;
		_logical_container	INT;
	BEGIN
		SELECT INTO _item item_id
			FROM tasks t
				LEFT OUTER JOIN completed_tasks ct USING ( task_id )
			WHERE t.task_id = t_parent AND ct.task_id IS NULL
			FOR UPDATE OF t;
		IF NOT FOUND THEN
			RETURN 2;
		END IF;

		SELECT INTO _logical_container ltc_id
			FROM logical_task_containers
			WHERE task_id = t_parent;

		INSERT INTO tasks ( task_id_parent , item_id , ltc_id , task_title , task_description , task_priority , user_id )
			VALUES ( t_parent , _item , _logical_container , t_title , t_description , t_priority , t_user );
		RETURN 0;
	EXCEPTION
		WHEN unique_violation THEN
			RETURN 1;
	END;
	$tasks_add_nested$ LANGUAGE plpgsql;
	REVOKE EXECUTE ON FUNCTION tasks_add_nested( INT , TEXT , TEXT , INT , INT ) FROM PUBLIC;
	GRANT EXECUTE ON FUNCTION tasks_add_nested( INT , TEXT , TEXT , INT , INT ) TO :webapp_user;

	DROP FUNCTION finish_task( INT , INT , TEXT );
	CREATE FUNCTION finish_task( t_id INT , u_id INT , n_text TEXT )
			RETURNS INT
			STRICT VOLATILE
			SECURITY INVOKER
		AS $finish_task$
	BEGIN
		PERFORM 1 FROM tasks_single_view t
			WHERE task_id = t_id AND badness = 0;
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

	DROP FUNCTION restart_task( INT , INT , TEXT );
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

	DROP FUNCTION update_task( INT , INT , TEXT , TEXT , INT , INT );
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

		PERFORM 1 FROM items
			WHERE item_id = p_id
			FOR UPDATE;
		IF NOT FOUND THEN
			RETURN 2;
		END IF;

		IF t_assignee = 0 THEN
			t_assignee := NULL;
		END IF;
		UPDATE tasks SET item_id = p_id , task_title = t_title ,
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

	DROP FUNCTION update_task( INT , TEXT , TEXT , INT , INT );
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

	DROP FUNCTION tasks_move_up( _task INT , _force BOOLEAN );
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

	DROP FUNCTION tasks_move_down( _task INT , _sibling INT , _force BOOLEAN );
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

	CREATE VIEW tasks_deps_view
		AS SELECT t.task_id , COUNT( td ) AS deps_total ,
				COUNT( NULLIF( td IS NOT NULL AND dct IS NULL , FALSE ) ) AS deps_unsatisfied
			FROM tasks t
				LEFT OUTER JOIN task_dependencies td
					USING ( task_id )
				LEFT OUTER JOIN completed_tasks dct
					ON dct.task_id = td.task_id_depends
			GROUP BY t.task_id;

	CREATE VIEW tasks_tdeps_view
		AS SELECT t.task_id , COUNT( DISTINCT task_id_copyof ) AS tdeps_total ,
				COUNT( DISTINCT ( CASE
						WHEN tdn.task_id_copyof IS NOT NULL AND ct.task_id IS NULL
							THEN tdn.task_id_copyof
							ELSE NULL
				END ) ) AS tdeps_unsatisfied
			FROM tasks t
				LEFT OUTER JOIN taskdep_nodes tdn
					ON NOT tdn.tnode_reverse AND tdn.task_id = t.task_id
						AND tdn.task_id_copyof <> tdn.task_id
				LEFT OUTER JOIN completed_tasks ct
					ON ct.task_id = tdn.task_id_copyof
			GROUP BY t.task_id;

	CREATE VIEW tasks_ideps_view
		AS SELECT task_id_child AS task_id ,
				SUM( deps_total ) AS ideps_total ,
				SUM( deps_unsatisfied ) AS ideps_unsatisfied ,
				SUM( tdeps_total ) AS tideps_total ,
				SUM( tdeps_unsatisfied ) AS tideps_unsatisfied
			FROM tasks_tree tt
				INNER JOIN tasks_deps_view td
					ON td.task_id = tt.task_id_parent
				INNER JOIN tasks_tdeps_view ttd
					ON ttd.task_id = tt.task_id_parent
			GROUP BY task_id_child;

	CREATE VIEW tasks_sdeps_view
		AS SELECT t.task_id AS task_id ,
				COUNT( st ) AS sdeps_total ,
				COUNT( NULLIF( st.task_id IS NOT NULL AND sct.task_id IS NULL , FALSE ) ) AS sdeps_unsatisfied
			FROM tasks t
				LEFT OUTER JOIN tasks st
					ON st.task_id_parent = t.task_id
				LEFT OUTER JOIN completed_tasks sct
					ON sct.task_id = st.task_id
			GROUP BY t.task_id;

	DROP VIEW tasks_single_view;
	CREATE VIEW tasks_single_view
		AS SELECT t.task_id AS id, t.task_title AS title, t.item_id AS item , t.task_id_parent AS parent_task ,
				t.task_description AS description, t.task_added AS added_at,
				u1.user_view_name AS added_by, ct.completed_task_time AS completed_at,
				u2.user_view_name AS assigned_to , u2.user_id AS assigned_id ,
				u3.user_view_name AS completed_by, t.user_id AS uid ,
				t.task_priority AS priority ,
				( cmu IS NOT NULL ) AS can_move_up ,
				( _inherited.ideps_unsatisfied + _direct.deps_unsatisfied + _subs.sdeps_unsatisfied ) AS badness
			FROM tasks t
				INNER JOIN tasks_deps_view _direct
					USING ( task_id )
				INNER JOIN tasks_tdeps_view _transitive
					USING ( task_id )
				INNER JOIN tasks_ideps_view _inherited
					USING ( task_id )
				INNER JOIN tasks_sdeps_view _subs
					USING ( task_id )
				INNER JOIN users_view u1 ON u1.user_id = t.user_id
				LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id
				LEFT OUTER JOIN users_view u2 ON u2.user_id = t.user_id_assigned
				LEFT OUTER JOIN users_view u3 ON u3.user_id = ct.user_id
				LEFT OUTER JOIN tasks_can_move_up cmu ON cmu.task_id = t.task_id;
	GRANT SELECT ON tasks_single_view TO :webapp_user;

	DROP VIEW tasks_list;
	CREATE VIEW tasks_list
		AS SELECT t.task_id AS id, t.item_id AS item , t.task_id_parent AS parent_task ,
				t.task_title AS title,
				t.task_description AS description, t.task_added AS added_at,
				u1.user_view_name AS added_by,
				ct.completed_task_time AS completed_at,
				u2.user_view_name AS assigned_to ,
				u2.user_id AS assigned_to_id ,
				u3.user_view_name AS completed_by ,
				t.task_priority AS priority ,
				_direct.deps_total AS total_direct_dependencies ,
				_direct.deps_unsatisfied AS unsatisfied_direct_dependencies ,
				_transitive.tdeps_total AS total_transitive_dependencies ,
				_transitive.tdeps_unsatisfied AS unsatisfied_transitive_dependencies ,
				_subs.sdeps_total AS total_subtasks ,
				_subs.sdeps_unsatisfied AS incomplete_subtasks ,
				( CASE
					WHEN _direct.deps_total <> 0 THEN
						0
					ELSE
						_inherited.ideps_total
				END ) AS total_inherited_dependencies ,
				( CASE
					WHEN _direct.deps_total <> 0 THEN
						0
					ELSE
						_inherited.ideps_unsatisfied
				END ) AS unsatisfied_inherited_dependencies ,
				( _inherited.ideps_unsatisfied + _direct.deps_unsatisfied + _subs.sdeps_unsatisfied ) AS badness
			FROM tasks t
				INNER JOIN tasks_deps_view _direct
					USING ( task_id )
				INNER JOIN tasks_tdeps_view _transitive
					USING ( task_id )
				INNER JOIN tasks_ideps_view _inherited
					USING ( task_id )
				INNER JOIN tasks_sdeps_view _subs
					USING ( task_id )
				INNER JOIN users_view u1 ON u1.user_id = t.user_id
				LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id
				LEFT OUTER JOIN users_view u2 ON u2.user_id = t.user_id_assigned
				LEFT OUTER JOIN users_view u3 ON u3.user_id = ct.user_id;
	GRANT SELECT ON tasks_list TO :webapp_user;

	ALTER TABLE tasks
		DROP COLUMN tc_id;
	DROP TABLE task_containers;

COMMIT;
