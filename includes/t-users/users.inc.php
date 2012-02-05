<?php


class Page_TasksUsers
	extends AuthenticatedPage
{
	public function __construct( )
	{
		parent::__construct( array(
			''	=> 'users_list' ,
			'add'	=> 'users_add_form' ,
		) );
		$this->setTitle( 'Users' );
	}
}


class Ctrl_UsersList
	extends Controller
{

	public function handle( Page $page )
	{
		$dao = Loader::DAO( 'users' );
		return Loader::View( 'box' , null , Loader::View( 'users_list' , $dao->getUsers( ) ) )
			->setClass( 'list' )
			->addButton( BoxButton::create( 'Add user' , 'users/add' )
				->setClass( 'list-add' ) );
	}

}


class Ctrl_UsersAddForm
	extends Controller
{
	private $initial;

	public function __construct( $initial = false )
	{
		$this->initial = $initial;
	}

	public function handle( Page $page )
	{
		$form = Loader::Create( 'Form' , 'Create user' , 'user-add' )
			->addField( Loader::Create( 'Field' , 'email' , 'text' )
					->setDescription( 'E-mail address:' )
					->setValidator( Loader::Create( 'Validator_Email' , 'Invalid address.' ) ) )
			->addField( Loader::Create( 'Field' , 'pass' , 'password' )
					->setDescription( 'Password:' )
					->setValidator( Loader::Create( 'Validator_StringLength' , 'This password' , 8 ) ) )
			->addField( Loader::Create( 'Field' , 'pass2' , 'password' )
					->setDescription( 'Confirm password:' ) )
			->addController( Loader::Ctrl( 'users_add' , $this->initial ) );

		if ( $this->initial ) {
			$form->setSuccessURL( 'home' );
			$page->setTitle( 'Initial user' );
		} else {
			$form->setURL( 'users' );
		}

		return $form->controller( );
	}
}


class Ctrl_UsersAdd
	extends Controller
	implements FormAware
{
	private $form;
	private $initial;

	public function __construct( $initial )
	{
		$this->initial = $initial;
	}

	public function setForm( Form $form )
	{
		$this->form = $form;
	}


	public function handle( Page $page )
	{
		$p1 = $this->form->field( 'pass' );
		$p2 = $this->form->field( 'pass2' );
		if ( $p1->value( ) != $p2->value( ) ) {
			$p1->putError( 'Passwords did not match.' );
			return null;
		}

		$email = $this->form->field( 'email' );
		$error = Loader::DAO( 'users' )->addUser( $email->value( ) ,
			$p1->value( ) );

		switch ( $error ) {

			case 0:
				if ( $this->initial ) {
					session_start( );
					$_SESSION[ 'uid' ] = Loader::DAO( 'users' )->getUser( $email->value( ) )->user_id;
				}
				return true;

			case 1:
				$email->putError( 'This e-mail address is already in use.' );
				break;

			default:
				$email->putError( 'Some unknown error has occurred (' . $error . ')' );
				break;
		}
		return null;
	}

}



class View_UsersList
	extends BaseURLAwareView
{
	private $users;

	public function __construct( $users )
	{
		$this->users = $users;
	}

	public function render( )
	{
		$table = HTML::make( 'table' )
			->appendElement( HTML::make( 'tr' )
				->setAttribute( 'class' , 'header' )
				->appendElement( HTML::make( 'th' )
					->appendText( 'E-mail address' ) ) );

		foreach ( $this->users as $user ) {
			$table->appendElement( HTML::make( 'tr' )
				->appendElement( HTML::make( 'td' )
					->appendText( $user->user_email ) ) );
		}

		return $table;
	}
}
