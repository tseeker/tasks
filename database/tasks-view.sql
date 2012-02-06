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
			u2.user_view_name AS assigned_to ,
			u3.user_view_name AS completed_by ,
			t.task_priority AS priority ,
			bd.bad_deps AS missing_dependencies ,
			mtd.trans_missing AS total_missing_dependencies
		FROM tasks t
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
				SELECT tdn.task_id , COUNT( DISTINCT task_id_copyof ) AS trans_missing
					FROM taskdep_nodes tdn
						LEFT OUTER JOIN completed_tasks ct
							ON ct.task_id = task_id_copyof
					WHERE NOT tnode_reverse AND ct.task_id IS NULL
						AND tdn.task_id <> tdn.task_id_copyof
					GROUP BY tdn.task_id
				) AS mtd ON mtd.task_id = t.task_id;

GRANT SELECT ON tasks_list TO :webapp_user;


