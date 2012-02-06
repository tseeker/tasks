<?php


class Ctrl_HomePage
	extends Controller
{

	public final function handle( Page $page )
	{
		session_start( );
		if ( ! Loader::DAO( 'users' )->hasUsers( ) ) {
			return 'install';
		}
		if (  array_key_exists( 'uid' , $_SESSION ) ) {
			return 'items';
		}
		return 'login';
	}

}


class Ctrl_Logout
	extends Controller
{

	public function handle( Page $page )
	{
		session_start( );
		session_destroy( );
		return 'home';
	}

}



class Ctrl_CheckSession
	extends Controller
{
	private $loginURL;
	private $sessionKey;

	public function __construct( $url = 'login' , $key = 'uid' )
	{
		$this->loginURL = $url;
		$this->sessionKey = $key;
	}


	public function handle( Page $page )
	{
		session_start( );
		if ( ! Loader::DAO( 'users' )->hasUsers( ) ) {
			return 'install';
		}
		if ( array_key_exists( $this->sessionKey , $_SESSION ) ) {
			return null;
		}
		return $this->loginURL;
	}
}


class Ctrl_LogInForm
	extends Controller
{

	public function handle( Page $page )
	{
		return Loader::Create( 'Form' , 'Log in' , 'login' )
			->addField( Loader::Create( 'Field' , 'email' , 'text' )
					->setDescription( 'E-mail address:' ) )
			->addField( Loader::Create( 'Field' , 'pass' , 'password' )
					->setDescription( 'Password:' ) )
			->setSuccessURL( 'home' )
			->addController( Loader::Ctrl( 'log_in' ) )
			->controller( );
	}

}


class Ctrl_LogIn
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
		$email = $this->form->field( 'email' );
		$pass = $this->form->field( 'pass' );

		$user = Loader::DAO( 'users' )->checkLogin( $email->value( ) , $pass->value( ) );
		if ( $user == null ) {
			$email->putError( 'Invalid credentials.' );
			return null;
		}

		$_SESSION[ 'uid' ] = $user->user_id;
		return 'users/view?id=' . $user->user_id;
	}
}


class Ctrl_LoggedOut
	extends Controller
{

	public function handle( Page $page )
	{
		session_start( );
		if ( array_key_exists( 'uid' , $_SESSION ) ) {
			return 'home';
		}
		return null;
	}

}


class Ctrl_Install
	extends Controller
{

	public function handle( Page $page )
	{
		if ( Loader::DAO( 'users' )->hasUsers( ) ) {
			return 'login';
		}
		return Loader::Ctrl( 'users_add_form' , true );
	}
}
