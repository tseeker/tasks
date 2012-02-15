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
			$bTitle = 'Display active tasks';
			$bMode = 'blocked';
		} elseif ( $mode == 'blocked' ) {
			$tasks = Loader::DAO( 'tasks' )->getAllBlockedTasks( );
			$title = 'Blocked tasks';
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
		$box = Loader::View( 'box' , $title , Loader::View( 'tasks_list' , $tasks ) )
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
			$targetIsItem = true;
		} catch ( ParameterException $e ) {
			try {
				$target = (int) $this->getParameter( 'parent' );
				$targetIsItem = false;
			} catch ( ParameterException $e ) {
				$target = null;
				$targetIsItem = null;
			}
		}

		$form = Loader::Create( 'Form' , 'Add this task' , 'create-task' );

		if ( $target === null ) {
			$returnURL = 'tasks';
			if ( ! $this->addItemSelector( $form ) ) {
				return 'items';
			}
			$form->addField( Loader::Create( 'Field' , 'nested' , 'hidden' )
				->setDefaultValue( 0 ) );
		} elseif ( $targetIsItem ) {
			$item = Loader::DAO( 'items' )->get( $target );
			if ( $item === null ) {
				return 'tasks';
			}
			$returnURL = 'items/view?id=' . $target;

			$form->addField( Loader::Create( 'Field' , 'to' , 'hidden' )
					->setDefaultValue( $target ) )
				->addField( Loader::Create( 'Field' , 'nested' , 'hidden' )
					->setDefaultValue( 0 ) )
				->addField( Loader::Create( 'Field' , 'item' , 'hidden' )
					->setDefaultValue( $target ) )
				->addField( Loader::Create( 'Field' , 'item-name' , 'label' )
					->setMandatory( false )
					->setDescription( 'Item:' )
					->setDefaultValue( $item->name ) );
		} else {
			$parent = Loader::DAO( 'tasks' )->get( $target );
			if ( $parent === null ) {
				return 'tasks';
			}
			$returnURL = 'tasks/view?id=' . $target;
			if ( $parent->completed_by !== null ) {
				return $returnURL;
			}

			$form->addField( Loader::Create( 'Field' , 'parent' , 'hidden' )
					->setDefaultValue( $target ) )
				->addField( Loader::Create( 'Field' , 'nested' , 'hidden' )
					->setDefaultValue( 1 ) )
				->addField( Loader::Create( 'Field' , 'item-name' , 'label' )
					->setMandatory( false )
					->setDescription( 'Sub-task of:' )
					->setDefaultValue( $parent->title ) );
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
			Loader::Ctrl( 'task_details' , $task )
		);

		if ( $task->completed_by === null || ! empty( $task->subtasks ) ) {
			$result[] = Loader::Ctrl( 'task_list_subtasks' , $task );
		}
		$result[] = Loader::Ctrl( 'task_dependencies' , $task );
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

		// Create parent URL from either the item or parent task
		if ( $task->parent_task === null ) {
			$parentURL = 'items/view?id=' . $task->item;
		} else {
			$parentURL = 'tasks/view?id=' . $task->parent_task;
		}

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
			->setSuccessURL( $parentURL )
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
		if ( $task->completed_at !== null ) {
			return 'tasks/view?id=' . $id;
		}
		$page->setTitle( $task->title . ' (task)' );


		$form = Loader::Create( 'Form' , 'Update task' , 'edit-task' , 'Editing task' )
			->setURL( 'tasks/view?id=' . $task->id )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $task->id ) )
			->addField( Loader::Create( 'Field' , 'nested' , 'hidden' )
				->setDefaultValue( $task->parent_task === null ? 0 : 1 ) );

		if ( $task->parent_task === null ) {
			$form->addField( $this->createItemSelector( )
				->setDefaultValue( $task->item ) );
		}

		return $form->addField( Loader::Create( 'Field' , 'title' , 'text' )
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
			->addField( $this->createAssigneeSelector( )
				->setDefaultValue( $task->assigned_id ) )
			->addController( Loader::Ctrl( 'edit_task' ) )
			->controller( );
	}


	private function createItemSelector( )
	{
		$select = Loader::Create( 'Field' , 'item' , 'select' )
			->setDescription( 'On item:' );

		$items = Loader::DAO( 'items' )->getTreeList( );
		foreach ( $items as $item ) {
			$name = '-' . str_repeat( '--' , $item->depth ) . ' ' . $item->name;
			$select->addOption( $item->id , $name );
		}
		return $select;

	}


	private function createAssigneeSelector( )
	{
		$select = Loader::Create( 'Field' , 'assigned-to' , 'select' )
			->setDescription( 'Assigned to:' )
			->setMandatory( false );
		$select->addOption( '' , '(unassigned task)' );

		$users = Loader::DAO( 'users' )->getUsers( );
		foreach ( $users as $user ) {
			$select->addOption( $user->user_id , $user->user_view_name );
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


class Ctrl_DependencyAddForm
	extends Controller
{

	public function handle( Page $page )
	{
		// Check selected note
		try {
			$id = (int) $this->getParameter( 'to' );
		} catch ( ParameterException $e ) {
			return 'tasks';
		}

		$tasks = Loader::DAO( 'tasks' );
		$task = $tasks->get( $id );
		if ( $task === null ) {
			return 'tasks';
		}
		if ( $task->completed_at !== null || empty( $task->possibleDependencies ) ) {
			return 'tasks/view?id=' . $id;
		}
		$page->setTitle( $task->title . ' (task)' );

		// Generate form
		$form = Loader::Create( 'Form' , 'Add dependency' , 'add-dep' )
			->addField( Loader::Create( 'Field' , 'to' , 'hidden' )
			->setDefaultValue( $id ) );
		$this->addDependencySelector( $form , $task->possibleDependencies , $task->parent_task === null );
		return $form->setURL( 'tasks/view?id=' . $id )
			->addController( Loader::Ctrl( 'dependency_add' ) )
			->controller( );

	}

	private function addDependencySelector( $form , $possibleDependencies , $topLevel )
	{
		$form->addField( $select = Loader::Create( 'Field' , 'dependency' , 'select' )
			->setDescription( 'Dependency to add:' )
			->addOption( '' , '(please select a task)' ) );

		if ( $topLevel ) {
			$depsByItem = $this->getDependenciesByItem( $possibleDependencies );
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
			foreach ( $possibleDependencies as $task ) {
				$select->addOption( $task->id , $task->title );
			}
		}
		return true;

	}

	private function getDependenciesByItem( $possibleDependencies )
	{
		$dbi = array( );
		foreach ( $possibleDependencies as $pDep ) {
			$dbi[ $pDep->item ][] = $pDep;
		}
		return $dbi;
	}

	private function getItemsToDisplay( $depsByItem )
	{
		$dao = Loader::DAO( 'items' );
		$allItems = $dao->getTreeList( );
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

		$result = array( );
		foreach ( $allItems as $item ) {
			if ( array_key_exists( $item->id , $found ) ) {
				array_push( $result , $item );
			}
		}
		return $result;
	}
}


class Ctrl_DependencyDeleteForm
	extends Controller
{

	public function handle( Page $page )
	{
		$tasks = Loader::DAO( 'tasks' );

		// Get the task a dependency is being removed from
		try {
			$from = (int) $this->getParameter( 'from' );
		} catch ( ParameterException $e ) {
			return 'tasks';
		}
		$task = $tasks->get( $from );
		if ( $task === null ) {
			return 'tasks';
		}
		$page->setTitle( $task->title . ' (task)' );
		if ( $task->completed_at !== null ) {
			return 'tasks/view?id=' . $from;
		}

		// Get the dependency being deleted
		try {
			$to = (int) $this->getParameter( 'to' );
		} catch ( ParameterException $e ) {
			return 'tasks/view?id=' . $from;
		}
		$dependency = $tasks->get( $to );
		if ( $dependency === null || ! $this->checkDependency( $task  , $to ) ) {
			return 'tasks/view?id=' . $from;
		}

		// Generate confirmation text
		$confText = HTML::make( 'div' )
			->appendElement( HTML::make( 'p' )
				->appendText( 'The selected task will no longer depend on ' )
				->appendElement( HTML::make( 'strong' )
					->appendText( $dependency->title ) )
				->appendText( '.' ) );

		// Generate form
		return Loader::Create( 'Form' , 'Delete dependency' , 'delete-dep' )
			->addField( Loader::Create( 'Field' , 'from' , 'hidden' )
				->setDefaultValue( $from ) )
			->addField( Loader::Create( 'Field' , 'to' , 'hidden' )
				->setDefaultValue( $to ) )
			->addField( Loader::Create( 'Field' , 'confirm' , 'html' )->setDefaultValue( $confText ) )
			->setURL( 'tasks/view?id=' . $from )
			->addController( Loader::Ctrl( 'dependency_delete' ) )
			->controller( );

	}


	private function checkDependency( $task , $to )
	{
		foreach ( $task->dependencies as $dep ) {
			if ( $dep->id == $to ) {
				return true;
			}
		}
		return false;
	}
}



class Ctrl_TaskMoveDown
	extends Controller
{

	public function __construct( )
	{
		$this->dao = Loader::DAO( 'tasks' );
	}

	public function handle( Page $page )
	{
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'tasks';
		}

		$task = $this->dao->get( $id );
		if ( $task === null ) {
			return 'tasks';
		}

		if ( empty( $task->moveDownTargets ) ) {
			return 'tasks/view?id=' . $id;
		}

		$page->setTitle( $task->title . ' (task)' );
		$sibling = $this->getSibling( $task );
		if ( $sibling != null ) {
			if ( $this->handleSelectedSibling( $task , $sibling ) ) {
				return 'tasks/view?id=' . $id;
			} else {
				return $this->confirmationForm( $task , $sibling );
			}
		}
		return $this->siblingSelectionForm( $task );
	}

	private function getSibling( $task )
	{
		try {
			$sibling = (int) $this->getParameter( 'sibling' );
			$okSiblings = array_map( function( $item ) { return $item->target_id; } , $task->moveDownTargets );
			if ( ! in_array( $sibling , $okSiblings ) ) {
				$sibling = null;
			}
		} catch ( ParameterException $e ) {
			$sibling = null;
		}
		return $sibling;
	}

	private function handleSelectedSibling( $task , $sibling )
	{
		try {
			$force = (bool) $this->getParameter( 'force' );
		} catch ( ParameterException $e ) {
			$force = false;
		}

		return $this->dao->moveDown( $task , $sibling , $force );
	}

	private function confirmationForm( $task , $sibling )
	{
		$sibling = $this->dao->get( $sibling );
		$confText = HTML::make( 'div' )
			->appendElement( HTML::make( 'p' )
				->appendText( 'All dependencies and reverse dependencies of the '
					. 'selected task will be lost when it is moved into ' )
				->appendElement( HTML::make( 'strong' )->appendText( $sibling->title ) )
				->appendText( '.' ) )
			->appendElement( HTML::make( 'p' )
				->appendText( 'Please confirm.' ) );

		return Loader::Create( 'Form' , 'Move task' , 'move-down' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $task->id ) )
			->addField( Loader::Create( 'Field' , 'sibling' , 'hidden' )
				->setDefaultValue( $sibling->id ) )
			->addField( Loader::Create( 'Field' , 'force' , 'hidden' )
				->setDefaultValue( 1 ) )
			->addField( Loader::Create( 'Field' , 'confirm' , 'html' )->setDefaultValue( $confText ) )
			->setURL( 'tasks/view?id=' . $task->id )
			->controller( );
	}

	private function siblingSelectionForm( $task )
	{
		$selector = Loader::Create( 'Field' , 'sibling' , 'select' )
			->setDescription( 'Move task into: ' );
		foreach ( $task->moveDownTargets as $target ) {
			$selector->addOption( $target->target_id , $target->target_title );
		}
		return Loader::Create( 'Form' , 'Move task' , 'move-down' , 'Move task to sibling' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $task->id ) )
			->addField( $selector )
			->setURL( 'tasks/view?id=' . $task->id )
			->controller( );
	}
}


class Ctrl_TaskMoveUp
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
		$task = $dao->get( $id );
		if ( $task === null ) {
			return 'tasks';
		}
		if ( ! $task->can_move_up ) {
			return 'tasks/view?id=' . $id;
		}

		$page->setTitle( $task->title . ' (task)' );
		try {
			$confirmed = (bool) $this->getParameter( 'confirmed' );
		} catch ( ParameterException $e ) {
			$confirmed = false;
		}
		if ( ! $confirmed ) {
			return $this->showConfirmationForm( $task );
		}

		try {
			$force = (bool) $this->getParameter( 'force' );
		} catch ( ParameterException $e ) {
			$force = false;
		}
		if ( $dao->moveUp( $task , $force ) ) {
			return 'tasks/view?id=' . $id;
		}
		return $this->showForceForm( $task );
	}

	private function showConfirmationForm( $task )
	{
		$confText = HTML::make( 'div' )
			->appendElement( HTML::make( 'p' )
				->appendText( 'You are about to move this sub-task into its '
					. 'grand-parent.' ) )
			->appendElement( HTML::make( 'p' )
				->appendText( 'Please confirm.' ) );

		return Loader::Create( 'Form' , 'Move task' , 'move-up' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $task->id ) )
			->addField( Loader::Create( 'Field' , 'confirmed' , 'hidden' )
				->setDefaultValue( 1 ) )
			->addField( Loader::Create( 'Field' , 'confirm' , 'html' )->setDefaultValue( $confText ) )
			->setURL( 'tasks/view?id=' . $task->id )
			->controller( );
	}

	private function showForceForm( $task )
	{
		$confText = HTML::make( 'div' )
			->appendElement( HTML::make( 'p' )
				->appendText( 'All dependencies and reverse dependencies of the '
					. 'selected task will be lost when it is moved.' ) )
			->appendElement( HTML::make( 'p' )
				->appendText( 'Please confirm.' ) );

		return Loader::Create( 'Form' , 'Move task' , 'move-up' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $task->id ) )
			->addField( Loader::Create( 'Field' , 'confirmed' , 'hidden' )
				->setDefaultValue( 1 ) )
			->addField( Loader::Create( 'Field' , 'force' , 'hidden' )
				->setDefaultValue( 1 ) )
			->addField( Loader::Create( 'Field' , 'confirm' , 'html' )->setDefaultValue( $confText ) )
			->setURL( 'tasks/view?id=' . $task->id )
			->controller( );
	}

}
