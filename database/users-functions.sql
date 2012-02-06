--
-- Create a new user
--

CREATE OR REPLACE FUNCTION users_add( _email TEXT , _salt TEXT , _iters INT , _hash TEXT , _name TEXT )
	RETURNS INT
	LANGUAGE PLPGSQL
	STRICT VOLATILE SECURITY INVOKER
AS $users_add$
BEGIN
	IF _name = '' THEN
		_name := NULL;
	END IF;

	INSERT INTO users ( user_email , user_salt , user_iterations , user_hash , user_display_name )
		VALUES ( _email , _salt , _iters , _hash , _name );
	RETURN 0;
EXCEPTION
	WHEN unique_violation THEN
		RETURN 1;
END;
$users_add$;

REVOKE EXECUTE ON FUNCTION users_add( TEXT , TEXT , INT , TEXT , TEXT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION users_add( TEXT , TEXT , INT , TEXT , TEXT) TO :webapp_user;


--
-- Update an user's address and display name
--

CREATE OR REPLACE FUNCTION users_edit( _id INT , _email TEXT , _name TEXT )
	RETURNS INT
	LANGUAGE PLPGSQL
	STRICT VOLATILE SECURITY INVOKER
AS $users_edit$
BEGIN
	IF _name = '' THEN
		_name := NULL;
	END IF;

	UPDATE users SET user_email = _email , user_display_name = _name
		WHERE user_id = _id;
	RETURN ( CASE WHEN FOUND THEN 0 ELSE 2 END );
EXCEPTION
	WHEN unique_violation THEN
		RETURN 1;
END;
$users_edit$;

REVOKE EXECUTE ON FUNCTION users_edit( INT , TEXT , TEXT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION users_edit( INT , TEXT , TEXT ) TO :webapp_user;


--
-- View that lists users and adds the string to use when displaying
--

DROP VIEW IF EXISTS users_view;
CREATE VIEW users_view
	AS SELECT * , ( CASE
			WHEN user_display_name IS NULL THEN
				user_email
			ELSE
				user_display_name
			END ) AS user_view_name
		FROM users;

GRANT SELECT ON users_view TO :webapp_user;
