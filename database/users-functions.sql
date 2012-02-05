--
-- Create a new user
--

CREATE OR REPLACE FUNCTION users_add( _email TEXT , _salt TEXT , _iters INT , _hash TEXT )
	RETURNS INT
	LANGUAGE PLPGSQL
	STRICT VOLATILE SECURITY INVOKER
AS $users_add$
BEGIN
	INSERT INTO users ( user_email , user_salt , user_iterations , user_hash )
		VALUES ( _email , _salt , _iters , _hash );
	RETURN 0;
EXCEPTION
	WHEN unique_violation THEN
		RETURN 1;
END;
$users_add$;

REVOKE EXECUTE ON FUNCTION users_add( TEXT , TEXT , INT , TEXT ) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION users_add( TEXT , TEXT , INT , TEXT) TO :webapp_user;
