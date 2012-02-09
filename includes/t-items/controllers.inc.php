<?php


class Ctrl_ItemsTree
	extends Controller
	implements TitleProvider
{
	private $useParameter;

	public function __construct( $useParameter = false )
	{
		$this->useParameter = $useParameter;
	}

	public function handle( Page $page )
	{
		$items = Loader::DAO( 'items' );
		$tree = $items->getTree( );
		$items->countActiveTasks( );

		if ( $this->useParameter ) {
			$root = (int) $this->getParameter( $this->useParameter , 'GET' );
		} else {
			$root = null;
		}

		$buttonURL = 'items/add';
		if ( $root != null ) {
			$rootObj = $items->get( $root );
			$tree = $items->getAll( $rootObj->children );
			$boxTitle = 'Child items';
			$buttonURL .= "?from=$root";
		} else {
			$boxTitle = null;
		}

		return Loader::View( 'box' , $boxTitle , Loader::View( 'items_tree' , $tree ) )
			->setClass( 'list' )
			->addButton( BoxButton::create( 'Add item' , $buttonURL )
				->setClass( 'list-add' ) );
	}


	public function getTitle( )
	{
		return 'Items';
	}
}


class Ctrl_ItemDetails
	extends Controller
{
	private $item;

	public function __construct( Data_Item $item )
	{
		$this->item = $item;
	}

	public function handle( Page $page )
	{
		$items = Loader::DAO( 'items');
		$items->getTree( );

		$box = Loader::View( 'box' , 'Details' , Loader::View( 'item_details' , $this->item ) )
			->addButton( BoxButton::create( 'Edit item' , 'items/edit?id=' . $this->item->id )
				->setClass( 'icon edit' ) );

		if ( $items->canMove( $this->item ) ) {
			$box->addButton( BoxButton::create( 'Move item' , 'items/move?id=' . $this->item->id )
				->setClass( 'icon move' ) );
		}

		if ( $items->canDelete( $this->item ) ) {
			$box->addButton( BoxButton::create( 'Delete item' , 'items/delete?id=' . $this->item->id )
				->setClass( 'icon delete' ) );
		}

		return $box;
	}

}


class Ctrl_AddItem
	extends Controller
	implements FormAware
{
	private $form;

	public function setForm( Form $form )
	{
		$this->form = $form;
	}

	public function handle( Page $page )
	{
		$name = $this->form->field( 'name' );
		$before = $this->form->field( 'before' );
		$description = $this->form->field( 'description' );
		list( $after , $id ) = explode( ':' , $before->value( ) );

		$items = Loader::DAO( 'items' );
		if ( $id === '' ) {
			$error = $items->createLast( $name->value( ) , $description->value( ) );
		} elseif ( $after == 1 ) {
			$error = $items->createUnder( $name->value( ) , $id , $description->value( ) );
		} else {
			$error = $items->createBefore( $name->value( ) , $id , $description->value( ) );
		}

		switch ( $error ) {

		case 0:
			return true;

		case 1:
			$name->putError( 'This name is not unique' );
			break;

		case 2:
			$before->putError( 'The item you selected no longer exists' );
			break;

		default:
			$name->putError( 'An unknown error occurred (' . $error . ')' );
			break;

		}

		return null;
	}
}


class Ctrl_MoveItem
	extends Controller
	implements FormAware
{
	private $form;


	public function setForm( Form $form )
	{
		$this->form = $form;
	}


	public function handle( Page $page )
	{
		$srcId = (int) $this->form->field( 'id' )->value( );
		$dest = $this->form->field( 'destination' );
		list( $after , $id ) = explode( ':' , $dest->value( ) );

		$items = Loader::DAO( 'items' );
		if ( $id === '' ) {
			$error = $items->moveLast( $srcId );
		} elseif ( $after == 1 ) {
			$error = $items->moveUnder( $srcId , $id );
		} else {
			$error = $items->moveBefore( $srcId , $id );
		}

		switch ( $error ) {

		case 0:
			return true;

		case 1:
			$dest->putError( 'Invalid destination' );
			break;

		case 2:
			$before->putError( 'The place you selected no longer exists.' );
			break;

		default:
			$name->putError( 'An unknown error occurred (' . $error . ')' );
			break;

		}

		return null;
	}

}


class Ctrl_DeleteItem
	extends Controller
	implements FormAware
{
	private $form;


	public function setForm( Form $form )
	{
		$this->form = $form;
	}


	public function handle( Page $page )
	{
		$id = (int) $this->form->field( 'id' )->value( );

		$items = Loader::DAO( 'items' );
		if ( ! $items->canDelete( $items->get( $id ) ) ) {
			return false;
		}
		$items->destroy( $id );
		return true;
	}

}


class Ctrl_EditItem
	extends Controller
	implements FormAware
{
	private $form;


	public function setForm( Form $form )
	{
		$this->form = $form;
	}


	public function handle( Page $page )
	{
		$id = (int) $this->form->field( 'id' )->value( );
		$items = Loader::DAO( 'items' );
		$item = $items->get( $id );

		$name = $this->form->field( 'name' );
		$description = $this->form->field( 'description' )->value( );
		if ( $name->value( ) === $item->name && $description == $item->description ) {
			return true;
		}

		$error = $items->modify( $id , $name->value( ) , $description );
		switch ( $error ) {

		case 0:
			return true;

		case 1:
			$name->putError( 'This name is not unique.' );
			break;

		default:
			$name->putError( 'An unknown error occurred (' . $error . ')' );
			break;

		}

		return null;
	}

}


class Ctrl_ItemTasks
	extends Controller
{
	private $item;

	public function __construct( Data_Item $item )
	{
		$this->item = $item;
	}


	public function handle( Page $page )
	{
		$tasks = Loader::DAO( 'tasks' )->getTasksAt( $this->item );

		return Loader::View( 'box' , 'Tasks' , Loader::View( 'tasks_list' , $tasks , array(
					'deps' , 'assigned' , 'completed' ) ) )
				->addButton( BoxButton::create( 'Add task' , 'tasks/add?to=' . $this->item->id )
					->setClass( 'list-add' ) );
	}
}
