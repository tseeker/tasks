<?php

class View_TasksList
	extends BaseURLAwareView
{
	protected $tasks;
	protected $dao;


	public function __construct( $tasks , $features = array( 'item' , 'assigned' , 'deps' , 'completed' ) )
	{
		$this->tasks = $tasks;
		$this->features = array_combine( $features , array_fill( 0 , count( $features ) , 1 ) );
		$this->dao = Loader::DAO( 'tasks' );
	}


	public final function render( )
	{
		if ( empty( $this->tasks ) ) {
			return HTML::make( 'div' )
				->setAttribute( 'class' , 'no-table' )
				->appendText( 'No tasks to display.' );
		}
		return HTML::make( 'dl' )
			->append( $this->generateList( ) )
			->setAttribute( 'class' , 'tasks' );
	}


	private function generateList( )
	{
		$result = array( );
		$prevPriority = 6;
		foreach ( $this->tasks as $task ) {
			$priority = ( $task->completed_by === null ) ? $task->priority : -1;
			if ( $priority !== $prevPriority ) {
				if ( $priority == -1 ) {
					$text = 'Completed tasks';
					$extraClass = ' completed';
				} else {
					$text = $this->dao->translatePriority( $priority ) . ' priority';
					$extraClass = '';
				}
				$prevPriority = $priority;

				array_push( $result , HTML::make( 'dt' )
					->setAttribute( 'class' , 'sub-title' . $extraClass )
					->appendText( $text ) );
			}
			$result = array_merge( $result , $this->generateItem( $task ) );
		}
		return $result;
	}

	protected function generateItem( $task )
	{
		$cell = array( );
		array_push( $cell , HTML::make( 'dt' )
			->appendElement( HTML::make( 'a' )
				->setAttribute( 'href' , $this->base . '/tasks/view?id=' . $task->id )
				->appendText( $task->title ) ) );
		$this->addParent( $cell , $task );
		$classes = array( );

		$addedAt = strtotime( $task->added_at );
		$addedAtDate = date( 'd/m/o' , $addedAt );
		$addedAtTime = date( 'H:i:s' , $addedAt );
		array_push( $cell ,
			HTML::make( 'dd' )->appendText( "Added $addedAtDate at $addedAtTime by {$task->added_by}" ) );

		if ( $task->completed_by !== null ) {
			$this->generateCompletedTask( $cell , $classes , $task );
		} else {
			if ( $task->unsatisfied_direct_dependencies > 0 ) {
				$this->generateMissingDependencies( $cell , $classes , $task );
			}
			if ( $task->incomplete_subtasks > 0 ) {
				$this->generateMissingSubtasks( $cell , $classes , $task );
			}
			if ( $task->unsatisfied_inherited_dependencies > 0 ) {
				$this->generateMissingInherited( $cell , $classes , $task );
			}
			if ( $task->assigned_to !== null ) {
				$this->generateAssignedTask( $cell , $classes , $task );
			}
		}

		if ( ! empty( $classes ) ) {
			foreach ( $cell as $entry ) {
				$entry->setAttribute( 'class' , join( ' ' , array_unique( $classes ) ) );
			}
		}

		return $cell;
	}

	protected function addParent( &$cell , $task )
	{
		if ( ! array_key_exists( 'item' , $this->features ) ) {
			return;
		}

		$this->addItem( $cell , $task );
		if ( $task->parent_task !== null ) {
			$this->addParentTask( $cell , $task );
		}
	}

	protected function addItem( &$cell , $task )
	{
		$itemsDao = Loader::DAO( 'items' );
		$item = $itemsDao->get( $task->item );
		$lineage = $itemsDao->getLineage( $item );
		array_push( $lineage , $item->id );

		$contents = array( );
		foreach ( Loader::DAO( 'items' )->getAll( $lineage ) as $ancestor ) {
			if ( ! empty( $contents ) ) {
				array_push( $contents , ' &raquo; ' );
			}
			array_push( $contents , HTML::make( 'a' )
				->setAttribute( 'href' , $this->base . '/items/view?id=' . $ancestor->id )
				->appendText( $ancestor->name ) );
		}
		array_unshift( $contents, 'On ' );

		array_push( $cell , HTML::make( 'dd' )->append( $contents ) );
	}

	protected function addParentTask( &$cell , $task )
	{
		$parents = $this->dao->getLineage( $task );
		$contents = array( );
		foreach ( $parents as $parent ) {
			list( $id , $title ) = $parent;
			if ( ! empty( $contents ) ) {
				array_push( $contents , ' &raquo; ' );
			}
			array_push( $contents , HTML::make( 'a' )
				->setAttribute( 'href' , $this->base . '/tasks/view?id=' . $id )
				->appendText( $title ) );
		}

		array_push( $cell , HTML::make( 'dd' )
			->appendText( 'Sub-task of ' )
			->append( $contents ) );
	}

	protected function generateMissingDependencies( &$cell , &$classes , $task )
	{
		if ( ! array_key_exists( 'deps' , $this->features ) ) {
			return;
		}

		if ( $task->unsatisfied_direct_dependencies > 1 ) {
			$end = 'ies';
		} else {
			$end = 'y';
		}
		array_push( $cell ,
			$md = HTML::make( 'dd' )->appendText( "{$task->unsatisfied_direct_dependencies} missing dependenc$end" ) );
		if ( $task->unsatisfied_direct_dependencies != $task->unsatisfied_transitive_dependencies ) {
			$md->appendText( " ({$task->unsatisfied_transitive_dependencies} when counting transitive dependencies)" );
		}

		array_push( $classes , 'missing-deps' );
	}

	protected function generateMissingSubtasks( &$cell , &$classes , $task )
	{
		if ( ! array_key_exists( 'deps' , $this->features ) ) {
			return;
		}

		if ( $task->incomplete_subtasks > 1 ) {
			$end = 's';
		} else {
			$end = '';
		}
		array_push( $cell , HTML::make( 'dd' )->appendText(
				"{$task->incomplete_subtasks} incomplete sub-task$end (out of {$task->total_subtasks})" ) );

		array_push( $classes , 'missing-deps' );
	}

	protected function generateMissingInherited( &$cell , &$classes , $task )
	{
		if ( ! array_key_exists( 'deps' , $this->features ) ) {
			return;
		}

		if ( $task->unsatisfied_inherited_dependencies > 1 ) {
			$end = 'ies';
		} else {
			$end = 'y';
		}
		array_push( $cell , HTML::make( 'dd' )->appendText(
				"{$task->unsatisfied_inherited_dependencies} unsatisfied dependenc$end in parent task(s)" ) );

		array_push( $classes , 'missing-deps' );
	}

	protected function generateAssignedTask( &$cell , &$classes , $task )
	{
		if ( ! array_key_exists( 'assigned' , $this->features ) ) {
			return;
		}

		array_push( $cell , HTML::make( 'dd' )->appendText( 'Assigned to ' . $task->assigned_to ) );
		array_push( $classes , 'assigned' );
	}

	protected function generateCompletedTask( &$cell , &$classes , $task )
	{
		if ( ! array_key_exists( 'completed' , $this->features ) ) {
			return;
		}

		$completedAt = strtotime( $task->completed_at );
		$completedAtDate = date( 'd/m/o' , $completedAt );
		$completedAtTime = date( 'H:i:s' , $completedAt );
		array_push( $cell , HTML::make( 'dd' )->appendText(
			"Completed $completedAtDate at $completedAtTime by {$task->completed_by}" ) );
		array_push( $classes , 'completed' );
	}
}


class View_TaskDetails
	extends BaseURLAwareView
{
	private $task;

	public function __construct( $task )
	{
		$this->task = $task;
	}

	public function render( )
	{
		$list = HTML::make( 'dl' )
			->setAttribute( 'class' , 'tasks' )
			->appendElement( HTML::make( 'dt' )
				->appendText( 'On item:' ) )
			->appendElement( HTML::make( 'dd' )
				->append( $this->formatPlaceLineage( $this->task->item ) ) );

		if ( $this->task->parent_task !== null ) {
			$parents = Loader::DAO( 'tasks' )->getLineage( $this->task );
			$contents = array( );
			foreach ( $parents as $parent ) {
				list( $id , $title ) = $parent;
				if ( ! empty( $contents ) ) {
					array_push( $contents , ' &raquo; ' );
				}
				array_push( $contents , HTML::make( 'a' )
					->setAttribute( 'href' , $this->base . '/tasks/view?id=' . $id )
					->appendText( $title ) );
			}
			$list->appendElement( HTML::make( 'dt' )
					->appendText( 'Sub-task of:' ) )
				->appendElement( HTML::make( 'dd' )
					->append( $contents ) );
		}

		if ( $this->task->description != '' ) {
			$list->appendElement( HTML::make( 'dt' )
					->appendText( 'Description:' ) )
				->appendElement( HTML::make( 'dd' )
					->appendRaw( $this->formatDescription( ) ) );
		}

		$list->appendElement( HTML::make( 'dt' )
				->appendText( 'Added:' ) )
			->appendElement( HTML::make( 'dd' )
				->appendText( $this->formatAction( $this->task->added_at , $this->task->added_by ) ) );

		if ( $this->task->completed_by === null ) {
			$list->appendElement( HTML::make( 'dt' )
					->appendText( 'Priority:' ) )
				->appendElement( HTML::make( 'dd' )
					->appendText( Loader::DAO( 'tasks' )
						->translatePriority( $this->task->priority ) ) );

			if ( $this->task->assigned_to === null ) {
				$list->appendElement( HTML::make( 'dt' )
					->setAttribute( 'class' , 'unassigned-task' )
					->appendText( 'Unassigned!' ) );
			} else {
				$list->appendElement( HTML::make( 'dt' )
						->appendText( 'Assigned to:' ) )
					->appendElement( HTML::make( 'dd' )
						->appendText( $this->task->assigned_to ) );
			}
		} else {
			$list->appendElement( HTML::make( 'dt' )
					->appendText( 'Completed:' ) )
				->appendElement( HTML::make( 'dd' )
					->appendText( $this->formatAction(
						$this->task->completed_at , $this->task->completed_by ) ) );
		}

		return $list;
	}

	private function formatPlaceLineage( $item )
	{
		$lineage = $item->lineage;
		array_push( $lineage , $item->id );

		$contents = array( );
		foreach ( Loader::DAO( 'items' )->getAll( $lineage ) as $ancestor ) {
			if ( ! empty( $contents ) ) {
				array_push( $contents , ' &raquo; ' );
			}
			array_push( $contents , HTML::make( 'a' )
				->setAttribute( 'href' , $this->base . '/items/view?id=' . $ancestor->id )
				->appendText( $ancestor->name ) );
		}

		return $contents;
	}


	private function formatDescription( )
	{
		$description = HTML::from( $this->task->description );
		return preg_replace( '/\n/s' , '<br/>' , $description );
	}


	private function formatAction( $timestamp , $user )
	{
		$ts = strtotime( $timestamp );
		$tsDate = date( 'd/m/o' , $ts );
		$tsTime = date( 'H:i:s' , $ts );
		return "$tsDate at $tsTime by $user";
	}
}


class View_TaskNote
	implements View
{
	private $note;

	public function __construct( $note )
	{
		$this->note = $note;
	}

	public function render( )
	{
		$text = HTML::make( 'p' )
			->appendRaw( preg_replace( '/\n/s' , '<br/>' ,  HTML::from( $this->note->text ) ) );

		$ts = strtotime( $this->note->added_at );
		$tsDate = date( 'd/m/o' , $ts );
		$tsTime = date( 'H:i:s' , $ts );
		$details = HTML::make( 'div')
			->setAttribute( 'style' , 'font-size: 9pt' )
			->appendElement( HTML::make( 'em' )
				->appendText( "Note added $tsDate at $tsTime by {$this->note->author}" ) );

		return array( $text , $details );
	}
}


class View_TaskDependencies
	extends BaseURLAwareView
{
	private $task;
	private $reverse;

	public function __construct( $task , $reverse )
	{
		$this->task = $task;
		$this->reverse = $reverse;
	}

	public function render( )
	{
		$source = $this->reverse ? 'reverseDependencies' : 'dependencies';
		if ( empty( $this->task->$source ) ) {
			return HTML::make( 'div' )
				->setAttribute( 'class' , 'no-table' )
				->appendText( 'This task has no dependencies.' );
		}

		$list = HTML::make( 'ul' )->setAttribute( 'class' , 'dep-list' );
		$showItem = ( $this->task->parent_task === null );
		if ( $showItem ) {
			$prevItem = null;
			$itemList = null;
		} else {
			$prevItem = (string) $this->task->item->id;
			$itemList = $list;
		}

		foreach ( $this->task->$source as $dependency ) {
			if ( $prevItem !== $dependency->item ) {
				$itemList = HTML::make( 'ul' );
				$list->appendElement( HTML::make( 'li' )
					->appendText( 'In ' )
					->appendElement( HTML::make( 'a' )
						->setAttribute( 'href' , $this->base . '/items/view?id=' . $dependency->item )
						->appendText( $dependency->item_name ) )
					->appendElement( $itemList ) );
				$prevItem = $dependency->item;
			} elseif ( $itemList === null ) {
				$itemList = $list;
			}

			$entry = HTML::make( 'li' )->appendElement(
				$link = HTML::make( 'a' )
					->setAttribute( 'href' , $this->base . '/tasks/view?id=' . $dependency->id )
					->appendText( $dependency->title ) );
			if ( ! $this->reverse ) {
				$link->setAttribute( 'class' , ( $dependency->completed == 't' )
					? 'satisfied' : 'missing' );

				if ( $this->task->completed_at === null ) {
					$entry->appendText( ' (' )
						->appendElement( HTML::make( 'a' )
							->setAttribute( 'href' , $this->base . '/tasks/deps/delete?from='
								. $this->task->id . '&to=' . $dependency->id )
							->appendText( 'remove') )
						->appendText( ')' );
					if ( $dependency->missing_dependencies != 0 ) {
						$end = $dependency->missing_dependencies > 1 ? 'ies' : 'y';
						$entry->appendElement( HTML::make( 'ul' )
							->appendElement( $mdeps = HTML::make( 'li' ) ) );
						$mdeps->appendText( $dependency->missing_dependencies
								. " missing dependenc$end (transitively)" );
					}
				}
			}

			$itemList->appendElement( $entry );
		}
		return $list;
	}
}
