--
-- Upgrade the database from commit ID 49cc53e31f80084bd5f530f0f37d6f8d219ea3b0
--
-- Run this from the top-level directory
--

\i database/config.sql
\c :db_name

BEGIN;

	CREATE TABLE task_containers (
		tc_id		SERIAL NOT NULL PRIMARY KEY ,
		task_id		INT UNIQUE ,
		item_id		INT UNIQUE ,
		CHECK( task_id IS NULL AND item_id IS NOT NULL OR task_id IS NOT NULL AND item_id IS NULL )
	);
	GRANT SELECT ON task_containers TO :webapp_user;
	ALTER TABLE task_containers
		ADD FOREIGN KEY ( item_id ) REFERENCES items( item_id )
			ON UPDATE NO ACTION
			ON DELETE CASCADE ,
		ADD FOREIGN KEY ( task_id ) REFERENCES tasks( task_id )
			ON UPDATE NO ACTION
			ON DELETE CASCADE;
	INSERT INTO task_containers ( item_id )
		SELECT item_id FROM items;
	INSERT INTO task_containers ( task_id )
		SELECT task_id FROM tasks;

	CREATE TABLE logical_task_containers(
		ltc_id		SERIAL NOT NULL PRIMARY KEY ,
		task_id		INT UNIQUE ,
		CHECK( ltc_id = 1 AND task_id IS NULL OR ltc_id <> 1 AND task_id IS NOT NULL )
	);
	INSERT INTO logical_task_containers DEFAULT VALUES;
	ALTER TABLE logical_task_containers
		ADD FOREIGN KEY ( task_id ) REFERENCES tasks( task_id )
			ON UPDATE NO ACTION
			ON DELETE CASCADE;
	GRANT SELECT ON logical_task_containers TO :webapp_user;
	INSERT INTO logical_task_containers (task_id)
		SELECT task_id FROM tasks;

	ALTER TABLE tasks
		ADD tc_id INT ,
		ADD ltc_id INT;
	UPDATE tasks t
		SET ltc_id = 1 , tc_id = tc.tc_id
		FROM task_containers tc
		WHERE tc.item_id = t.item_id;
	ALTER TABLE tasks
		ALTER tc_id SET NOT NULL ,
		ALTER ltc_id SET NOT NULL ,
		ADD FOREIGN KEY ( tc_id ) REFERENCES task_containers ( tc_id )
			ON UPDATE NO ACTION ON DELETE CASCADE ,
		ADD FOREIGN KEY ( ltc_id ) REFERENCES logical_task_containers ( ltc_id )
			ON UPDATE NO ACTION ON DELETE CASCADE ,
		DROP COLUMN item_id CASCADE;
	CREATE UNIQUE INDEX i_tasks_title ON tasks (tc_id, task_title);
	CREATE UNIQUE INDEX i_tasks_ltc ON tasks (task_id , ltc_id);

	ALTER TABLE task_dependencies ADD ltc_id INT;
	UPDATE task_dependencies SET ltc_id = 1;
	ALTER TABLE task_dependencies
		ALTER ltc_id SET NOT NULL ,
		DROP CONSTRAINT task_dependencies_task_id_depends_fkey ,
		DROP CONSTRAINT task_dependencies_task_id_fkey ,
		ADD CONSTRAINT fk_taskdep_task
			FOREIGN KEY ( ltc_id , task_id ) REFERENCES tasks( ltc_id , task_id )
				ON UPDATE NO ACTION ON DELETE CASCADE ,
		ADD CONSTRAINT fk_taskdep_dependency
			FOREIGN KEY ( ltc_id , task_id_depends ) REFERENCES tasks( ltc_id , task_id )
				ON UPDATE NO ACTION ON DELETE CASCADE;

	CREATE FUNCTION tgf_item_tc_ai( )
			RETURNS TRIGGER
			LANGUAGE PLPGSQL
			SECURITY DEFINER
		AS $tgf_item_tc_ai$
	BEGIN
		INSERT INTO task_containers ( item_id )
			VALUES ( NEW.item_id );
		RETURN NEW;
	END;
	$tgf_item_tc_ai$;
	REVOKE EXECUTE ON FUNCTION tgf_item_tc_ai( ) FROM PUBLIC;
	CREATE TRIGGER tg_item_tc_ai AFTER INSERT ON items
		FOR EACH ROW EXECUTE PROCEDURE tgf_item_tc_ai( );

	CREATE FUNCTION tgf_task_tc_ai( )
			RETURNS TRIGGER
			LANGUAGE PLPGSQL
			SECURITY DEFINER
		AS $tgf_task_tc_ai$
	BEGIN
		INSERT INTO task_containers ( task_id )
			VALUES ( NEW.task_id );
		INSERT INTO logical_task_containers ( task_id )
			VALUES ( NEW.task_id );
		RETURN NEW;
	END;
	$tgf_task_tc_ai$;
	REVOKE EXECUTE ON FUNCTION tgf_task_tc_ai( ) FROM PUBLIC;
	CREATE TRIGGER tg_task_tc_ai AFTER INSERT ON tasks
		FOR EACH ROW EXECUTE PROCEDURE tgf_task_tc_ai( );

	DROP FUNCTION add_task( INT , TEXT , TEXT , INT , INT ) CASCADE;
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


	DROP FUNCTION finish_task( INT , INT , TEXT );
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

	DROP FUNCTION update_task( INT , INT  , TEXT , TEXT , INT , INT );
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

	DROP FUNCTION tasks_possible_dependencies( INT );
	CREATE FUNCTION tasks_possible_dependencies( o_id INT )
		RETURNS SETOF tasks
		STRICT STABLE
	AS $tasks_possible_dependencies$
		SELECT t.*
			FROM tasks t
				INNER JOIN tasks t2 USING ( ltc_id )
			WHERE t2.task_id = $1 AND t.task_id NOT IN (
				SELECT d.task_id_depends AS id
					FROM taskdep_nodes n1
						INNER JOIN task_dependencies d
							ON d.task_id = n1.task_id_copyof
					WHERE n1.task_id = $1 AND n1.tnode_reverse
				UNION ALL SELECT n.task_id_copyof AS id
					FROM task_dependencies d1
						INNER JOIN taskdep_nodes n
							ON n.task_id = d1.task_id_depends
					WHERE d1.task_id = $1 AND n.tnode_reverse
				UNION ALL SELECT task_id_copyof AS id
					FROM taskdep_nodes
					WHERE task_id = $1
			);
	$tasks_possible_dependencies$ LANGUAGE sql;
	REVOKE EXECUTE ON FUNCTION tasks_possible_dependencies( INT ) FROM PUBLIC;
	GRANT EXECUTE ON FUNCTION tasks_possible_dependencies( INT ) TO :webapp_user;

	DROP FUNCTION tasks_add_dependency( INT , INT );
	CREATE FUNCTION tasks_add_dependency( t_id INT , t_dependency INT )
			RETURNS INT
			STRICT VOLATILE
			SECURITY INVOKER
		AS $tasks_add_dependency$

	DECLARE
		ltc	INT;

	BEGIN
		SELECT INTO ltc ltc_id FROM tasks WHERE task_id = t_id;
		IF NOT FOUND THEN
			RETURN 1;
		END IF;

		INSERT INTO task_dependencies( ltc_id , task_id , task_id_depends )
			VALUES ( ltc , t_id , t_dependency );
		RETURN 0;
	EXCEPTION
		WHEN foreign_key_violation THEN
			RETURN 3;
		WHEN check_violation THEN
			RETURN 2;
	END;
	$tasks_add_dependency$ LANGUAGE plpgsql;
	REVOKE EXECUTE ON FUNCTION tasks_add_dependency( INT , INT ) FROM PUBLIC;
	GRANT EXECUTE ON FUNCTION tasks_add_dependency( INT , INT ) TO :webapp_user;

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
			gp.tc_id , gp.ltc_id
			FROM tasks t 
				INNER JOIN logical_task_containers lt
					USING ( ltc_id )
				INNER JOIN tasks gp
					ON gp.task_id = lt.task_id
			WHERE t.task_id = _task;

		DELETE FROM task_dependencies
			WHERE task_id = _task OR task_id_depends = _task;
		UPDATE tasks
			SET tc_id = _gp_container ,
				ltc_id = _gp_lcontainer
			WHERE task_id = _task;
		RETURN TRUE;
	END;
	$tasks_move_up$;
	REVOKE EXECUTE ON FUNCTION tasks_move_up( INT , BOOLEAN ) FROM PUBLIC;
	GRANT EXECUTE ON FUNCTION tasks_move_up( INT , BOOLEAN ) TO :webapp_user;

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

		SELECT INTO _s_container tc_id
			FROM task_containers
			WHERE task_id = _sibling;
		SELECT INTO _s_lcontainer ltc_id
			FROM logical_task_containers
			WHERE task_id = _sibling;

		DELETE FROM task_dependencies
			WHERE task_id = _task OR task_id_depends = _task;
		UPDATE tasks
			SET tc_id = _s_container ,
				ltc_id = _s_lcontainer
			WHERE task_id = _task;
		RETURN TRUE;
	END;
	$tasks_move_down$;
	REVOKE EXECUTE ON FUNCTION tasks_move_down( INT , INT , BOOLEAN ) FROM PUBLIC;
	GRANT EXECUTE ON FUNCTION tasks_move_down( INT , INT , BOOLEAN ) TO :webapp_user;

	CREATE VIEW tasks_single_view
		AS SELECT t.task_id AS id, t.task_title AS title, tc.item_id AS item , tc.task_id AS parent_task ,
				t.task_description AS description, t.task_added AS added_at,
				u1.user_view_name AS added_by, ct.completed_task_time AS completed_at,
				u2.user_view_name AS assigned_to , u2.user_id AS assigned_id ,
				u3.user_view_name AS completed_by, t.user_id AS uid ,
				t.task_priority AS priority ,
				( cmu IS NOT NULL ) AS can_move_up
			FROM tasks t
				INNER JOIN task_containers tc USING ( tc_id )
				INNER JOIN users_view u1 ON u1.user_id = t.user_id
				LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id
				LEFT OUTER JOIN users_view u2 ON u2.user_id = t.user_id_assigned
				LEFT OUTER JOIN users_view u3 ON u3.user_id = ct.user_id
				LEFT OUTER JOIN tasks_can_move_up cmu ON cmu.task_id = t.task_id;
	GRANT SELECT ON tasks_single_view TO :webapp_user;

	CREATE VIEW tasks_list
		AS SELECT t.task_id AS id, tc.item_id AS item , tc.task_id AS parent_task ,
				t.task_title AS title,
				t.task_description AS description, t.task_added AS added_at,
				u1.user_view_name AS added_by,
				ct.completed_task_time AS completed_at,
				u2.user_view_name AS assigned_to ,
				u2.user_id AS assigned_to_id ,
				u3.user_view_name AS completed_by ,
				t.task_priority AS priority ,
				bd.bad_deps AS missing_dependencies ,
				bc.bad_children AS missing_subtasks ,
				( CASE
					WHEN mtd.trans_missing IS NULL AND bc.bad_children IS NULL THEN
						NULL::BIGINT
					WHEN mtd.trans_missing IS NULL THEN
						bc.bad_children
					WHEN bc.bad_children IS NULL THEN
						mtd.trans_missing
					ELSE
						bc.bad_children + mtd.trans_missing
				END ) AS total_missing_dependencies
			FROM tasks t
				INNER JOIN task_containers tc USING( tc_id )
				INNER JOIN users_view u1 ON u1.user_id = t.user_id
				LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id
				LEFT OUTER JOIN users_view u2 ON u2.user_id = t.user_id_assigned
				LEFT OUTER JOIN users_view u3 ON u3.user_id = ct.user_id
				LEFT OUTER JOIN (
					SELECT td.task_id , COUNT(*) AS bad_deps
						FROM task_dependencies td
							LEFT OUTER JOIN completed_tasks dct
								ON dct.task_id = td.task_id_depends
						WHERE dct.task_id IS NULL
						GROUP BY td.task_id
					) AS bd ON bd.task_id = t.task_id
				LEFT OUTER JOIN (
					SELECT ltc.task_id , COUNT( * ) AS bad_children
						FROM logical_task_containers ltc
							INNER JOIN tasks t
								USING ( ltc_id )
							LEFT OUTER JOIN completed_tasks ct
								ON ct.task_id = t.task_id
						WHERE ct.task_id IS NULL
						GROUP BY ltc.task_id
					) AS bc ON bc.task_id = t.task_id
				LEFT OUTER JOIN (
					SELECT tdn.task_id , COUNT( DISTINCT task_id_copyof ) AS trans_missing
						FROM taskdep_nodes tdn
							LEFT OUTER JOIN completed_tasks ct
								ON ct.task_id = task_id_copyof
						WHERE NOT tnode_reverse AND ct.task_id IS NULL
							AND tdn.task_id <> tdn.task_id_copyof
						GROUP BY tdn.task_id
					) AS mtd ON mtd.task_id = t.task_id;

	GRANT SELECT ON tasks_list TO :webapp_user;

COMMIT;
