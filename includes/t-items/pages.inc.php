<?php

class Page_TasksItems
	extends AuthenticatedPage
{

	public function __construct()
	{
		parent::__construct( array(
			''		=> 'items_tree' ,
			'view'		=> 'view_item' ,
			'add'		=> 'add_item_form' ,
			'move'		=> 'move_item_form' ,
			'edit'		=> 'edit_item_form' ,
			'delete'	=> 'delete_item_form' ,
		) );
	}

}
