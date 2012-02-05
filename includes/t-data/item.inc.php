<?php

class Data_Item
{
	public $id;
	public $name;
	public $description;
	public $hasParent;
	public $parent;
	public $children;
	public $depth;
	public $lineage;

	public $activeTasks;
	public $inactiveTasks;

	public function __construct( $id , $name )
	{
		$this->id = $id;
		$this->name = $name;
	}


	public function getIdentifier( )
	{
		return $this->id;
	}


	public function getName( )
	{
		return $this->name;
	}


	public function getDepth( )
	{
		if ( $this->depth === null ) {
			throw new Exception( "Method not implemented" );
		}
		return $this->depth;
	}
}
