<?php


class Ctrl_AddTask
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
		$item = $this->form->field( 'item' );
		$name = $this->form->field( 'title' );
		$priority = $this->form->field( 'priority' );
		$description = $this->form->field( 'description' );

		$error = Loader::DAO( 'tasks' )->addTask( (int) $item->value( ) , $name->value( ) ,
			(int) $priority->value( ) , $description->value( ) );
		switch ( $error ) {

		case 0:
			return true;

		case 1:
			$name->putError( 'Duplicate task name for this item.' );
			break;

		case 2:
			$item->putError( 'This item has been deleted' );
			break;

		default:
			$name->putError( "An unknown error occurred ($error)" );
			break;
		}

		return null;
	}
}


class Ctrl_TaskDetails
	extends Controller
{
	private $task;

	public function __construct( $task )
	{
		$this->task = $task;
	}


	public function handle( Page $page )
	{
		if ( $this->task->completed_at !== null ) {
			$bTitle = "Completed task";
		} else {
			$bTitle = "Active task";
		}
		$items = Loader::DAO( 'items' );
		$items->getLineage( $this->task->item = $items->get( $this->task->item ) );

		$box = Loader::View( 'box' , $bTitle , Loader::View( 'task_details' , $this->task ) );

		$tasks = Loader::DAO( 'tasks' );
		if ( $this->task->completed_by === null ) {
			$box->addButton( BoxButton::create( 'Edit task' , 'tasks/edit?id=' . $this->task->id )
					->setClass( 'icon edit' ) );
			if ( $tasks->canFinish( $this->task ) ) {
				$box->addButton( BoxButton::create( 'Mark as completed' , 'tasks/finish?id=' . $this->task->id )
						->setClass( 'icon stop' ) );
			};
		} else {
			if ( $tasks->canRestart( $this->task ) ) {
				$box->addButton( BoxButton::create( 'Re-activate' , 'tasks/restart?id=' . $this->task->id )
					->setClass( 'icon start' ) );
			}
			$timestamp = strtotime( $this->task->completed_at );
		}

		if ( Loader::DAO( 'tasks' )->canDelete( $this->task ) ) {
			$box->addButton( BoxButton::create( 'Delete' , 'tasks/delete?id=' . $this->task->id )
					->setClass( 'icon delete' ) );
		}

		return $box;
	}
}


class Ctrl_TaskDependencies
	extends Controller
{
	private $task;

	public function __construct( $task )
	{
		$this->task = $task;
	}


	public function handle( Page $page )
	{
		$views = array(
			Loader::View( 'box' , 'Dependencies' ,
				Loader::View( 'task_dependencies' , $this->task , false ) )
		);

		if ( ! empty( $this->task->reverseDependencies ) ) {
			array_push( $views , Loader::View( 'box' , 'Reverse dependencies' ,
				Loader::View( 'task_dependencies' , $this->task , true ) ) );
		}

		return $views;
	}
}


class Ctrl_TaskNotes
	extends Controller
{
	private $task;

	public function __construct( $task )
	{
		$this->task = $task;
	}


	public function handle( Page $page )
	{
		$result = array( );
		foreach ( $this->task->notes as $note ) {
			$box = Loader::View( 'box' , null , Loader::View( 'task_note' , $note ) );
			if ( $this->task->completed_at === null && $note->uid == $_SESSION[ 'uid' ] ) {
				$box->addButton( BoxButton::create( 'Edit comment' , 'tasks/notes/edit?id=' . $note->id )
						->setClass( 'icon edit' ) )
					->addButton( BoxButton::create( 'Delete comment' , 'tasks/notes/delete?id=' . $note->id )
						->setClass( 'icon delete' ) );
			}
			array_push( $result , $box );
		}
		return $result;
	}
}


class Ctrl_DeleteTask
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
		Loader::DAO( 'tasks' )->delete( (int) $this->form->field( 'id' )->value( ) );
		return true;
	}
}


class Ctrl_ToggleTask
	extends Controller
{
	private $restart;

	public function __construct( $restart )
	{
		$this->isRestart = $restart;
	}

	public function handle( Page $page )
	{
		// Check selected task
		try {
			$id = (int) $this->getParameter( 'id' , 'GET' );
		} catch ( ParameterException $e ) {
			return 'tasks';
		}

		$tasks = Loader::DAO( 'tasks' );
		$task = $tasks->get( $id );
		if ( $task === null ) {
			return 'tasks';
		}

		if ( $this->isRestart && $tasks->canRestart( $task ) ) {
			$tasks->restart( $id , '[AUTO] Task re-activated.' );
		} else if ( ! $this->isRestart && $tasks->canFinish( $task ) ) {
			$tasks->finish( $id , '[AUTO] Task completed.' );
		}

		return 'tasks/view?id=' . $id;
	}
}


class Ctrl_EditTask
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
		$id = $this->form->field( 'id' );
		$item = $this->form->field( 'item' );
		$name = $this->form->field( 'title' );
		$priority = $this->form->field( 'priority' );
		$description = $this->form->field( 'description' );

		$error = Loader::DAO( 'tasks' )->updateTask( (int) $id->value( ) ,
			(int) $item->value( ) , $name->value( ) ,
			(int) $priority->value( ) , $description->value( ) );

		switch ( $error ) {

		case 0:
			return true;

		case 1:
			$name->putError( "A task already uses this title for this item." );
			break;

		case 2:
			$item->putError( "This item has been deleted." );
			break;

		default:
			$name->putError( "An unknown error occurred ($error)" );
			break;
		}

		return null;
	}
}


class Ctrl_AddTaskNoteForm
	extends Controller
{
	private $task;

	public function __construct( $task )
	{
		$this->task = $task;
	}


	public function handle( Page $page )
	{
		return Loader::Create( 'Form' , 'Add' , 'add-note' , 'Add a comment' )
			->setSuccessURL( 'tasks/view?id=' . $this->task->id )
			->setAction( '?id=' . $this->task->id . '#add-note-form' )
			->addField( Loader::Create( 'Field' , 'text' , 'textarea' )
				->setDescription( 'Comment:' )
				->setValidator( Loader::Create( 'Validator_StringLength' , 'This comment' , 5 ) ) )
			->addController( Loader::Ctrl( 'add_task_note' , $this->task ) )
			->controller( );
	}
}


class Ctrl_AddTaskNote
	extends Controller
	implements FormAware
{
	private $task;
	private $form;

	public function __construct( $task )
	{
		$this->task = $task;
	}

	public function setForm( Form $form )
	{
		$this->form = $form;
	}

	public function handle( Page $page )
	{
		Loader::DAO( 'tasks' )->addNote( $this->task->id , $this->form->field( 'text' )->value( ) );
		return true;
	}

}


class Ctrl_DeleteNote
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
		Loader::DAO( 'tasks' )->deleteNote( (int) $this->form->field( 'id' )->value( ) );
		return true;
	}
}


class Ctrl_EditNote
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
		$text = $this->form->field( 'text' )->value( );
		Loader::DAO( 'tasks' )->updateNote( $id , $text );
		return true;
	}
}
