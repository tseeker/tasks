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
			'SELECT t.task_id AS id, t.item_id AS item, t.task_title AS title, '
			.		't.task_description AS description, t.task_added AS added_at, '
			.		'u1.user_email AS added_by, ct.completed_task_time AS completed_at, '
			.		'u2.user_email AS completed_by , t.task_priority AS priority '
			.	'FROM tasks t '
			.		'INNER JOIN users u1 ON u1.user_id = t.user_id '
			.		'LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id '
			.		'LEFT OUTER JOIN users u2 ON u2.user_id = ct.user_id '
			.	'ORDER BY ( CASE WHEN ct.task_id IS NULL THEN t.task_priority ELSE -1 END ) DESC , '
			.		't.task_added DESC' )->execute( );
	}

	public function getAllActiveTasks( )
	{
		return $this->query(
			'SELECT t.task_id AS id, t.item_id AS item, t.task_title AS title, '
			.		't.task_description AS description, t.task_added AS added_at, '
			.		'u1.user_email AS added_by, NULL AS completed_at, NULL AS completed_by , '
			.		't.task_priority AS priority '
			.	'FROM tasks t '
			.		'INNER JOIN users u1 ON u1.user_id = t.user_id '
			.		'LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id '
			.	'WHERE ct.task_id IS NULL '
			.	'ORDER BY t.task_priority DESC , t.task_added DESC' )->execute( );
	}


	public function getTasksAt( Data_Item $item )
	{
		return $this->query(
			'SELECT t.task_id AS id, t.task_title AS title, '
			.		't.task_description AS description, t.task_added AS added_at, '
			.		'u1.user_email AS added_by, ct.completed_task_time AS completed_at, '
			.		'u2.user_email AS completed_by , t.task_priority AS priority '
			.	'FROM tasks t '
			.		'INNER JOIN users u1 ON u1.user_id = t.user_id '
			.		'LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id '
			.		'LEFT OUTER JOIN users u2 ON u2.user_id = ct.user_id '
			.	'WHERE t.item_id = $1'
			.	'ORDER BY ( CASE WHEN ct.task_id IS NULL THEN t.task_priority ELSE -1 END ) DESC , '
			.		't.task_added DESC' )->execute( $item->id );
	}


	public function addTask( $item , $title , $priority , $description )
	{
		$result = $this->query( 'SELECT add_task( $1 , $2 , $3 , $4 , $5 ) AS error' )
			->execute( $item , $title , $description , $priority , $_SESSION[ 'uid' ] );
		return $result[0]->error;
	}


	public function get( $id )
	{
		$result = $this->query(
			'SELECT t.task_id AS id, t.task_title AS title, t.item_id AS item ,'
			.		't.task_description AS description, t.task_added AS added_at, '
			.		'u1.user_email AS added_by, ct.completed_task_time AS completed_at, '
			.		'u2.user_email AS completed_by, t.user_id AS uid , '
			.		't.task_priority AS priority '
			.	'FROM tasks t '
			.		'INNER JOIN users u1 ON u1.user_id = t.user_id '
			.		'LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id '
			.		'LEFT OUTER JOIN users u2 ON u2.user_id = ct.user_id '
			.	'WHERE t.task_id = $1' )->execute( $id );
		if ( empty( $result ) ) {
			return null;
		}

		$task = $result[ 0 ];
		$task->notes = $this->query(
			'SELECT n.note_id AS id , n.user_id AS uid , u.user_email AS author , '
			.		'n.note_added AS added_at , n.note_text AS "text" '
			.	'FROM notes n '
			.		'INNER JOIN users u USING (user_id) '
			.	'WHERE n.task_id = $1 '
			.	'ORDER BY n.note_added DESC' )->execute( $id );
		$task->dependencies = $this->query(
			'SELECT t.task_id AS id , t.task_title AS title , t.item_id AS item , '
			.		'i.item_name AS item_name , '
			.		'( ct.completed_task_time IS NOT NULL ) AS completed '
			.	'FROM task_dependencies td '
			.		'INNER JOIN tasks t ON t.task_id = td.task_id_depends '
			.		'INNER JOIN items i USING ( item_id ) '
			.		'LEFT OUTER JOIN completed_tasks ct ON ct.task_id = t.task_id '
			.	'WHERE td.task_id = $1 '
			.	'ORDER BY i.item_name , t.task_priority , t.task_title' )->execute( $id );
		$task->reverseDependencies = $this->query(
			'SELECT t.task_id AS id , t.task_title AS title , t.item_id AS item , '
			.		'i.item_name AS item_name , '
			.		'( ct.completed_task_time IS NOT NULL ) AS completed '
			.	'FROM task_dependencies td '
			.		'INNER JOIN tasks t USING( task_id ) '
			.		'INNER JOIN items i USING ( item_id ) '
			.		'LEFT OUTER JOIN completed_tasks ct USING ( task_id ) '
			.	'WHERE td.task_id_depends = $1 '
			.	'ORDER BY i.item_name , t.task_priority , t.task_title' )->execute( $id );

		return $task;
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
		foreach ( $task->dependencies as $dependency ) {
			if ( $dependency->completed != 't' ) {
				return false;
			}
		}
		return true;
	}


	public function canRestart( $task )
	{
		assert( $task->completed_at != null );
		foreach ( $task->reverseDependencies as $dependency ) {
			if ( $dependency->completed == 't' ) {
				return false;
			}
		}
		return true;
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

	public function updateTask( $id , $item , $title , $priority , $description )
	{
		$result = $this->query( 'SELECT update_task( $1 , $2 , $3 , $4 , $5 ) AS error' )
			->execute( $id , $item , $title , $description , $priority );
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

}
