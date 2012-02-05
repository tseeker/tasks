--
-- Insert tree data for new rows
--

CREATE OR REPLACE FUNCTION items_tree_ai( )
		RETURNS TRIGGER
		SECURITY DEFINER
	AS $items_tree_ai$
BEGIN
	INSERT INTO items_tree( item_id_parent , item_id_child , pt_depth )
		VALUES ( NEW.item_id , NEW.item_id , 0 );
	INSERT INTO items_tree( item_id_parent , item_id_child , pt_depth )
		SELECT x.item_id_parent, NEW.item_id, x.pt_depth + 1
			FROM items_tree x WHERE x.item_id_child = NEW.item_id_parent;
	RETURN NEW;
END;
$items_tree_ai$ LANGUAGE 'plpgsql';

CREATE TRIGGER items_tree_ai
	AFTER INSERT ON items FOR EACH ROW
		EXECUTE PROCEDURE items_tree_ai( );


--
-- Make sure the changes are OK before updating
--

CREATE OR REPLACE FUNCTION items_tree_bu( )
		RETURNS TRIGGER
		SECURITY DEFINER
	AS $items_tree_bu$
BEGIN
	IF NEW.item_id <> OLD.item_id THEN
		RAISE EXCEPTION 'Changes to identifiers are forbidden.';
	END IF;

	IF NOT OLD.item_id_parent IS DISTINCT FROM NEW.item_id_parent THEN
		RETURN NEW;
	END IF;

	PERFORM 1 FROM items_tree
		WHERE ( item_id_parent , item_id_child ) = ( NEW.item_id , NEW.item_id_parent );
	IF FOUND THEN
		RAISE EXCEPTION 'Update blocked, it would create a loop.';
	END IF;

	RETURN NEW;
END;
$items_tree_bu$ LANGUAGE 'plpgsql';

CREATE TRIGGER items_tree_bu
	BEFORE UPDATE ON items FOR EACH ROW
		EXECUTE PROCEDURE items_tree_bu( );


--
-- Update tree data when a row's parent is changed
--

CREATE OR REPLACE FUNCTION items_tree_au( )
		RETURNS TRIGGER
		SECURITY DEFINER
	AS $items_tree_au$
BEGIN
	IF NOT OLD.item_id_parent IS DISTINCT FROM NEW.item_id_parent THEN
		RETURN NEW;
	END IF;

	-- Remove existing lineage for the updated object and its children
	IF OLD.item_id_parent IS NOT NULL THEN
		DELETE FROM items_tree AS te2
			USING items_tree te1
			WHERE te2.item_id_child = te1.item_id_child
				AND te1.item_id_parent = NEW.item_id
				AND te2.pt_depth > te1.pt_depth;
	END IF;

	-- Create new lineage
	IF NEW.item_id_parent IS NOT NULL THEN
		INSERT INTO items_tree ( item_id_parent , item_id_child , pt_depth )
			SELECT te1.item_id_parent , te2.item_id_child , te1.pt_depth + te2.pt_depth + 1
				FROM items_tree te1 , items_tree te2
				WHERE te1.item_id_child = NEW.item_id_parent
					AND te2.item_id_parent = NEW.item_id;
	END IF;

	RETURN NEW;
END;
$items_tree_au$ LANGUAGE 'plpgsql';

CREATE TRIGGER items_tree_au
	AFTER UPDATE ON items FOR EACH ROW
		EXECUTE PROCEDURE items_tree_au( );
