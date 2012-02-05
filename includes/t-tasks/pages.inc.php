<?php

class Page_TasksTasks
	extends AuthenticatedPage
{

	public function __construct()
	{
		parent::__construct( array(
			''		=> 'all_tasks' ,
			'add'		=> 'add_task_form' ,
			'delete'	=> 'delete_task_form' ,
			'edit'		=> 'edit_task_form' ,
			'finish'	=> array( 'toggle_task' , false ) ,
			'restart'	=> array( 'toggle_task' , true ) ,
			'view'		=> 'view_task' ,
			'notes/edit'	=> 'edit_note_form' ,
			'notes/delete'	=> 'delete_note_form' ,
		));
	}

}
