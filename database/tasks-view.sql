/*
 * Task view
 *
 * This view is used by the application when loading individual tasks.
 */
DROP VIEW IF EXISTS tasks_single_view;
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



--
-- Task list view
--
-- This view contains all fields used to display task lists.
--

DROP VIEW IF EXISTS tasks_list;
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


