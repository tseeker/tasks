-- Sequences
CREATE SEQUENCE items_item_id_seq INCREMENT 1
	MINVALUE 1 MAXVALUE 9223372036854775807
	START 1 CACHE 1;
GRANT SELECT,UPDATE ON items_item_id_seq TO :webapp_user;

CREATE SEQUENCE users_user_id_seq INCREMENT 1
	MINVALUE 1 MAXVALUE 9223372036854775807
	START 1 CACHE 1;
GRANT SELECT,UPDATE ON users_user_id_seq TO :webapp_user;

CREATE SEQUENCE tasks_task_id_seq INCREMENT 1
	MINVALUE 1 MAXVALUE 9223372036854775807
	START 1 CACHE 1;
GRANT SELECT,UPDATE ON tasks_task_id_seq TO :webapp_user;

CREATE SEQUENCE notes_note_id_seq INCREMENT 1
	MINVALUE 1 MAXVALUE 9223372036854775807
	START 1 CACHE 1;
GRANT SELECT,UPDATE ON notes_note_id_seq TO :webapp_user;

CREATE SEQUENCE task_dependencies_taskdep_id_seq INCREMENT 1
	MINVALUE 1 MAXVALUE 9223372036854775807
	START 1 CACHE 1;
GRANT SELECT,UPDATE ON task_dependencies_taskdep_id_seq TO :webapp_user;

-- Tables


/*
 * Task containers
 * ----------------
 *
 * A task container is either an item or a task. Task names within the
 * same task container are unique.
 */
CREATE TABLE task_containers (
	tc_id		SERIAL NOT NULL PRIMARY KEY ,
	task_id		INT UNIQUE ,
	item_id		INT UNIQUE ,
	CHECK( task_id IS NULL AND item_id IS NOT NULL OR task_id IS NOT NULL AND item_id IS NULL )
);

GRANT SELECT ON task_containers TO :webapp_user;


/*
 * Logical task containers
 * ------------------------
 *
 * A logical task container is a group of tasks that can depend on each other.
 * One such container exists for all top-level tasks, and each task defines a
 * container as well.
 */
CREATE TABLE logical_task_containers(
	ltc_id		SERIAL NOT NULL PRIMARY KEY ,
	task_id		INT UNIQUE ,
	CHECK( ltc_id = 1 AND task_id IS NULL OR ltc_id <> 1 AND task_id IS NOT NULL )
);

INSERT INTO logical_task_containers DEFAULT VALUES;

GRANT SELECT ON logical_task_containers TO :webapp_user;



--  Table items
CREATE TABLE items (
	item_id						INT NOT NULL DEFAULT NEXTVAL('items_item_id_seq'::TEXT),
	item_name					VARCHAR(128) NOT NULL,
	item_id_parent					INT,
	item_ordering					INT NOT NULL,
	item_description				TEXT ,
	PRIMARY KEY(item_id)
);

CREATE UNIQUE INDEX i_items_unicity ON items (item_name,item_id_parent);
CREATE UNIQUE INDEX i_items_ordering ON items (item_ordering);

-- Make sure top-level items are unique
CREATE UNIQUE INDEX i_items_top_unicity
	ON items ( item_name )
	WHERE item_id_parent IS NULL;

ALTER TABLE items ADD FOREIGN KEY (item_id_parent)
		REFERENCES items(item_id) ON UPDATE NO ACTION ON DELETE CASCADE;

GRANT SELECT,INSERT,UPDATE,DELETE ON items TO :webapp_user;


-- Add reference from task containers to items
ALTER TABLE task_containers
	ADD FOREIGN KEY ( item_id ) REFERENCES items( item_id )
		ON UPDATE NO ACTION
		ON DELETE CASCADE;



--  Table users
CREATE TABLE users (
	user_id							INT NOT NULL DEFAULT NEXTVAL('users_user_id_seq'::TEXT),
	user_email						VARCHAR(256) NOT NULL,
	user_display_name					VARCHAR(256) ,
	user_salt						CHAR(8) NOT NULL,
	user_iterations						INT NOT NULL,
	user_hash						CHAR(40) NOT NULL,
	PRIMARY KEY(user_id)
);

CREATE UNIQUE INDEX i_users_user_email ON users (LOWER(user_email));
GRANT SELECT,INSERT,UPDATE ON users TO :webapp_user;



--  Table tasks
CREATE TABLE tasks (
	task_id						INT NOT NULL DEFAULT NEXTVAL('tasks_task_id_seq'::TEXT),
	ltc_id						INT NOT NULL REFERENCES logical_task_containers( ltc_id )
								ON UPDATE NO ACTION ON DELETE CASCADE ,
	tc_id						INT NOT NULL REFERENCES task_containers( tc_id )
								ON UPDATE NO ACTION ON DELETE CASCADE ,
	task_title					VARCHAR(256) NOT NULL,
	task_priority					INT NOT NULL,
	task_description				TEXT NOT NULL,
	task_added					TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT now(),
	user_id						INT NOT NULL REFERENCES users(user_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	user_id_assigned				INT REFERENCES users(user_id) ON UPDATE NO ACTION ON DELETE SET NULL ,

	PRIMARY KEY( task_id )
);

CREATE UNIQUE INDEX i_tasks_title ON tasks (tc_id,task_title);
CREATE UNIQUE INDEX i_tasks_ltc ON tasks (task_id , ltc_id);
GRANT SELECT,INSERT,UPDATE,DELETE ON tasks TO :webapp_user;


-- Add reference from task containers to tasks
ALTER TABLE task_containers
	ADD FOREIGN KEY ( task_id ) REFERENCES tasks( task_id )
		ON UPDATE NO ACTION
		ON DELETE CASCADE;

-- Add reference from logical task containers to tasks
ALTER TABLE logical_task_containers
	ADD FOREIGN KEY ( task_id ) REFERENCES tasks( task_id )
		ON UPDATE NO ACTION
		ON DELETE CASCADE;


--  Table items_tree
CREATE TABLE items_tree (
	item_id_parent						INT NOT NULL REFERENCES items(item_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	item_id_child						INT NOT NULL REFERENCES items(item_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	pt_depth						INT NOT NULL,
	PRIMARY KEY(item_id_parent,item_id_child)
);
GRANT SELECT ON items_tree TO :webapp_user;



--  Table completed_tasks
CREATE TABLE completed_tasks (
	task_id							INT NOT NULL REFERENCES tasks(task_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	completed_task_time					TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT now(),
	user_id							INT NOT NULL REFERENCES users(user_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	PRIMARY KEY(task_id)
);

GRANT SELECT,INSERT,UPDATE,DELETE ON completed_tasks TO :webapp_user;



--  Table task_dependencies
CREATE TABLE task_dependencies (
	taskdep_id						INT NOT NULL DEFAULT NEXTVAL('task_dependencies_taskdep_id_seq'::TEXT),
	ltc_id							INT NOT NULL ,
	task_id							INT NOT NULL ,
	task_id_depends						INT NOT NULL ,
	PRIMARY KEY(taskdep_id)
);

CREATE UNIQUE INDEX i_taskdep_unicity ON task_dependencies (task_id,task_id_depends);
CREATE INDEX i_taskdep_bydependency ON task_dependencies (task_id_depends);

ALTER TABLE task_dependencies
	ADD CONSTRAINT fk_taskdep_task
		FOREIGN KEY ( ltc_id , task_id ) REFERENCES tasks( ltc_id , task_id )
			ON UPDATE NO ACTION ON DELETE CASCADE ,
	ADD CONSTRAINT fk_taskdep_dependency
		FOREIGN KEY ( ltc_id , task_id_depends ) REFERENCES tasks( ltc_id , task_id )
			ON UPDATE NO ACTION ON DELETE CASCADE;

GRANT SELECT,INSERT,DELETE ON task_dependencies TO :webapp_user;



--  Table notes
CREATE TABLE notes (
	note_id							INT NOT NULL DEFAULT NEXTVAL('notes_note_id_seq'::TEXT),
	task_id							INT NOT NULL REFERENCES tasks(task_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	user_id							INT NOT NULL REFERENCES users(user_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	note_added						TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT now(),
	note_text						TEXT NOT NULL,
	PRIMARY KEY(note_id)
);

GRANT SELECT,INSERT,UPDATE,DELETE ON notes TO :webapp_user;

