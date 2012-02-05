<?php


class Dao_Users
	extends DAO
{
	private function hashPassword( $password , $salt , $iterations )
	{
		$hash = $password;
		$salt = trim( $salt );
		do {
			$hash = sha1( "$salt$hash$salt" );
			$iterations --;
		} while ( $iterations > 0 );
		return $hash;
	}


	public function getUser( $email )
	{
		$query = $this->query( 'SELECT * FROM users WHERE user_email = LOWER( $1 )' );
		$results = $query->execute( $email );
		if ( empty( $results ) ) {
			return null;
		}
		return array_shift( $results );
	}


	public function checkLogin( $email , $password )
	{
		$userData = $this->getUser( $email );
		if ( $userData != null ) {
			$hashed = $this->hashPassword( $password ,
				$userData->user_salt ,
				$userData->user_iterations );
			if ( $hashed === $userData->user_hash ) {
				return $userData;
			}
		}
		return null;
	}
}
