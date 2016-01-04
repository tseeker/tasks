<?php

class Page_TasksTasks
	extends AuthenticatedPage
{

	public function __construct()
	{
		parent::__construct( array(
			''		=> 'all_tasks' ,
			'add'		=> 'add_task_form' ,
			'claim'		=> 'task_claim' ,
			'delete'	=> 'delete_task_form' ,
			'edit'		=> 'edit_task_form' ,
			'finish'	=> array( 'toggle_task' , false ) ,
			'restart'	=> array( 'toggle_task' , true ) ,
			'view'		=> 'view_task' ,
			'deps/add'	=> 'dependency_add_form' ,
			'deps/delete'	=> 'dependency_delete_form' ,
			'move'		=> 'task_move_form' ,
			'move/down'	=> 'task_move_down' ,
			'move/up'	=> 'task_move_up' ,
			'notes/edit'	=> 'edit_note_form' ,
			'notes/delete'	=> 'delete_note_form' ,
		));
	}

}
