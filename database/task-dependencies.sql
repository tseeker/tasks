--
-- Table, indexes and foreign keys
--

CREATE TABLE taskdep_nodes(
	task_id			INT NOT NULL
		REFERENCES tasks( task_id )
			ON DELETE CASCADE ,
	tnode_reverse		BOOLEAN NOT NULL ,
	tnode_id		SERIAL NOT NULL ,

	tnode_id_parent		INT ,
	tnode_depth		INT NOT NULL ,

	task_id_copyof		INT NOT NULL ,
	tnode_id_copyof		INT ,

	taskdep_id		INT
		REFERENCES task_dependencies( taskdep_id )
			ON DELETE CASCADE ,

	PRIMARY KEY( task_id , tnode_reverse , tnode_id )
);

CREATE INDEX i_tnode_reversetasks ON taskdep_nodes ( tnode_reverse , tnode_id_parent );
CREATE INDEX i_tnode_copyof ON taskdep_nodes ( task_id_copyof );
CREATE INDEX i_tnode_objdep ON taskdep_nodes ( taskdep_id );

ALTER TABLE taskdep_nodes
	ADD CONSTRAINT fk_tnode_copyof
		FOREIGN KEY( task_id_copyof , tnode_reverse , tnode_id_copyof )
			REFERENCES taskdep_nodes( task_id , tnode_reverse , tnode_id )
				ON DELETE CASCADE ,
	ADD CONSTRAINT fk_tnode_parent
		FOREIGN KEY( task_id , tnode_reverse , tnode_id_parent )
			REFERENCES taskdep_nodes( task_id , tnode_reverse , tnode_id )
				ON DELETE CASCADE;

GRANT SELECT ON taskdep_nodes TO :webapp_user;


--
-- When a task is added, the corresponding dependency tree and
-- reverse dependency tree must be created
--
CREATE OR REPLACE FUNCTION tgf_task_ai( )
		RETURNS TRIGGER
		STRICT VOLATILE
		SECURITY DEFINER
	AS $tgf_task_ai$
BEGIN
	INSERT INTO taskdep_nodes ( task_id , tnode_reverse , tnode_depth , task_id_copyof )
		VALUES ( NEW.task_id , FALSE , 0 , NEW.task_id );
	INSERT INTO taskdep_nodes ( task_id , tnode_reverse , tnode_depth , task_id_copyof )
		VALUES ( NEW.task_id , TRUE , 0 , NEW.task_id );
	RETURN NEW;
END;
$tgf_task_ai$ LANGUAGE plpgsql;

CREATE TRIGGER tg_task_ai
	AFTER INSERT ON tasks
		FOR EACH ROW
		EXECUTE PROCEDURE tgf_task_ai( );

REVOKE EXECUTE ON FUNCTION tgf_task_ai() FROM PUBLIC;


--
-- Copy the contents of a tree <src> as a child of node <node> on tree <dest>.
--
CREATE OR REPLACE FUNCTION tdtree_copy_tree(
			is_reverse BOOLEAN , src_id INT , dest_id INT ,
			node_id INT , depth INT , dep_id INT
		)
		RETURNS VOID
		STRICT VOLATILE
	AS $tdtree_copy_tree$
DECLARE
	node	RECORD;
	objid	INT;
BEGIN
	CREATE TEMPORARY TABLE tdtree_copy_ids(
		old_id	INT ,
		new_id	INT
	);

	FOR node IN
		SELECT * FROM taskdep_nodes nodes
			WHERE task_id = src_id
				AND tnode_reverse = is_reverse
			ORDER BY tnode_depth ASC
	LOOP
		IF node.tnode_id_copyof IS NULL THEN
			node.task_id_copyof := src_id;
			node.tnode_id_copyof := node.tnode_id;
		END IF;
		IF node.tnode_id_parent IS NULL THEN
			node.tnode_id_parent := node_id;
			node.taskdep_id := dep_id;
		ELSE
			SELECT INTO node.tnode_id_parent new_id
				FROM tdtree_copy_ids
				WHERE old_id = node.tnode_id_parent;
		END IF;
		node.tnode_depth := node.tnode_depth + depth;

		INSERT INTO taskdep_nodes ( task_id , tnode_reverse , tnode_id_parent ,
					tnode_depth , task_id_copyof , tnode_id_copyof ,
					taskdep_id )
			VALUES ( dest_id , is_reverse , node.tnode_id_parent , node.tnode_depth ,
					node.task_id_copyof , node.tnode_id_copyof ,
					node.taskdep_id )
			RETURNING tnode_id INTO objid;
		INSERT INTO tdtree_copy_ids VALUES ( node.tnode_id , objid );
	END LOOP;

	DROP TABLE tdtree_copy_ids;
END;
$tdtree_copy_tree$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION tdtree_copy_tree( BOOLEAN , INT , INT , INT , INT , INT ) FROM PUBLIC;


--
-- Add the contents of tree <src> as a child of the root of tree <dest>.
-- Also copy <src> to copies of <dest>.
--
CREATE OR REPLACE FUNCTION tdtree_set_child( is_reverse BOOLEAN , src_id INT , dest_id INT , dep_id INT )
		RETURNS VOID
		STRICT VOLATILE
	AS $tdtree_set_child$
DECLARE
	tree_id	INT;
	node_id INT;
	depth	INT;
BEGIN
	FOR tree_id , node_id , depth IN
		SELECT task_id , tnode_id , tnode_depth + 1
			FROM taskdep_nodes
			WHERE tnode_reverse = is_reverse
				AND task_id_copyof = dest_id
	LOOP
		PERFORM tdtree_copy_tree( is_reverse , src_id , tree_id , node_id , depth , dep_id );
	END LOOP;
END;
$tdtree_set_child$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION tdtree_set_child( BOOLEAN , INT , INT , INT ) FROM PUBLIC;


--
-- When a dependency between tasks is added, the corresponding trees must
-- be updated.
--
CREATE OR REPLACE FUNCTION tgf_taskdep_ai( )
		RETURNS TRIGGER
		STRICT VOLATILE
		SECURITY DEFINER
	AS $tgf_taskdep_ai$
BEGIN
	PERFORM tdtree_set_child( FALSE , NEW.task_id_depends , NEW.task_id , NEW.taskdep_id );
	PERFORM tdtree_set_child( TRUE , NEW.task_id , NEW.task_id_depends , NEW.taskdep_id );
	RETURN NEW;
END;
$tgf_taskdep_ai$ LANGUAGE plpgsql;

CREATE TRIGGER tg_taskdep_ai
	AFTER INSERT ON task_dependencies
		FOR EACH ROW
		EXECUTE PROCEDURE tgf_taskdep_ai( );

REVOKE EXECUTE ON FUNCTION tgf_taskdep_ai() FROM PUBLIC;


--
-- Before inserting a dependency, we need to lock all trees that have something
-- to do with either nodes. Then we need to make sure there are no cycles and
-- that the new dependency is not redundant.
--
CREATE OR REPLACE FUNCTION tgf_taskdep_bi( )
		RETURNS TRIGGER
		STRICT VOLATILE
		SECURITY DEFINER
	AS $tgf_taskdep_bi$
BEGIN
	-- Lock all trees
	PERFORM 1
		FROM taskdep_nodes n1
			INNER JOIN taskdep_nodes n2
				USING ( task_id )
		WHERE n1.task_id_copyof IN ( NEW.task_id , NEW.task_id_depends )
		FOR UPDATE OF n2;

	-- Check for cycles
	PERFORM 1 FROM taskdep_nodes
		WHERE task_id = NEW.task_id
			AND task_id_copyof = NEW.task_id_depends
			AND tnode_reverse;
	IF FOUND THEN
		RAISE EXCEPTION 'Cycle detected'
			USING ERRCODE = 'check_violation';
	END IF;

	-- Check for redundant dependencies
	PERFORM  1
		FROM taskdep_nodes n1
			INNER JOIN task_dependencies d
				ON d.task_id = n1.task_id_copyof
		WHERE n1.task_id = NEW.task_id
			AND n1.tnode_reverse
			AND d.task_id_depends = NEW.task_id_depends;
	IF FOUND THEN
		RAISE EXCEPTION '% is the parent of some child of %' , NEW.task_id_depends , NEW.task_id
			USING ERRCODE = 'check_violation';
	END IF;

	PERFORM 1
		FROM task_dependencies d1
			INNER JOIN taskdep_nodes n
				ON n.task_id = d1.task_id_depends
		WHERE d1.task_id = NEW.task_id
			AND n.tnode_reverse
			AND n.task_id_copyof = NEW.task_id_depends;
	IF FOUND THEN
		RAISE EXCEPTION '% is the child of some ancestor of %' , NEW.task_id , NEW.task_id_depends
			USING ERRCODE = 'check_violation';
	END IF;

	PERFORM 1 FROM taskdep_nodes
		WHERE task_id = NEW.task_id
			AND task_id_copyof = NEW.task_id_depends
			AND NOT tnode_reverse;
	IF FOUND THEN
		RAISE EXCEPTION '% is already an ancestor of %' , NEW.task_id_depends , NEW.task_id
			USING ERRCODE = 'check_violation';
	END IF;

	RETURN NEW;
END;
$tgf_taskdep_bi$ LANGUAGE plpgsql;


CREATE TRIGGER tg_taskdep_bi
	BEFORE INSERT ON task_dependencies
		FOR EACH ROW
		EXECUTE PROCEDURE tgf_taskdep_bi( );

REVOKE EXECUTE ON FUNCTION tgf_taskdep_bi() FROM PUBLIC;



--
-- List all dependencies that can be added to a task.
--
CREATE OR REPLACE FUNCTION tasks_possible_dependencies( o_id INT )
	RETURNS SETOF tasks
	STRICT STABLE
AS $tasks_possible_dependencies$
	SELECT * FROM tasks
		WHERE task_id NOT IN (
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


--
-- Add a dependency
--
CREATE OR REPLACE FUNCTION tasks_add_dependency( t_id INT , t_dependency INT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY INVOKER
	AS $tasks_add_dependency$
BEGIN
	INSERT INTO task_dependencies( task_id , task_id_depends )
		VALUES ( t_id , t_dependency );
	RETURN 0;
EXCEPTION
	WHEN foreign_key_violation THEN
		RETURN 1;
	WHEN check_violation THEN
		RETURN 2;
END;
$tasks_add_dependency$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION tasks_add_dependency( INT , INT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION tasks_add_dependency( INT , INT ) TO :webapp_user;
