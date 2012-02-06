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
	task_id							INT NOT NULL DEFAULT NEXTVAL('tasks_task_id_seq'::TEXT),
	item_id						INT NOT NULL REFERENCES items(item_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	task_title						VARCHAR(256) NOT NULL,
	task_priority						INT NOT NULL,
	task_description					TEXT NOT NULL,
	task_added						TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT now(),
	user_id							INT NOT NULL REFERENCES users(user_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	PRIMARY KEY(task_id)
);

CREATE UNIQUE INDEX i_tasks_item_id_task_title ON tasks (item_id,task_title);
GRANT SELECT,INSERT,UPDATE,DELETE ON tasks TO :webapp_user;



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
	task_id							INT NOT NULL REFERENCES tasks(task_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	task_id_depends						INT NOT NULL REFERENCES tasks(task_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	PRIMARY KEY(taskdep_id)
);

CREATE UNIQUE INDEX i_taskdep_unicity ON task_dependencies (task_id,task_id_depends);
CREATE INDEX i_taskdep_bydependency ON task_dependencies (task_id_depends);
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

