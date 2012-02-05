<?php

abstract class View_TasksBase
	extends BaseURLAwareView
{
	protected $tasks;
	protected $dao;


	protected function __construct( )
	{
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

	protected abstract function generateItem( $task );
}


class View_AllTasks
	extends View_TasksBase
{

	public function __construct( $tasks )
	{
		parent::__construct( );
		$this->tasks = $tasks;
	}

	protected function generateItem( $task )
	{
		$cell = array( );
		array_push( $cell , HTML::make( 'dt' )
			->appendElement( HTML::make( 'a' )
				->setAttribute( 'href' , $this->base . '/tasks/view?id=' . $task->id )
				->appendText( $task->title ) ) );

		array_push( $cell , HTML::make( 'dd' )->append( $this->formatPlaceLineage( $task->item ) ) );

		$addedAt = strtotime( $task->added_at );
		$addedAtDate = date( 'd/m/o' , $addedAt );
		$addedAtTime = date( 'H:i:s' , $addedAt );
		array_push( $cell ,
			HTML::make( 'dd' )->appendText( "Added $addedAtDate at $addedAtTime by {$task->added_by}" ) );
		if ( $task->missing_dependencies !== null ) {
			if ( $task->missing_dependencies > 1 ) {
				$end = 'ies';
			} else {
				$end = 'y';
			}
			array_push( $cell ,
				HTML::make( 'dd' )->appendText( "{$task->missing_dependencies} missing dependenc$end" ) );

			foreach ( $cell as $entry ) {
				$entry->setAttribute( 'class' , 'missing-deps' );
			}
		} elseif ( $task->completed_by !== null ) {
			$completedAt = strtotime( $task->completed_at );
			$completedAtDate = date( 'd/m/o' , $completedAt );
			$completedAtTime = date( 'H:i:s' , $completedAt );
			array_push( $cell , HTML::make( 'dd' )->appendText(
				"Completed $completedAtDate at $completedAtTime by {$task->completed_by}" ) );

			foreach ( $cell as $entry ) {
				$entry->setAttribute( 'class' , 'completed' );
			}
		}

		return $cell;
	}

	private function formatPlaceLineage( $item )
	{
		$item = Loader::DAO( 'items' )->get( $item );
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
		array_unshift( $contents, 'On ' );

		return $contents;
	}
}


class View_Tasks
	extends View_TasksBase
{
	public function __construct( $tasks )
	{
		parent::__construct( );
		$this->tasks = $tasks;
	}


	protected function generateItem( $task )
	{
		$cell = array( );
		array_push( $cell , HTML::make( 'dt' )
			->appendElement( HTML::make( 'a' )
				->setAttribute( 'href' , $this->base . '/tasks/view?id=' . $task->id )
				->appendText( $task->title ) ) );

		$addedAt = strtotime( $task->added_at );
		$addedAtDate = date( 'd/m/o' , $addedAt );
		$addedAtTime = date( 'H:i:s' , $addedAt );
		array_push( $cell ,
			HTML::make( 'dd' )->appendText( "Added $addedAtDate at $addedAtTime by {$task->added_by}" ) );

		if ( $task->missing_dependencies !== null ) {
			if ( $task->missing_dependencies > 1 ) {
				$end = 'ies';
			} else {
				$end = 'y';
			}
			array_push( $cell ,
				HTML::make( 'dd' )->appendText( "{$task->missing_dependencies} missing dependenc$end" ) );

			foreach ( $cell as $entry ) {
				$entry->setAttribute( 'class' , 'missing-deps' );
			}
		} elseif ( $task->completed_by !== null ) {
			$completedAt = strtotime( $task->completed_at );
			$completedAtDate = date( 'd/m/o' , $completedAt );
			$completedAtTime = date( 'H:i:s' , $completedAt );
			array_push( $cell , HTML::make( 'dd' )->appendText(
				"Completed $completedAtDate at $completedAtTime by {$task->completed_by}" ) );
			foreach ( $cell as $entry ) {
				$entry->setAttribute( 'class' , 'completed' );
			}
		}

		return $cell;
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
		$prevItem = null;
		$itemList = null;
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
				}
			}

			$itemList->appendElement( $entry );
		}
		return $list;
	}
}
