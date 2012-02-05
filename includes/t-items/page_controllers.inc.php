<?php


class Ctrl_ViewItem
	extends Controller
{
	public function handle( Page $page )
	{
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return $page->getBaseURL() . '/items';
		}

		$item = Loader::DAO( 'items' )->get( $id );
		if ( $item === null ) {
			return $page->getBaseURL() . '/items';
		}
		$page->setTitle( $item->name . ' (item)' );

		return array(
			Loader::Ctrl( 'item_details' , $item ) ,
			Loader::Ctrl( 'items_tree' , 'id' ) ,
			Loader::Ctrl( 'item_tasks' , $item )
		);
	}
}


class Ctrl_AddItemForm
	extends Controller
	implements TitleProvider
{
	private $items;

	public function handle( Page $page )
	{
		$this->items = Loader::DAO( 'items' );

		$locationField = Loader::Create( 'Item_LocationField' , array( ) );

		$form = Loader::Create( 'Form' , 'Add this item' , 'create-item' , 'New item information' )
			->addField( Loader::Create( 'Field' , 'name' , 'text' )
				->setDescription( 'Item name:' )
				->setModifier( Loader::Create( 'Modifier_TrimString' ) )
				->setValidator( Loader::Create( 'Validator_StringLength' , 'This name' , 2 , 128 ) ) )
			->addField( $before = Loader::Create( 'Field' , 'before' , 'select' )
				->setDescription( 'Insert before:' )
				->setMandatory( false )
				->setModifier( $locationField )
				->setValidator( $locationField ) )
			->addField( Loader::Create( 'Field' , 'description' , 'textarea' )
				->setDescription( 'Description:' )
				->setMandatory( false )
				->setModifier( Loader::Create( 'Modifier_TrimString' ) )
				->setValidator( Loader::Create( 'Validator_StringLength' ,
					'This description' , 10 , null , true ) ) )
			->addField( Loader::Create( 'Field' , 'from' , 'hidden' ) )
			->addController( Loader::Ctrl( 'add_item' ) );

		// Add options to the insert location selector
		$this->addTreeOptions( $before , $this->items->getTree( ) );
		$before->addOption( '1:' , 'the end of the list' );

		// Try to guess return page and default insert location
		try {
			$from = (int) $this->getParameter( 'from' );
		} catch ( ParameterException $e ) {
			$from = 0;
		}
		if ( $from == 0 ) {
			$returnURL = 'items';
			$defBefore = '1:';
		} else {
			$returnURL = 'items/view?id=' . $from;
			$defBefore = '1:' . $from;
		}
		$form->setURL( $returnURL );
		$form->field( 'from' )->setDefaultValue( $from );
		$before->setDefaultValue( $defBefore );

		return $form->controller( );
	}

	private function addTreeOptions( $before , $tree )
	{
		foreach ( $tree as $item ) {
			$name = '-' . str_repeat( '--' , $item->getDepth( ) ) . ' ' . $item->getName( );
			$before->addOption( '0:' . $item->getIdentifier( ) , $name );

			if ( !empty( $item->children ) ) {
				$this->addTreeOptions( $before , $this->items->getAll( $item->children ) );
			}

			$name = '-' . str_repeat( '--' , $item->getDepth( ) + 1 ) . ' the end of ' . $item->getName( );
			$before->addOption( '1:' . $item->getIdentifier( ) , $name );
		}
	}

	public function getTitle( )
	{
		return 'Add item';
	}
}


class Ctrl_MoveItemForm
	extends Controller
{
	private $items;

	public function handle( Page $page )
	{
		// Check selected item
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'items';
		}

		$this->items = Loader::DAO( 'items' );
		$item = $this->items->get( $id );
		if ( $item === null ) {
			return 'items';
		}

		$destinations = $this->items->getMoveTargets( $item );
		if ( empty( $destinations ) ) {
			return 'items/view?id=' . $item->id;
		}
		$page->setTitle( $item->name . ' (item)' );

		// Field modifier / validator
		$locationField = Loader::Create( 'Item_LocationField' , $destinations );

		// Generate form
		$form = Loader::Create( 'Form' , 'Move item' , 'move-item' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )->setDefaultValue( $item->id ) )
			->addField( $dest = Loader::Create( 'Field' , 'destination' , 'select' )
				->setDescription( 'Move before:' )
				->setModifier( $locationField )
				->setValidator( $locationField ) )
			->setURL( 'items/view?id=' . $item->id )
			->addController( Loader::Ctrl( 'move_item' ) );
		$this->addDestinations( $dest , $this->items->getTree( ) , $destinations );
		if ( in_array( '1:' , $destinations ) ) {
			$dest->addOption( '1:' , 'the end of the list' );
		}

		return $form->controller( );
	}


	private function addDestinations( $field , $tree , $destinations )
	{
		foreach ( $tree as $item ) {
			$id = '0:' . $item->id;
			$disabled = ! in_array( $id , $destinations );
			if ( $disabled && ! $this->checkChildren( $item , $destinations ) ) {
				continue;
			}

			$name = '-' . str_repeat( '--' , $item->getDepth( ) ) . ' ' . $item->getName( );
			$field->addOption( $id , $name , $disabled );

			if ( !empty( $item->children ) ) {
				$this->addDestinations( $field , $this->items->getAll( $item->children ) , $destinations );
			}

			$id = '1:' . $item->id;
			if ( ! in_array( $id , $destinations ) ) {
				continue;
			}
			$name = '-' . str_repeat( '--' , $item->getDepth( ) + 1 ) . ' the end of ' . $item->getName( );
			$field->addOption( $id , $name );
		}
	}


	private function checkChildren( $item , $destinations )
	{
		$children = $this->items->getAll( $item->children );
		foreach ( $children as $child ) {
			if ( in_array( '0:' . $child->id , $destinations ) ) {
				return true;
			}
		}

		if ( in_array( '1:' . $item->id , $destinations ) ) {
			return true;
		}

		foreach ( $children as $child ) {
			if ( $this->checkChildren( $child , $destinations ) ) {
				return true;
			}
		}
		return false;
	}
}


class Ctrl_DeleteItemForm
	extends Controller
{
	private $items;

	public function handle( Page $page )
	{
		// Check selected item
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'items';
		}

		$items = Loader::DAO( 'items' );
		$item = $items->get( $id );
		if ( $item === null ) {
			return 'items';
		}
		if ( ! $items->canDelete( $item ) ) {
			return 'items/view?id=' . $id;
		}
		$page->setTitle( $item->name . ' (item)' );

		// Generate confirmation text
		$confText = HTML::make( 'div' )
			->appendElement( HTML::make( 'p' )
				->appendText( 'You are about to delete this item.' ) )
			->appendElement( HTML::make( 'p' )
				->appendText( 'All child items and all tasks the item contains will be deleted permanently.' ) )
			->appendElement( HTML::make( 'p' )
				->appendText( 'It is impossible to undo this operation.' ) );

		// Generate form
		$form = Loader::Create( 'Form' , 'Delete the item' , 'delete-item' , 'Please confirm' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $item->id ) )
			->addField( Loader::Create( 'Field' , 'confirm' , 'html' )->setDefaultValue( $confText ) )
			->setCancelURL( 'items/view?id=' . $item->id )
			->setSuccessURL( 'items' ) // XXX: use lineage
			->addController( Loader::Ctrl( 'delete_item' ) );

		return $form->controller( );
	}
}


class Ctrl_EditItemForm
	extends Controller
{
	private $items;

	public function handle( Page $page )
	{
		// Check selected item
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'items';
		}

		$this->items = Loader::DAO( 'items' );
		$item = $this->items->get( $id );
		if ( $item === null ) {
			return 'items';
		}
		$page->setTitle( $item->name . ' (item)' );

		return Loader::Create( 'Form' , 'Update item' , 'edit-item' )
			->setURL( 'items/view?id=' . $item->id )
			->addField( Loader::Create( 'Field' , 'name' , 'text' )
				->setDescription( 'Name of the item:' )
				->setModifier( Loader::Create( 'Modifier_TrimString' ) )
				->setValidator( Loader::Create( 'Validator_StringLength' , 'This name' , 2 , 128 ) )
				->setDefaultValue( $item->name ) )
			->addField( Loader::Create( 'Field' , 'description' , 'textarea' )
				->setDescription( 'Description:' )
				->setMandatory( false )
				->setModifier( Loader::Create( 'Modifier_TrimString' ) )
				->setValidator( Loader::Create( 'Validator_StringLength' ,
					'This description' , 10 , null , true ) )
				->setDefaultValue( $item->description ) )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $item->id ) )
			->addController( Loader::Ctrl( 'edit_item' ) )
			->controller( );
	}

}
