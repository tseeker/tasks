<?php


class Item_LocationField
	implements FieldValidator , FieldModifier
{

	private $okLocations;

	public function __construct( $okLocations )
	{
		$this->okLocations = $okLocations;
	}

	public function replace( $value )
	{
		$exploded = explode( ':' , $value );
		if ( count( $exploded ) != 2 ) {
			$exploded = array( 0 , $value );
		}

		if ( $exploded[ 1 ] == '' ) {
			$exploded[ 0 ] = 1;
		} else {
			$exploded[ 0 ] = ( $exploded[ 0 ] == '0' ) ? 0 : 1;
			$exploded[ 1 ] = (int) $exploded[ 1 ];
		}

		return join( ':' , $exploded );
	}

	public function validate( $value )
	{
		list( $inside , $before ) = explode( ':' , $value );
		if ( $before != '' && Loader::DAO( 'items' )->get( $before ) == null ) {
			return array( 'This item no longer exists.' );
		}
		if ( ! ( empty( $this->okLocations ) || in_array( $value , $this->okLocations ) ) ) {
			return array( 'Invalid destination' );
		}
		return null;
	}
}

