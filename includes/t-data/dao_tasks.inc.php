<?php

class DAO_Tasks
	extends DAO
{
	private static $priorities = array(
			'1'	=> 'Lowest' ,
			'2'	=> 'Low' ,
			'3'	=> 'Normal' ,
			'4'	=> 'High' ,
			'5'	=> 'Very high' ,
		);


	public function translatePriority( $value )
	{
		return DAO_Tasks::$priorities[ "$value" ];
	}


	public function getAllTasks( )
	{
		return $this->query(
			'SELECT * FROM tasks_list '
			.	'ORDER BY ( CASE '
			.			'WHEN completed_at IS NULL THEN '
			.				'priority '
			.			'ELSE '
			.				'-1 '
			.		'END ) DESC , badness , added_at DESC' )->execute( );
	}

	public function getAllActiveTasks( )
	{
		return $this->query(
			'SELECT * FROM tasks_list '
			.	'WHERE completed_at IS NULL AND badness = 0 '
			.	'ORDER BY priority DESC , added_at DESC' )->execute( );
	}

	public function getAllBlockedTasks( )
	{
		return $this->query(
			'SELECT * FROM tasks_list '
			.	'WHERE badness <> 0 '
			.	'ORDER BY priority DESC , badness , added_at DESC' )->execute( );
	}


	public function getTasksAt( Data_Item $item )
	{
		return $this->query(
			'SELECT * FROM tasks_list '
			.	'WHERE item = $1 AND parent_task IS NULL '
			.	'ORDER BY ( CASE '
			.			'WHEN completed_at IS NULL THEN '
			.				'priority '
			.			'ELSE '
			.				'-1 '
			.		'END ) DESC , badness , added_at DESC'
		)->execute( $item->id );
	}


	public function getUserTasks( $user )
	{
		return $this->query(
			'SELECT * FROM tasks_list '
			.	'WHERE assigned_to_id = $1 '
			.	'ORDER BY priority DESC , badness , added_at DESC'
		)->execute( $user->user_id );
	}


	public function addTask( $item , $title , $priority , $description )
	{
		$result = $this->query( 'SELECT add_task( $1 , $2 , $3 , $4 , $5 ) AS error' )
			->execute( $item , $title , $description , $priority , $_SESSION[ 'uid' ] );
		return $result[0]->error;
	}


	public function addNestedTask( $parent , $title , $priority , $description )
	{
		$result = $this->query( 'SELECT tasks_add_nested( $1 , $2 , $3 , $4 , $5 ) AS error' )
			->execute( $parent , $title , $description , $priority , $_SESSION[ 'uid' ] );
		return $result[0]->error;
	}


	public function get( $id )
	{
		$result = $this->query( 'SELECT * FROM tasks_single_view WHERE id = $1' )->execute( $id );
		if ( empty( $result ) ) {
			return null;
		}

		$task = $result[ 0 ];
		$task->notes = $this->query(
			'SELECT n.note_id AS id , n.user_id AS uid , u.user_view_name AS author , '
			.		'n.note_added AS added_at , n.note_text AS "text" '
			.	'FROM notes n '
			.		'INNER JOIN users_view u USING (user_id) '
			.	'WHERE n.task_id = $1 '
			.	'ORDER BY n.note_added DESC' )->execute( $id );
		$task->subtasks = $this->query(
			'SELECT * FROM tasks_list '
			.	'WHERE parent_task = $1 '
			.	'ORDER BY ( CASE '
			.			'WHEN completed_at IS NULL THEN '
			.				'priority '
			.			'ELSE '
			.				'-1 '
			.		'END ) DESC , badness , added_at DESC'
		)->execute( $id );
		$task->moveDownTargets = $this->query(
			'SELECT * FROM tasks_move_down_targets '
			.	'WHERE task_id = $1 '
			.	'ORDER BY target_title' )->execute( $id );
		$task->dependencies = $this->query(
			'SELECT t.task_id AS id , t.task_title AS title , t.item_id AS item , '
			.		'i.item_name AS item_name , '
			.		'( ct.completed_task_time IS NOT NULL ) AS completed , '
			.		'tl.badness AS missing_dependencies '
			.	'FROM task_dependencies td '
			.		'INNER JOIN tasks t ON t.task_id = td.task_id_depends '
			.		'INNER JOIN tasks_list tl ON tl.id = t.task_id '
			.		'LEFT OUTER JOIN items i USING ( item_id ) '
			.		'LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id '
			.	'WHERE td.task_id = $1 '
			.	'ORDER BY i.item_name , t.task_priority DESC , t.task_title' )->execute( $id );
		$task->reverseDependencies = $this->query(
			'SELECT t.task_id AS id , t.task_title AS title , t.item_id AS item , '
			.		'i.item_name AS item_name , '
			.		'( ct.completed_task_time IS NOT NULL ) AS completed '
			.	'FROM task_dependencies td '
			.		'INNER JOIN tasks t USING( task_id ) '
			.		'LEFT OUTER JOIN items i USING ( item_id ) '
			.		'LEFT OUTER JOIN completed_tasks ct ON t.task_id = ct.task_id '
			.	'WHERE td.task_id_depends = $1 '
			.	'ORDER BY i.item_name , t.task_priority DESC , t.task_title' )->execute( $id );
		$task->possibleDependencies = $this->query(
			'SELECT t.task_id AS id , t.task_title AS title , t.item_id AS item , '
			.		'i.item_name AS item_name '
			.	'FROM tasks_possible_dependencies( $1 ) t '
			.		'LEFT OUTER JOIN items i USING ( item_id ) '
			.	'ORDER BY i.item_name , t.task_priority , t.task_title' )->execute( $id );
		$task->lineage = null;

		return $task;
	}


	public function getLineage( $task )
	{
		if ( ! in_array( 'lineage' , get_object_vars( $task ) ) || $task->lineage === null ) {
			$result = $this->query(
				'SELECT task_id , task_title '
				.	'FROM tasks_tree tt '
				.		'INNER JOIN tasks '
				.			'ON task_id = tt.task_id_parent '
				.	'WHERE task_id_child = $1 AND tt_depth > 0 '
				.	'ORDER BY tt_depth DESC'
			)->execute( $task->id );

			$task->lineage = array( );
			foreach ( $result as $row ) {
				array_push( $task->lineage , array( $row->task_id , $row->task_title ) );
			}
		}
		return $task->lineage;
	}


	public function canDelete( $task )
	{
		if ( $task->completed_by !== null ) {
			$ts = strtotime( $task->completed_at );
			return ( time() - $ts > 7 * 3600 * 24 );
		}
		$ts = strtotime( $task->added_at );
		return ( time() - $ts < 600 ) && ( $task->uid == $_SESSION[ 'uid' ] );
	}


	public function canFinish( $task )
	{
		assert( $task->completed_at == null );
		return ( $task->badness == 0 );
	}


	public function canRestart( $task )
	{
		assert( $task->completed_at != null );
		foreach ( $task->reverseDependencies as $dependency ) {
			if ( $dependency->completed == 't' ) {
				return false;
			}
		}

		if ( $task->parent_task === null ) {
			return true;
		}
		$parent = ( $task->parent_task instanceof StdClass ) ? $task->parent_task : $this->get( $task->parent_task );
		return $parent->completed_by === null;
	}


	public function delete( $task )
	{
		$this->query( 'DELETE FROM tasks WHERE task_id = $1' )->execute( $task );
	}


	public function finish( $task , $noteText )
	{
		$this->query( 'SELECT finish_task( $1 , $2 , $3 )' )
			->execute( $task , $_SESSION[ 'uid' ] , $noteText );
	}


	public function restart( $task , $noteText )
	{
		$this->query( 'SELECT restart_task( $1 , $2 , $3 )' )
			->execute( $task , $_SESSION[ 'uid' ] , $noteText );
	}

	public function updateTask( $id , $item , $title , $priority , $description , $assignee )
	{
		$result = $this->query( 'SELECT update_task( $1 , $2 , $3 , $4 , $5 , $6 ) AS error' )
			->execute( $id , $item , $title , $description , $priority , $assignee );
		return $result[0]->error;
	}

	public function updateNestedTask( $id , $title , $priority , $description , $assignee )
	{
		$result = $this->query( 'SELECT update_task( $1 , $2 , $3 , $4 , $5 ) AS error' )
			->execute( $id , $title , $description , $priority , $assignee );
		return $result[0]->error;
	}

	public function addNote( $task , $note )
	{
		$this->query( 'INSERT INTO notes ( task_id , user_id , note_text ) VALUES ( $1 , $2 , $3 )' )
			->execute( $task , $_SESSION[ 'uid' ] , $note );
	}

	public function getNote( $id )
	{
		$query = $this->query(
			'SELECT n.note_id AS id , n.note_text AS text , n.note_added AS added_at , '
			.		'n.task_id AS task , '
			.		'( n.user_id = $2 AND t.task_id IS NULL ) AS editable '
			.	'FROM notes n '
			.		'LEFT OUTER JOIN completed_tasks t USING (task_id) '
			.	'WHERE n.note_id = $1' );
		$result = $query->execute( $id , $_SESSION[ 'uid' ] );
		if ( empty( $result ) ) {
			return null;
		}
		$result[ 0 ]->editable = ( $result[ 0 ]->editable == 't' );
		return $result[ 0 ];
	}

	public function deleteNote( $id )
	{
		$this->query( 'DELETE FROM notes WHERE note_id = $1' )->execute( $id );
	}

	public function updateNote( $id , $text )
	{
		$this->query( 'UPDATE notes SET note_text = $2 , note_added = now( ) WHERE note_id = $1' )
			->execute( $id , $text );
	}

	public function addDependency( $id , $dependency )
	{
		$result = $this->query( 'SELECT tasks_add_dependency( $1 , $2 ) AS error' )
			->execute( $id , $dependency );
		return $result[0]->error;
	}

	public function deleteDependency( $from , $to )
	{
		$this->query( 'DELETE FROM task_dependencies WHERE task_id = $1 AND task_id_depends = $2' )
			->execute( $from , $to );
	}

	public function assignTaskTo( $task , $user )
	{
		$this->query(
			'UPDATE tasks _task SET user_id_assigned = $2 '
			.	'FROM tasks _task2 '
			.		'LEFT OUTER JOIN completed_tasks _completed '
			.			'USING ( task_id ) '
			.	'WHERE _task2.task_id = _task.task_id AND _completed.task_id IS NULL AND _task.task_id = $1'
		)->execute( $task , $user );
	}

	public function moveUp( $task , $force = false )
	{
		$result = $this->query( 'SELECT tasks_move_up( $1 , $2 ) AS success')
			->execute( $task->id , $force ? 't' : 'f' );
		return ( $result[0]->success == 't' );
	}

	public function moveDown( $task , $sibling , $force )
	{
		$result = $this->query( 'SELECT tasks_move_down( $1 , $2 , $3 ) AS success')
			->execute( $task->id , $sibling , $force ? 't' : 'f' );
		return ( $result[0]->success == 't' );
	}
}
