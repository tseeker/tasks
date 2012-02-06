-- Create a new task
CREATE OR REPLACE FUNCTION add_task( t_item INT , t_title TEXT , t_description TEXT , t_priority INT , t_user INT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $add_task$
BEGIN
	INSERT INTO tasks ( item_id , task_title , task_description , task_priority , user_id )
		VALUES ( t_item , t_title , t_description , t_priority , t_user );
	RETURN 0;
EXCEPTION
	WHEN unique_violation THEN
		RETURN 1;
	WHEN foreign_key_violation THEN
		RETURN 2;
END;
$add_task$ LANGUAGE plpgsql;


-- Mark a task as finished
CREATE OR REPLACE FUNCTION finish_task( t_id INT , u_id INT , n_text TEXT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $finish_task$
BEGIN
	BEGIN
		INSERT INTO completed_tasks ( task_id , user_id )
			VALUES ( t_id , u_id );
	EXCEPTION
		WHEN unique_violation THEN
			RETURN 1;
	END;

	INSERT INTO notes ( task_id , user_id , note_text )
		VALUES ( t_id , u_id , n_text );
	RETURN 0;
END;
$finish_task$ LANGUAGE plpgsql;


-- Restart a task
CREATE OR REPLACE FUNCTION restart_task( t_id INT , u_id INT , n_text TEXT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $restart_task$
BEGIN
	DELETE FROM completed_tasks WHERE task_id = t_id;
	IF NOT FOUND THEN
		RETURN 1;
	END IF;
	INSERT INTO notes ( task_id , user_id , note_text )
		VALUES ( t_id , u_id , n_text );
	RETURN 0;
END;
$restart_task$ LANGUAGE plpgsql;


-- Update a task
CREATE OR REPLACE FUNCTION update_task( t_id INT , p_id INT , t_title TEXT , t_description TEXT , t_priority INT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $update_task$
BEGIN
	UPDATE tasks SET item_id = p_id , task_title = t_title ,
			task_description = t_description ,
			task_priority = t_priority
		WHERE task_id = t_id;
	RETURN 0;
EXCEPTION
	WHEN unique_violation THEN
		RETURN 1;
	WHEN foreign_key_violation THEN
		RETURN 2;
END;
$update_task$ LANGUAGE plpgsql;



--
-- Task list view
--
-- This view contains all fields used to display task lists.
--

DROP VIEW IF EXISTS tasks_list;
CREATE VIEW tasks_list
	AS SELECT t.task_id AS id, t.item_id AS item, t.task_title AS title,
			t.task_description AS description, t.task_added AS added_at,
			u1.user_view_name AS added_by,
			ct.completed_task_time AS completed_at,
			u2.user_view_name AS completed_by ,
			t.task_priority AS priority ,
			bd.bad_deps AS missing_dependencies
		FROM tasks t
			INNER JOIN users_view u1 ON u1.user_id = t.user_id
			LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id
			LEFT OUTER JOIN users_view u2 ON u2.user_id = ct.user_id
			LEFT OUTER JOIN (
				SELECT td.task_id , COUNT(*) AS bad_deps
					FROM task_dependencies td
						LEFT OUTER JOIN completed_tasks dct
							ON dct.task_id = td.task_id_depends
					WHERE dct.task_id IS NULL
					GROUP BY td.task_id
				) AS bd ON bd.task_id = t.task_id;

GRANT SELECT ON tasks_list TO :webapp_user;

