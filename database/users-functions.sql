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
