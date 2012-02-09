/*
 * Triggers to handle task containers
 */


DROP FUNCTION IF EXISTS tgf_item_tc_ai( ) CASCADE;
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


DROP FUNCTION IF EXISTS tgf_task_tc_ai( ) CASCADE;
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
