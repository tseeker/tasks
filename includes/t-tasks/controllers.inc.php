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
		$nested = $this->form->field( 'nested' )->value( );
		if ( 0 === (int) $nested ) {
			return $this->addTopLevelTask( );
		}
		return $this->addNestedTask( );
	}

	private function addTopLevelTask( )
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

	private function addNestedTask( )
	{
		$parent = $this->form->field( 'parent' );
		$name = $this->form->field( 'title' );
		$priority = $this->form->field( 'priority' );
		$description = $this->form->field( 'description' );

		$error = Loader::DAO( 'tasks' )->addNestedTask( (int) $parent->value( ) ,
			$name->value( ) , (int) $priority->value( ) , $description->value( ) );
		switch ( $error ) {

		case 0:
			return true;

		case 1:
			$name->putError( 'Duplicate sub-task name.' );
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
		$tasks = Loader::DAO( 'tasks' );

		$items->getLineage( $this->task->item = $items->get( $this->task->item ) );
		if ( $this->task->parent_task !== null ) {
			$this->task->parent_task = $tasks->get( $this->task->parent_task );
		}

		$box = Loader::View( 'box' , $bTitle , Loader::View( 'task_details' , $this->task ) );

		if ( $this->task->completed_by === null ) {
			$box->addButton( BoxButton::create( 'Edit task' , 'tasks/edit?id=' . $this->task->id )
					->setClass( 'icon edit' ) );

			if ( $tasks->canFinish( $this->task ) ) {
				$box->addButton( BoxButton::create( 'Mark as completed' , 'tasks/finish?id=' . $this->task->id )
						->setClass( 'icon stop' ) );
			}

			if ( $this->task->assigned_id !== $_SESSION[ 'uid' ] ) {
				$box->addButton( BoxButton::create( 'Claim task' , 'tasks/claim?id=' . $this->task->id )
						->setClass( 'icon claim' ) );
			}

			if ( $this->task->can_move_up == 't' ) {
				$box->addButton( BoxButton::create( 'Move task to grandparent' ,
					'tasks/move/up?id=' . $this->task->id )->setClass( 'icon move-up' ) );
			}
			if ( ! empty( $this->task->moveDownTargets ) ) {
				$box->addButton( BoxButton::create( 'Move task to sibling' ,
					'tasks/move/down?id=' . $this->task->id )->setClass( 'icon move-down' ) );
			}
		} else {
			if ( $tasks->canRestart( $this->task ) ) {
				$box->addButton( BoxButton::create( 'Re-activate' , 'tasks/restart?id=' . $this->task->id )
					->setClass( 'icon start' ) );
			}
			$timestamp = strtotime( $this->task->completed_at );
		}

		if ( $tasks->canDelete( $this->task ) ) {
			$box->addButton( BoxButton::create( 'Delete' , 'tasks/delete?id=' . $this->task->id )
					->setClass( 'icon delete' ) );
		}

		return $box;
	}
}


class Ctrl_TaskListSubtasks
	extends Controller
{
	private $task;

	public function __construct( $task )
	{
		$this->task = $task;
	}


	public function handle( Page $page )
	{
		$box = Loader::View( 'box' , 'Sub-tasks' ,
			Loader::View( 'tasks_list' , $this->task->subtasks , array( 'deps' , 'assigned' , 'completed' ) ) );

		if ( $this->task->completed_by === null ) {
			if ( !empty( $this->task->subtasks ) ) {
				$box->addButton( BoxButton::create( 'Move sub-tasks' , 'tasks/move?type=s&id=' . $this->task->id )
					->setClass( 'icon move' ) );
			}
			$box->addButton( BoxButton::create( 'Add sub-task' , 'tasks/add?parent=' . $this->task->id )
				->setClass( 'list-add' ) );
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
		$views = array( );

		if ( ! empty( $this->task->dependencies )
				|| ( $this->task->completed_by === null && ! empty( $this->task->possibleDependencies ) ) ) {
			$views[] = ( $depBox = Loader::View( 'box' , 'Dependencies' ,
				Loader::View( 'task_dependencies' , $this->task , false ) ) );

			if ( ! empty( $this->task->possibleDependencies ) ) {
				$depBox->addButton( BoxButton::create( 'Add dependency' , 'tasks/deps/add?to=' . $this->task->id )
						->setClass( 'list-add' ) );
			}
		}

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

		$nested = $this->form->field( 'nested' )->value( );
		if ( 0 == (int) $nested ) {
			$item = $this->form->field( 'item' );
		} else {
			$item = null;
		}

		$name = $this->form->field( 'title' );
		$priority = $this->form->field( 'priority' );
		$description = $this->form->field( 'description' );
		$assignee = $this->form->field( 'assigned-to' );

		if ( $item != null ) {
			return $this->handleTopLevelTask( $id , $item , $name , $priority , $description , $assignee );
		}
		return $this->handleNestedTask(  $id , $name , $priority , $description , $assignee );
	}

	private function handleNestedTask( $id , $name , $priority , $description , $assignee )
	{
		$error = Loader::DAO( 'tasks' )->updateNestedTask( (int) $id->value( ) , $name->value( ) ,
			(int) $priority->value( ) , $description->value( ) ,
			(int) $assignee->value( ) );

		switch ( $error ) {

		case 0:
			return true;

		case 1:
			$name->putError( 'Another sub-task already uses this title.' );
			break;

		case 2:
			$assignee->putError( 'This user has been deleted.' );
			break;

		default:
			$name->putError( "An unknown error occurred ($error)" );
			break;
		}

		return null;
	}

	private function handleTopLevelTask( $id , $item , $name , $priority , $description , $assignee )
	{
		$error = Loader::DAO( 'tasks' )->updateTask( (int) $id->value( ) ,
			(int) $item->value( ) , $name->value( ) ,
			(int) $priority->value( ) , $description->value( ) ,
			(int) $assignee->value( ) );

		switch ( $error ) {

		case 0:
			return true;

		case 1:
			$name->putError( 'A task already uses this title for this item.' );
			break;

		case 2:
			$item->putError( 'This item has been deleted.' );
			break;

		case 3:
			$assignee->putError( 'This user has been deleted.' );
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


class Ctrl_DependencyAdd
	extends Controller
	implements FormAware
{
	private $form;
	private $more;

	public function __construct( $more = false )
	{
		$this->more = $more;
	}

	public function setForm( Form $form )
	{
		$this->form = $form;
	}

	public function handle( Page $page )
	{
		if ( $this->more ) {
			return null;
		}

		$id = (int) $this->form->field( 'to' )->value( );
		$dependency = $this->form->field( 'dependency' );
		$error = Loader::DAO( 'tasks' )->addDependency( $id , $dependency->value( ) );

		switch ( $error ) {

		case 0:
			return $this->checkForMore( );

		case 1:
			$dependency->putError( 'The task you selected has been deleted.' );
			break;

		case 2:
			$dependency->putError( 'This dependency is no longer possible.' );
			break;

		case 3:
			$dependency->putError( 'These tasks are no longer at the same level.' );
			break;

		default:
			$dependency->putError( "An unknown error occurred ($error)" );
			break;
		}

		return null;
	}


	private function checkForMore( )
	{
		$field = $this->form->field( 'moar' );
		if ( $field === null || !$field->value( ) ) {
			return true;
		}
		return Loader::Ctrl( 'dependency_add_form' , true );
	}
}


class Ctrl_DependencyAddFiltering
	extends Controller
	implements FormAware
{
	private static $fields = array( 'text' , 'state' , 'items' , 'item-children' , 'keep' );

	private $filtering;
	private $selector;
	private $task;

	private $dependencies;

	public function __construct( Form $selector , $task )
	{
		$this->selector = $selector;
		$this->task = $task;
	}

	public function setForm( Form $form )
	{
		$this->filtering = $form;
	}

	public function handle( Page $page )
	{
		$this->filterTaskDependencies( );
		$this->addDependencySelector( );
		$this->copyFiltersToSelector( );
		if ( $this->getField( 'keep' ) ) {
			$this->saveToSession( );
		} elseif ( array_key_exists( 'add-dep-filters' , $_SESSION ) ) {
			unset( $_SESSION[ 'add-dep-filters' ] );
		}
		return null;
	}

	private function filterTaskDependencies( )
	{
		$this->dependencies = array( );
		$text = trim( $this->getField( 'text' ) );
		if ( $text == '' ) {
			$text = array( );
		} else {
			$text = array_unique( preg_split( '/\s+/' , $text ) );
		}

		$state = $this->getField( 'state' );
		$sActive = ( $state == '' || strstr( $state , 'a' ) !== false );
		$sBlocked = ( $state == '' || strstr( $state , 'b' ) !== false );
		$sCompleted = ( $state == '' || strstr( $state , 'c' ) !== false );

		foreach ( $this->task->possibleDependencies as $dep ) {
			// Check for text
			$ok = true;
			foreach ( $text as $tCheck ) {
				$ok = stristr( $dep->title , $tCheck );
				if ( !$ok ) {
					break;
				}
			}
			if ( !$ok ) {
				continue;
			}

			// Check state
			$isBlocked = ( $dep->blocked === 't' );
			$isCompleted = ( $dep->completed === 't' );
			if ( $isBlocked && !$sBlocked || $isCompleted && !$sCompleted
					|| !( $isBlocked || $isCompleted ) && !$sActive ) {
				continue;
			}

			$this->dependencies[] = $dep;
		}
	}

	private function addDependencySelector( )
	{
		$this->selector->addField( $select = Loader::Create( 'Field' , 'dependency' , 'select' )
			->setDescription( 'Dependency to add:' )
			->addOption( '' , '(please select a task)' ) );

		if ( $this->task->parent_task === null ) {
			$depsByItem = $this->getDependenciesByItem( );
			$items = $this->getItemsToDisplay( $depsByItem );
			foreach ( $items as $item ) {
				$prefix = '-' . str_repeat( '--' , $item->depth );
				$name = $prefix . ' ' . $item->name;
				$select->addOption( 'I' . $item->id , $name , true );
				if ( ! array_key_exists( $item->id , $depsByItem ) ) {
					continue;
				}

				foreach ( $depsByItem[ $item->id ] as $task ) {
					$select->addOption( $task->id , $prefix . '-> ' . $task->title );
				}
			}
		} else {
			foreach ( $this->dependencies as $task ) {
				$select->addOption( $task->id , $task->title );
			}
		}

		if ( count( $this->task->possibleDependencies ) > 1 ) {
			$this->selector->addField( Loader::Create( 'Field' , 'moar' , 'select' )
				->setDescription( 'Add more dependencies:' )
				->setMandatory( false )
				->addOption( '0' , 'No' )
				->addOption( '1' , 'Yes' ) );
		}
	}

	private function getItemsToDisplay( $depsByItem )
	{
		$dao = Loader::DAO( 'items' );
		$found = array( );
		foreach ( array_keys( $depsByItem ) as $id ) {
			if ( array_key_exists( $id , $found ) ) {
				continue;
			}
			$item = $dao->get( $id );
			foreach ( $dao->getLineage( $item ) as $parent ) {
				$found[ $parent ] = 1;
			}
			$found[ $id ] = 1;
		}

		$fByItem = $this->getField( 'items' );
		$fByItem = ( $fByItem == '' ) ? null : ( (int) $fByItem );
		$fChildren = ( $this->getField( 'item-children' ) === '1' );
		$fOKChildren = false;
		$fDepth = -1;

		$result = array( );
		foreach ( $dao->getTreeList( ) as $item ) {
			if ( $fByItem !== null && $fChildren ){
				if ( $item->id == $fByItem ) {
					$fOKChildren = true;
					$fDepth = $item->depth;
				} else if ( $fOKChildren && $item->depth <= $fDepth ) {
					$fOKChildren = false;
				}
			}

			if ( $fByItem !== null && $item->id != $fByItem && !$fOKChildren ) {
				continue;
			}
			if ( array_key_exists( $item->id , $found ) ) {
				array_push( $result , $item );
			}
		}
		return $result;
	}

	private function getDependenciesByItem( )
	{
		$dbi = array( );
		foreach ( $this->dependencies as $pDep ) {
			$dbi[ $pDep->item ][] = $pDep;
		}
		return $dbi;
	}

	private function copyFiltersToSelector( )
	{
		foreach ( Ctrl_DependencyAddFiltering::$fields as $f ) {
			$v = $this->getField( $f );
			$this->selector->addField(
				Loader::Create( 'Field' , 'filters-' . $f , 'hidden' )
					->setMandatory( false )
					->setDefaultValue( $v ) );
		}
	}

	private function saveToSession( )
	{
		if ( array_key_exists( 'add-dep-filters' , $_SESSION ) ) {
			$values = $_SESSION[ 'add-dep-filters' ];
		} else {
			$values = array( );
		}
		foreach ( Ctrl_DependencyAddFiltering::$fields as $f ) {
			$fld = $this->filtering->field( $f );
			if ( $fld !== null ) {
				$values[ $f ] = $fld->value( );
			}	
		}
		$_SESSION[ 'add-dep-filters' ] = $values;
	}

	public function getFiltersFromSelector( )
	{
		foreach ( Ctrl_DependencyAddFiltering::$fields as $f ) {
			$field = $this->filtering->field( $f );
			if ( $field !== null ) {
				$fv = $this->getParameter( 'filters-' . $f , 'POST' );
				$field->setFormValue( $fv );
			}
		}
	}

	public function getFiltersFromSession( )
	{
		if ( !array_key_exists( 'add-dep-filters' , $_SESSION ) ) {
			return;
		}
		$values = $_SESSION[ 'add-dep-filters' ];
		foreach ( Ctrl_DependencyAddFiltering::$fields as $f ) {
			if ( array_key_exists( $f , $values ) ) {
				$field = $this->filtering->field( $f );
				if ( $field != null ) {
					$field->setFormValue( $values[ $f ] );
				}
			}
		}
	}

	private function getField( $name )
	{
		$fld = $this->filtering->field( $name );
		return $fld ? $fld->value( ) : '';
	}
}


class Ctrl_DependencyDelete
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
		Loader::DAO( 'tasks' )->deleteDependency(
			(int) $this->form->field( 'from' )->value( ) ,
			(int) $this->form->field( 'to' )->value( ) );
		return true;
	}
}


class Ctrl_TaskClaim
	extends Controller
{

	public function handle( Page $page )
	{
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'tasks';
		}

		$dao = Loader::DAO( 'tasks' );
		$dao->assignTaskTo( $id , $_SESSION[ 'uid' ] );
		return 'tasks/view?id=' . $id;
	}

}


class Ctrl_TaskMove
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
		$type = $this->form->field( 'type' );
		$id = $this->form->field( 'id' );
		$target = $this->form->field( 'target' );
		$tasks = $this->form->field( 'tasks[]' );
		try {
			$force = ( (int) $this->getParameter( 'force' , 'POST' ) == 1 );
		} catch ( ParameterException $e ) {
			$force = false;
		}

		$tFull = $target->value( );
		if ( strlen( $tFull ) < 2 ) {
			$target->putError( 'Invalid target.' );
			return null;
		}
		$toTask = ( substr( $tFull , 0 , 1 ) == 'T' );
		$toId = (int) substr( $tFull , 1 );

		$error = Loader::DAO( 'tasks' )->moveTasks(
			$type->value( ) === 's' , (int) $id->value( ) ,
			$toTask , $toId , $tasks->value( ) ,
			$force );

		switch ( $error ) {

		case 0:
			return true;

		case 1:
			$tasks->putError( 'Selected tasks deleted.' );
			break;

		case 2:
			$tasks->putError( 'Selected tasks moved.' );
			break;

		case 3:
			$target->putError( 'Target has been deleted.' );
			break;

		case 4:
			$target->putError( 'This is a child of a selected task.' );
			break;

		case 5:
			$tasks->putError( 'Dependencies would be broken' );
			$this->form->addField( Loader::Create( 'Field' , 'force' , 'select' )
				->setMandatory( false )
				->setDescription( 'Break dependencies:' )
				->addOption( '0' , 'No' )
				->addOption( '1' , 'Yes' ) );
			break;

		default:
			$target->putError( "An unknown error occurred ($error)" );
			break;
		}

		return null;
	}

	private function addTopLevelTask( )
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

	private function addNestedTask( )
	{
		$parent = $this->form->field( 'parent' );
		$name = $this->form->field( 'title' );
		$priority = $this->form->field( 'priority' );
		$description = $this->form->field( 'description' );

		$error = Loader::DAO( 'tasks' )->addNestedTask( (int) $parent->value( ) ,
			$name->value( ) , (int) $priority->value( ) , $description->value( ) );
		switch ( $error ) {

		case 0:
			return true;

		case 1:
			$name->putError( 'Duplicate sub-task name.' );
			break;

		default:
			$name->putError( "An unknown error occurred ($error)" );
			break;
		}

		return null;
	}
}
