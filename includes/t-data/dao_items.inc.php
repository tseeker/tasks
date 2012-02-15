<?php

class DAO_Items
	extends DAO
{
	private $loaded = array( );
	private $tree = null;
	private $treeList = null;

	private $activeTasksCounted = false;


	public function createBefore( $name , $before , $description = '' )
	{
		$query = $this->query( 'SELECT insert_item_before( $1 , $2 , $3 ) AS error' );
		$result = $query->execute( $name , $before , $description );
		return $result[ 0 ]->error;
	}


	public function createUnder( $name , $under , $description = '' )
	{
		$query = $this->query( 'SELECT insert_item_under( $1 , $2 , $3 ) AS error' );
		$result = $query->execute( $name , $under , $description );
		return $result[ 0 ]->error;
	}


	public function createLast( $name , $description = '' )
	{
		$query = $this->query( 'SELECT insert_item_last( $1 , $2 ) AS error' );
		$result = $query->execute( $name , $description );
		return $result[ 0 ]->error;
	}


	public function get( $identifier )
	{
		$identifier = (int)$identifier;
		if ( array_key_exists( $identifier , $this->loaded ) ) {
			return $this->loaded[ $identifier ];
		}

		$getNameQuery = $this->query( 'SELECT item_name , item_description FROM items WHERE item_id = $1' , true );
		$result = $getNameQuery->execute( $identifier );
		if ( empty( $result ) ) {
			$rObj = null;
		} else {
			$rObj = new Data_Item( $identifier , $result[ 0 ]->item_name );
			$rObj->description = $result[ 0 ]->item_description;
		}
		$this->loaded[ $identifier ] = $rObj;

		return $rObj;
	}


	public function getLineage( Data_Item $item )
	{
		if ( is_array( $item->lineage ) ) {
			return $item->lineage;
		}

		$query = $this->query(
			'SELECT p.item_id , p.item_name , p.item_description FROM items_tree pt '
			.	'INNER JOIN items p '
			.		'ON p.item_id = pt.item_id_parent '
			.	'WHERE pt.item_id_child = $1 AND pt.pt_depth > 0 '
			.	'ORDER BY pt.pt_depth DESC' );
		$result = $query->execute( $item->id );

		$stack = array( );
		foreach ( $result as $entry ) {
			if ( array_key_exists( $entry->item_id , $this->loaded ) ) {
				$object = $this->loaded[ $entry->item_id ];
			} else {
				$object = new Data_Item( $entry->item_id , $entry->item_name );
				$object->description = $entry->item_description;
				$this->loaded[ $entry->item_id ] = $object;
			}
			$object->lineage = $stack;
			array_push( $stack , $entry->item_id );
		}
		$item->lineage = $stack;
		return $item->lineage;
	}


	private function loadTree( )
	{
		$query = $this->query(
			'SELECT p.item_id , p.item_name , p.item_description , MAX( t.pt_depth ) AS depth '
				. 'FROM items p '
					. 'INNER JOIN items_tree t ON t.item_id_child = p.item_id '
				. 'GROUP BY p.item_id, p.item_name , p.item_ordering '
				. 'ORDER BY p.item_ordering' );
		$result = $query->execute( );

		$prevEntry = null;
		$stack = array( );
		$stackSize = 0;
		$this->tree = array( );
		$this->treeList = array( );
		foreach ( $result as $entry ) {
			if ( $entry->depth > $stackSize ) {
				array_push( $stack , $prevEntry );
				$stackSize ++;
			} elseif ( $entry->depth < $stackSize ) {
				while ( $stackSize > $entry->depth ) {
					array_pop( $stack );
					$stackSize --;
				}
			}

			if ( array_key_exists( $entry->item_id , $this->loaded ) ) {
				$object = $this->loaded[ $entry->item_id ];
			} else {
				$object = new Data_Item( $entry->item_id , $entry->item_name );
				$object->description = $entry->item_description;
				$this->loaded[ $entry->item_id ] = $object;
			}
			$object->children = array( );
			$object->lineage = $stack;
			if ( $object->depth = $entry->depth ) {
				$object->hasParent = true;
				$object->parent = $stack[ $stackSize - 1 ];
				array_push( $this->loaded[ $object->parent ]->children , $object->id );
			} else {
				$object->hasParent = false;
			}

			$this->loaded[ $object->id ] = $object;
			if ( $object->depth == 0 ) {
				array_push( $this->tree , $object );
			}
			array_push( $this->treeList , $object );
			$prevEntry = $object->id;
		}
	}


	public function getTree( )
	{
		if ( $this->tree !== null ) {
			return $this->tree;
		}
		$this->loadTree( );
		return $this->tree;
	}


	public function getTreeList( )
	{
		if ( $this->tree !== null ) {
			return $this->tree;
		}
		$this->loadTree( );
		return $this->treeList;
	}


	public function getAll( $input )
	{
		$output = array( );
		foreach ( $input as $id ) {
			array_push( $output , $this->get( $id ) );
		}
		return $output;
	}

	public function countActiveTasks( )
	{
		if ( $this->activeTasksCounted ) {
			return;
		}

		$query = $this->query(
			'SELECT p.item_id , p.item_name , p.item_description , COUNT(*) AS t_count_all , '
			.		'COUNT( NULLIF( t.task_id_parent IS NULL , FALSE ) ) AS t_count '
			.	'FROM items p '
			.		'INNER JOIN tasks t USING( item_id ) '
			.		'LEFT OUTER JOIN completed_tasks c ON t.task_id = c.task_id '
			.	'WHERE c.task_id IS NULL '
			.	'GROUP BY item_id, p.item_name' );
		$results = $query->execute( );

		foreach ( $results as $entry ) {
			if ( array_key_exists( $entry->item_id , $this->loaded ) ) {
				$object = $this->loaded[ $entry->item_id ];
			} else {
				$object = new Data_Item( $entry->item_id , $entry->item_name );
				$object->description = $entry->item_description;
				$this->loaded[ $entry->item_id ] = $object;
			}
			$object->activeTasks = $entry->t_count;
			$object->activeTasksTotal = $entry->t_count_all;
		}

		$this->activeTasksCounted = true;
	}


	private function checkActiveTasksIn( Data_Item $item )
	{
		if ( (int) $item->activeTasks > 0 ) {
			return true;
		}

		foreach ( $this->getAll( $item->children ) as $child ) {
			if ( $this->checkActiveTasksIn( $child ) ) {
				return true;
			}
		}

		return false;
	}


	public function canDelete( Data_Item $item )
	{
		if ( $this->tree === null ) {
			$this->loadTree( );
		}
		$this->countActiveTasks( );
		return ! $this->checkActiveTasksIn( $item );
	}


	public function getMoveTargetsIn( $tree , $parent , $item )
	{
		$positions = array( );
		$count = count( $tree );
		$nameProblem = false;
		for ( $i = 0 ; $i <= $count ; $i ++ ) {
			// Completely skip the selected item and its children
			if ( $i != $count && $tree[ $i ]->id == $item->id ) {
				continue;
			}

			// Check for invalid positions (i.e. before/after selected item)
			$invalidPos = ( $i > 0 && $tree[ $i - 1 ]->id == $item->id );

			// Check for duplicate name
			$nameProblem = $nameProblem || ( $i != $count && $tree[ $i ]->name == $item->name );

			// Get children positions
			if ( $i < $count ) {
				$sub = $this->getMoveTargetsIn( $this->getAll( $tree[ $i ]->children ) , $tree[ $i ] , $item );
			} else {
				$sub = array( );
			}

			array_push( $positions , array(
				'item'	=> ( $i < $count ) ? $tree[ $i ]->id : ( is_null( $parent ) ? null : $parent->id ) ,
				'end'	=> ( $i == $count ) ? 1 : 0 ,
				'valid'	=> ! $invalidPos ,
				'sub'	=> $sub
			) );
		}

		// Add all data to output array
		$realPos = array( );
		foreach ( $positions as $pos ) {
			if ( $pos['valid'] && ! $nameProblem ) {
				array_push( $realPos , $pos['end'] . ':' . $pos[ 'item' ] );
			}
			$realPos = array_merge( $realPos , $pos[ 'sub' ] );
		}

		return $realPos;
	}


	public function getMoveTargets( Data_Item $item )
	{
		//
		// A destination is a (parent,position) couple, where the
		// position corresponds to the item before which the selected
		// item is to be moved.
		//
		// A destination is valid if:
		//	- there is no parent or the parent does not have the
		// selected item in its lineage;
		//	- there is no item in the parent (or at the root if
		// there is no parent) that uses the same name as the selected
		// item, unless that item *is* the selected item;
		//	- the item at the specified position is not the selected
		// item, or there is no item at the specified position;
		//	- the item before the specified position is not the
		// selected item, or the specified position is 0.
		//

		$result = $this->getMoveTargetsIn( $this->getTree( ) , null , $item );
		return $result;
	}

	public function canMove( Data_Item $item )
	{
		$result = $this->getMoveTargets( $item );
		return ! empty( $result );
	}


	public function moveBefore( $item , $before )
	{
		$result = $this->query( 'SELECT move_item_before( $1 , $2 ) AS error' )
			->execute( $item , $before );
		return $result[ 0 ]->error;
	}


	public function moveUnder( $item , $under )
	{
		$result = $this->query( 'SELECT move_item_under( $1 , $2 ) AS error' )
			->execute( $item , $under );
		return $result[ 0 ]->error;
	}


	public function moveLast( $item )
	{
		$result = $this->query( 'SELECT move_item_last( $1 ) AS error' )
			->execute( $item );
		return $result[ 0 ]->error;
	}


	public function destroy( $item )
	{
		$this->query( 'SELECT delete_item( $1 )' )->execute( $item );
	}


	public function modify( $item , $name , $description = '' )
	{
		$result = $this->query( 'SELECT update_item( $1 , $2 , $3 ) AS error' )
			->execute( $item , $name , $description );
		return $result[ 0 ]->error;
	}
}
