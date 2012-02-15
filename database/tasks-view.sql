/*
 * Direct dependencies view
 * -------------------------
 *
 * This view counts the amount of direct dependencies (total and unsatisfied)
 * for each task.
 */
DROP VIEW IF EXISTS tasks_deps_view CASCADE;
CREATE VIEW tasks_deps_view
	AS SELECT t.task_id , COUNT( td ) AS deps_total ,
			COUNT( NULLIF( td IS NOT NULL AND dct IS NULL , FALSE ) ) AS deps_unsatisfied
		FROM tasks t
			LEFT OUTER JOIN task_dependencies td
				USING ( task_id )
			LEFT OUTER JOIN completed_tasks dct
				ON dct.task_id = td.task_id_depends
		GROUP BY t.task_id;


/*
 * Transitive dependencies view
 * -----------------------------
 *
 * This view counts the amount of total and unsatisfied dependencies for each
 * task, based on the fact that task dependencies are transitive. Each task
 * in the graph is only counted once.
 */
DROP VIEW IF EXISTS tasks_tdeps_view CASCADE;
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


/*
 * Inherited dependencies view
 * ----------------------------
 *
 * This view includes dependency counts for all tasks, including dependencies inherited
 * from parents in the case of sub-tasks.
 */
DROP VIEW IF EXISTS tasks_ideps_view CASCADE;
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


/*
 * Sub-tasks view
 * ---------------
 *
 * This view counts sub-tasks and the amount of sub-tasks that have not been
 * completed yet.
 */
DROP VIEW IF EXISTS tasks_sdeps_view CASCADE;
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
/*
 * Task view
 *
 * This view is used by the application when loading individual tasks.
 */
DROP VIEW IF EXISTS tasks_single_view;
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



--
-- Task list view
--
-- This view contains all fields used to display task lists.
--

DROP VIEW IF EXISTS tasks_list;
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


