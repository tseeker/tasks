<?php

abstract class AuthenticatedPage
	extends HubPage
{
	public function __construct( $pages )
	{
		parent::__construct( $pages );
		$this->addController( Loader::Ctrl( 'check_session' ) );
	}

	protected function getMenu( )
	{
		return array(
			'items'		=> 'Items' ,
			'tasks'		=> 'Tasks' ,
			'users'		=> 'Users' ,
			'logout'	=> 'Log out'
		);
	}
}


class Page_TasksHome
	extends HTMLPage
{

	public function __construct( )
	{
		parent::__construct( );
		$this->addController( Loader::Ctrl( 'home_page' ) );
	}

	protected function getMenu( )
	{
		return array();
	}
}


class Page_TasksLogin
	extends HTMLPage
{

	public function __construct()
	{
		parent::__construct( );
		$this->addController( Loader::Ctrl( 'logged_out' ) );
		$this->addController( Loader::Ctrl( 'log_in_form' ) );
	}

	protected function getMenu( )
	{
		return array();
	}

}


class Page_TasksLogout
	extends Page_Basic
{
	public function __construct( )
	{
		parent::__construct( );
		$this->addController( Loader::Ctrl( 'logout' ) );
	}
}
