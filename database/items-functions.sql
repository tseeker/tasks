--
-- Re-order items
--	* first create a temporary table containing text paths
--	* use that temporary table to re-order the main table
--
CREATE OR REPLACE FUNCTION reorder_items( )
		RETURNS VOID
		STRICT VOLATILE
		SECURITY INVOKER
	AS $$
DECLARE
	i_id INT;
	i_parent INT;
	i_ordering INT;
	i_path TEXT;
BEGIN
	-- Create and fill temporary table
	CREATE TEMPORARY TABLE items_ordering (
		item_id		INT NOT NULL PRIMARY KEY ,
		item_ordering_path	TEXT NOT NULL
	) ON COMMIT DROP;

	FOR i_id , i_parent , i_ordering IN
		SELECT p.item_id , p.item_id_parent , p.item_ordering
			FROM items p
				INNER JOIN items_tree pt
					ON pt.item_id_child = p.item_id
			GROUP BY p.item_id , p.item_id_parent , p.item_ordering
			ORDER BY MAX( pt.pt_depth )
	LOOP
		IF i_parent IS NULL THEN
			i_path := '';
		ELSE
			SELECT INTO i_path item_ordering_path || '/' FROM items_ordering WHERE item_id = i_parent;
		END IF;
		i_path := i_path || to_char( i_ordering , '000000000000' );
		INSERT INTO items_ordering VALUES ( i_id , i_path );
	END LOOP;

	-- Move all rows out of the way
	UPDATE items SET item_ordering = item_ordering + (
			SELECT 1 + 2 * max( item_ordering ) FROM items );

	-- Re-order items
	UPDATE items p1 SET item_ordering = 2 * p2.rn
		FROM ( SELECT item_id , row_number() OVER( ORDER BY item_ordering_path ) AS rn FROM items_ordering ) p2
		WHERE p1.item_id = p2.item_id;
END;
$$ LANGUAGE plpgsql;


-- Insert a item before another
CREATE OR REPLACE FUNCTION insert_item_before( i_name TEXT , i_before INT , i_description TEXT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY DEFINER
	AS $insert_item_before$
DECLARE
	i_ordering	INT;
	i_parent	INT;
BEGIN
	PERFORM 1 FROM items FOR UPDATE;

	SELECT INTO i_ordering , i_parent item_ordering - 1 , item_id_parent
		FROM items
		WHERE item_id = i_before;
	IF NOT FOUND THEN
		RETURN 2;
	END IF;

	IF i_description = '' THEN
		i_description := NULL;
	END IF;

	BEGIN
		INSERT INTO items ( item_name , item_id_parent , item_ordering , item_description )
			VALUES ( i_name , i_parent , i_ordering , i_description );
	EXCEPTION
		WHEN unique_violation THEN
			RETURN 1;
	END;
	PERFORM reorder_items( );
	RETURN 0;
END;
$insert_item_before$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION insert_item_before( TEXT , INT , TEXT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION insert_item_before( TEXT , INT , TEXT ) TO :webapp_user;


-- Insert item as the last child of another
CREATE OR REPLACE FUNCTION insert_item_under( i_name TEXT , i_parent INT , i_description TEXT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY DEFINER
	AS $insert_item_under$
DECLARE
	i_ordering	INT;
BEGIN
	PERFORM 1 FROM items FOR UPDATE;

	IF i_description = '' THEN
		i_description := NULL;
	END IF;

	SELECT INTO i_ordering max( item_ordering ) + 1 FROM items;
	BEGIN
		INSERT INTO items ( item_name , item_id_parent , item_ordering , item_description )
			VALUES ( i_name , i_parent , i_ordering , i_description );
	EXCEPTION
		WHEN unique_violation THEN
			RETURN 1;
		WHEN foreign_key_violation THEN
			RETURN 2;
	END;
	PERFORM reorder_items( );
	RETURN 0;
END;
$insert_item_under$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION insert_item_under( TEXT , INT , TEXT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION insert_item_under( TEXT , INT , TEXT ) TO :webapp_user;


-- Add a item as the last root element
CREATE OR REPLACE FUNCTION insert_item_last( i_name TEXT , i_description TEXT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY DEFINER
	AS $insert_item_last$
DECLARE
	i_ordering	INT;
BEGIN
	PERFORM 1 FROM items FOR UPDATE;

	SELECT INTO i_ordering max( item_ordering ) + 1 FROM items;
	IF i_ordering IS NULL THEN
		i_ordering := 0;
	END IF;

	IF i_description = '' THEN
		i_description := NULL;
	END IF;

	BEGIN
		INSERT INTO items ( item_name , item_ordering , item_description )
			VALUES ( i_name , i_ordering , i_description );
	EXCEPTION
		WHEN unique_violation THEN
			RETURN 1;
	END;
	PERFORM reorder_items( );
	RETURN 0;
END;
$insert_item_last$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION insert_item_last( TEXT , TEXT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION insert_item_last( TEXT , TEXT ) TO :webapp_user;

-- Rename a item
CREATE OR REPLACE FUNCTION update_item( i_id INT , i_name TEXT , i_description TEXT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY DEFINER
	AS $update_item$
BEGIN
	IF i_description = '' THEN
		i_description := NULL;
	END IF;

	UPDATE items
		SET item_name = i_name ,
			item_description = i_description
		WHERE item_id = i_id;

	RETURN 0;
EXCEPTION
	WHEN unique_violation THEN
		RETURN 1;
END
$update_item$ LANGUAGE plpgsql;

REVOKE EXECUTE ON FUNCTION update_item( INT , TEXT , TEXT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION update_item( INT , TEXT , TEXT ) TO :webapp_user;



-- Move a item before another
CREATE OR REPLACE FUNCTION move_item_before( i_id INT , i_before INT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY DEFINER
	AS $move_item_before$
DECLARE
	i_ordering	INT;
	i_parent	INT;
BEGIN
	PERFORM 1 FROM items FOR UPDATE;

	IF i_before = i_id THEN
		RETURN 1;
	ELSE
		SELECT INTO i_ordering , i_parent item_ordering - 1 , item_id_parent
			FROM items
			WHERE item_id = i_before;
		IF NOT FOUND THEN
			RETURN 2;
		END IF;
	END IF;

	BEGIN
		UPDATE items SET item_ordering = i_ordering , item_id_parent = i_parent
			WHERE item_id = i_id;
	EXCEPTION
		WHEN unique_violation THEN
			RETURN 1;
	END;

	PERFORM reorder_items( );
	RETURN 0;
END;
$move_item_before$ LANGUAGE plpgsql;


-- Move a item at the end of another's children
CREATE OR REPLACE FUNCTION move_item_under( i_id INT , i_parent INT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY DEFINER
	AS $move_item_under$
DECLARE
	i_ordering	INT;
BEGIN
	PERFORM 1 FROM items FOR UPDATE;

	IF i_parent = i_id THEN
		RETURN 1;
	ELSE
		SELECT INTO i_ordering MAX( item_ordering ) + 1
			FROM items
			WHERE item_id_parent = i_parent;
		IF i_ordering IS NULL THEN
			i_ordering := 1;
		END IF;
	END IF;

	BEGIN
		UPDATE items SET item_ordering = i_ordering , item_id_parent = i_parent
			WHERE item_id = i_id;
	EXCEPTION
		WHEN unique_violation THEN
			RETURN 1;
		WHEN foreign_key_violation THEN
			RETURN 2;
	END;

	PERFORM reorder_items( );
	RETURN 0;
END;
$move_item_under$ LANGUAGE plpgsql;


-- Move a item to the end of the tree
CREATE OR REPLACE FUNCTION move_item_last( i_id INT )
		RETURNS INT
		STRICT VOLATILE
		SECURITY DEFINER
	AS $move_item_last$
DECLARE
	i_ordering	INT;
BEGIN
	PERFORM 1 FROM items FOR UPDATE;

	SELECT INTO i_ordering MAX( item_ordering ) + 1
		FROM items;
	IF i_ordering IS NULL THEN
		i_ordering := 0;
	END IF;

	BEGIN
		UPDATE items SET item_ordering = i_ordering , item_id_parent = NULL
			WHERE item_id = i_id;
	EXCEPTION
		WHEN unique_violation THEN
			RETURN 1;
	END;

	PERFORM reorder_items( );
	RETURN 0;
END;
$move_item_last$ LANGUAGE plpgsql;



-- Delete a item, moving all children to the item's parent
CREATE OR REPLACE FUNCTION delete_item( i_id INT )
		RETURNS VOID
		STRICT VOLATILE
		SECURITY DEFINER
	AS $delete_item$
DECLARE
	i_parent	INT;
BEGIN
	PERFORM 1 FROM items FOR UPDATE;
	DELETE FROM items WHERE item_id = i_id;
	PERFORM reorder_items( );
END;
$delete_item$ LANGUAGE plpgsql;
