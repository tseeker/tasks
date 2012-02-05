<?php

class View_ItemsTree
	extends BaseURLAwareView
{
	private $tree;
	private $minDepth;

	public function __construct( $tree )
	{
		$this->tree = $tree;
		if ( ! empty( $tree ) ) {
			$this->minDepth = $tree[ 0 ]->depth;
		} else {
			$this->minDepth = 0;
		}
	}

	public function render( )
	{
		if ( empty( $this->tree ) ) {
			return HTML::make( 'div' )
				->setAttribute( 'class' , 'no-table' )
				->appendText( 'No items have been defined.' );
		}

		$table = HTML::make( 'table' )
			->appendElement( HTML::make( 'tr' )
				->setAttribute( 'class' , 'header' )
				->appendElement( HTML::make( 'th' )
					->appendText( 'Item name' ) )
				->appendElement( HTML::make( 'th' )
					->setAttribute( 'class' , 'align-right' )
					->appendText( 'Tasks' ) ) );
		foreach ( $this->tree as $item ) {
			$this->renderItem( $table , $item );
		}
		return $table;
	}

	private function renderItem( $table , $item )
	{
		$children = Loader::DAO( 'items' )->getAll( $item->children );
		$padding = 5 + ( $item->depth - $this->minDepth ) * 16;
		$table->appendElement( HTML::make( 'tr' )
			->appendElement( HTML::make( 'td' )
				->setAttribute( 'style' , 'padding-left:' . $padding . 'px' )
				->appendElement( HTML::make( 'a' )
					->setAttribute( 'href' , $this->base . '/items/view?id=' . $item->id )
					->appendText( $item->name ) ) )
			->appendElement( HTML::make( 'td' )
				->setAttribute( 'class' , 'align-right' )
				->appendRaw( (int) $item->activeTasks ) ) );

		foreach ( $children as $child ) {
			$this->renderItem( $table , $child );
		}
	}
}


class View_ItemDetails
	extends BaseURLAwareView
{
	private $item;

	public function __construct( Data_Item $item )
	{
		$this->item = $item;
	}

	public function render( )
	{
		$items = Loader::DAO( 'items' );

		$contents = array( );
		if ( empty( $this->item->lineage ) ) {
			array_push( $contents , HTML::make( 'em' ) ->appendText( 'None' ) );
		} else {
			foreach ( $items->getAll( $this->item->lineage ) as $ancestor ) {
				if ( ! empty( $contents ) ) {
					array_push( $contents , ' &raquo; ' );
				}
				array_push( $contents , HTML::make( 'a' )
					->setAttribute( 'href' , $this->base . '/items/view?id=' . $ancestor->id )
					->appendText( $ancestor->name ) );
			}
		}

		return HTML::make( 'dl' )
			->appendElement( HTML::make( 'dt' )->appendText( 'Path:' ) )
			->appendElement( HTML::make( 'dd' )
				->setAttribute( 'style' , 'font-size: 10pt' )
				->append( $contents ) );
	}
}
