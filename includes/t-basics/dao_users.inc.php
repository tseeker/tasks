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

	private function hashNewPassword( $password )
	{
		$iterations = rand( 130 , 160 );

		$randSource = array( );
		for ( $i = 0 ; $i < 26 ; $i ++ ) {
			array_push( $randSource , chr( $i + ord( 'a' ) ) );
			array_push( $randSource , chr( $i + ord( 'A' ) ) );
			if ( $i < 10 ) {
				array_push( $randSource , chr( $i + 48 ) );
			}
		}
		shuffle( $randSource );
		$salt = join( '' , array_splice( $randSource , 0 , 4 ) );

		$hash = $this->hashPassword( $password , $salt , $iterations );
		return array( $iterations , $salt , $hash );
	}


	public function getUsers( )
	{
		return $this->query( 
			'SELECT user_id , user_display_name , user_email , user_view_name '
			.	'FROM users_view '
			.	'ORDER BY LOWER( user_email )' )->execute( );
	}


	public function getUserById( $uid )
	{
		$query = $this->query( 'SELECT * FROM users_view WHERE user_id = $1' );
		$results = $query->execute( $uid );
		if ( empty( $results ) ) {
			return null;
		}
		return array_shift( $results );
	}


	public function getUser( $email )
	{
		$query = $this->query( 'SELECT * FROM users_view WHERE user_email = LOWER( $1 )' );
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


	public function addUser( $email , $password , $name )
	{
		list( $iterations , $salt , $hash ) = $this->hashNewPassword( $password );
		$result = $this->query( 'SELECT users_add( $1 , $2 , $3 , $4 , $5 ) AS error' )
			->execute( $email , $salt , $iterations , $hash , $name );
		return $result[ 0 ]->error;
	}


	public function hasUsers( )
	{
		$result = $this->query( 'SELECT COUNT(*) AS n_users FROM users' )->execute( );
		return $result[0]->n_users > 0;
	}


	public function modify( $id , $email , $name )
	{
		$result = $this->query( 'SELECT users_edit( $1 , $2 , $3 ) AS error'
			)->execute( $id , $email , $name );
		return $result[0]->error;
	}


	public function setPassword( $id , $password )
	{
		list( $iterations , $salt , $hash ) = $this->hashNewPassword( $password );
		$this->query(
			'UPDATE users '
			.	'SET user_iterations = $1 , user_salt = $2 , user_hash = $3 '
			.	'WHERE user_id = $4'
		)->execute( $iterations , $salt , $hash , $id );
	}
}
