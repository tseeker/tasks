<?php

class Ctrl_AllTasks
	extends Controller
{

	public function handle( Page $page )
	{
		try {
			$mode = $this->getParameter( 'mode' , 'GET' );
		} catch ( ParameterException $e ) {
			$mode = 'active';
		}

		if ( $mode == 'active' ) {
			$tasks = Loader::DAO( 'tasks' )->getAllActiveTasks( );
			$title = 'Active tasks';
			$bTitle = 'Display all tasks';
			$bMode = 'all';
		} else {
			$mode = 'all';
			$tasks = Loader::DAO( 'tasks' )->getAllTasks( );
			$title = 'All tasks';
			$bTitle = 'Display active tasks only';
			$bMode = 'active';
		}

		$tree = Loader::DAO( 'items' )->getTree( );
		$box = Loader::View( 'box' , $title , Loader::View( 'all_tasks' , $tasks , $mode ) )
			->addButton( BoxButton::create( $bTitle , 'tasks?mode=' . $bMode )
				->setClass( 'icon refresh' ) );
		if ( !empty( $tree ) ) {
			$box ->addButton( BoxButton::create( 'New task' , 'tasks/add' )
				->setClass( 'list-add' ) );
		}
		return $box;
	}

}


abstract class Ctrl_TaskFormBase
	extends Controller
{

	protected final function createPrioritySelector( )
	{
		$select = Loader::Create( 'Field' , 'priority' , 'select' )
			->setDescription( 'Priority:' )
			->setValidator( Loader::Create( 'Validator_IntValue' , 'Priorité invalide' )
				->setMinValue( 1 )->setMaxValue( 5 ) );
		$tasks = Loader::DAO( 'tasks' );

		for ( $i = 5 ; $i >= 1 ; $i -- ) {
			$select->addOption( $i , $tasks->translatePriority( $i ) );
		}

		return $select;
	}

}



class Ctrl_AddTaskForm
	extends Ctrl_TaskFormBase
{

	public function handle( Page $page )
	{
		try {
			$target = (int) $this->getParameter( 'to' );
		} catch ( ParameterException $e ) {
			$target = null;
		}

		$form = Loader::Create( 'Form' , 'Add this task' , 'create-task' );

		if ( $target === null ) {
			$returnURL = 'tasks';
			if ( ! $this->addItemSelector( $form ) ) {
				return 'items';
			}
		} else {
			$item = Loader::DAO( 'items' )->get( $target );
			if ( $item === null ) {
				return 'items';
			}
			$returnURL = 'items/view?id=' . $target;

			$form->addField( Loader::Create( 'Field' , 'to' , 'hidden' )
					->setDefaultValue( $target ) )
				->addField( Loader::Create( 'Field' , 'item' , 'hidden' )
					->setDefaultValue( $target ) );
		}

		$page->setTitle( 'New task' );

		return $form->addField( Loader::Create( 'Field' , 'title' , 'text' )
				->setDescription( 'Task title:' )
				->setModifier( Loader::Create( 'Modifier_TrimString' ) )
				->setValidator( Loader::Create( 'Validator_StringLength' , 'This title' , 5 , 256 ) ) )
			->addField( $this->createPrioritySelector( )
				->setDefaultValue( 3 ) )
			->addField( Loader::Create( 'Field' , 'description' , 'textarea' )
				->setDescription( 'Description:' )
				->setMandatory( false ) )
			->setURL( $returnURL )
			->addController( Loader::Ctrl( 'add_task' ) )
			->controller( );
	}


	private function addItemSelector( $form )
	{
		$form->addField( $select = Loader::Create( 'Field' , 'item' , 'select' )
			->setDescription( 'Item:' )
			->addOption( '' , '(please select an item)' ) );

		$items =  Loader::DAO( 'items' )->getTreeList( );
		if ( empty( $items ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			$name = '-' . str_repeat( '--' , $item->depth ) . ' ' . $item->name;
			$select->addOption( $item->id , $name );
		}
		return true;

	}

}


class Ctrl_ViewTask
	extends Controller
{

	public function handle( Page $page )
	{
		try {
			$id = (int) $this->getParameter( 'id' , 'GET' );
		} catch ( ParameterException $e ) {
			return 'tasks';
		}

		$task = Loader::DAO( 'tasks' )->get( $id );
		if ( $task === null ) {
			return 'tasks';
		}
		$page->setTitle( $task->title . ' (task)' );

		$result = array(
			Loader::Ctrl( 'task_details' , $task ) ,
			Loader::Ctrl( 'task_dependencies' , $task ) ,
		);

		if ( $task->completed_by === null ) {
			array_push( $result , Loader::Ctrl( 'add_task_note_form' , $task ) );
		}

		array_push( $result , Loader::Ctrl( 'task_notes' , $task ) );
		return $result;
	}

}


class Ctrl_DeleteTaskForm
	extends Controller
{

	public function handle( Page $page )
	{
		// Check selected task
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'tasks';
		}

		$tasks = Loader::DAO( 'tasks' );
		$task = $tasks->get( $id );
		if ( $task === null ) {
			return 'tasks';
		}
		if ( ! $tasks->canDelete( $task ) ) {
			return 'tasks/view?id=' . $id;
		}
		$page->setTitle( $task->title . ' (task)' );

		// Generate confirmation text
		$confText = HTML::make( 'div' )
			->appendElement( HTML::make( 'p' )
				->appendText( "You are about to delete this task, and any comment attached to it." ) )
			->appendElement( HTML::make( 'p' )
				->appendText( "This operation cannot be undone." ) );

		// Generate form
		return Loader::Create( 'Form' , 'Delete the task' , 'delete-task' , 'Task deletion' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $task->id ) )
			->addField( Loader::Create( 'Field' , 'confirm' , 'html' )->setDefaultValue( $confText ) )
			->setCancelURL( 'tasks/view?id=' . $task->id )
			->setSuccessURL( 'items/view?id=' . $task->item )
			->addController( Loader::Ctrl( 'delete_task' ) )
			->controller( );

	}

}



class Ctrl_EditTaskForm
	extends Ctrl_TaskFormBase
{

	public function handle( Page $page )
	{
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'tasks';
		}

		$task = Loader::DAO( 'tasks' )->get( $id );
		if ( $task === null ) {
			return 'tasks';
		}
		$page->setTitle( $task->title . ' (task)' );


		return Loader::Create( 'Form' , 'Update task' , 'edit-task' , 'Editing task' )
			->setURL( 'tasks/view?id=' . $task->id )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $task->id ) )
			->addField( $this->createItemSelector( )
				->setDefaultValue( $task->item ) )
			->addField( Loader::Create( 'Field' , 'title' , 'text' )
				->setDescription( 'Title:' )
				->setModifier( Loader::Create( 'Modifier_TrimString' ) )
				->setValidator( Loader::Create( 'Validator_StringLength' , 'This title' , 5 , 256 ) )
				->setDefaultValue( $task->title ) )
			->addField( $this->createPrioritySelector( )
				->setDefaultValue( $task->priority ) )
			->addField( Loader::Create( 'Field' , 'description' , 'textarea' )
				->setDescription( 'Description:' )
				->setMandatory( false )
				->setDefaultValue( $task->description ) )
			->addController( Loader::Ctrl( 'edit_task' ) )
			->controller( );
	}


	private function createItemSelector( )
	{
		$select = Loader::Create( 'Field' , 'item' , 'select' )
			->setDescription( 'On item:' );

		$items =  Loader::DAO( 'items' )->getTreeList( );
		foreach ( $items as $item ) {
			$name = '-' . str_repeat( '--' , $item->depth ) . ' ' . $item->name;
			$select->addOption( $item->id , $name );
		}
		return $select;

	}
}


class Ctrl_DeleteNoteForm
	extends Controller
{

	public function handle( Page $page )
	{
		// Check selected note
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'tasks';
		}

		$tasks = Loader::DAO( 'tasks' );
		$note = $tasks->getNote( $id );
		if ( $note === null ) {
			return 'tasks';
		}
		if ( !$note->editable ) {
			return 'tasks/view?id=' . $note->task;
		}
		$task = $tasks->get( $note->task );
		$page->setTitle( $task->title . ' (task)' );

		// Generate confirmation text
		$confText = HTML::make( 'div' )
			->appendElement( HTML::make( 'p' )
				->appendText( 'You are about to delete a comment attached to this task.' ) )
			->appendElement( HTML::make( 'p' )
				->appendText( 'This operation cannot be undone.' ) );

		// Generate form
		return Loader::Create( 'Form' , 'Delete this comment' , 'delete-note' , 'Comment deletion' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $note->id ) )
			->addField( Loader::Create( 'Field' , 'confirm' , 'html' )->setDefaultValue( $confText ) )
			->setURL( 'tasks/view?id=' . $note->task )
			->addController( Loader::Ctrl( 'delete_note' ) )
			->controller( );

	}

}


class Ctrl_EditNoteForm
	extends Controller
{

	public function handle( Page $page )
	{
		// Check selected note
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'tasks';
		}

		$tasks = Loader::DAO( 'tasks' );
		$note = $tasks->getNote( $id );
		if ( $note === null ) {
			return 'tasks';
		}
		if ( !$note->editable ) {
			return 'tasks/view?id=' . $note->task;
		}
		$task = $tasks->get( $note->task );
		$page->setTitle( $task->title . ' (task)' );

		// Generate form
		return Loader::Create( 'Form' , 'Update comment' , 'edit-note' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $note->id ) )
			->addField( Loader::Create( 'Field' , 'text' , 'textarea' )
				->setDescription( 'Comment:' )
				->setValidator( Loader::Create( 'Validator_StringLength' , 'Le texte' , 5 ) )
				->setDefaultValue( $note->text ) )
			->setURL( 'tasks/view?id=' . $note->task )
			->addController( Loader::Ctrl( 'edit_note' ) )
			->controller( );

	}

}
