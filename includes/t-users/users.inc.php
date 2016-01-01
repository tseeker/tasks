<?php


class Page_TasksUsers
	extends AuthenticatedPage
{
	public function __construct( )
	{
		parent::__construct( array(
			''	=> 'users_list' ,
			'view'	=> 'users_view' ,
			'add'	=> 'users_add_form' ,
			'edit'	=> 'users_edit_form' ,
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
					->appendText( 'E-mail address' ) )
				->appendElement( HTML::make( 'th' )
					->appendText( 'Display name' ) ) );

		foreach ( $this->users as $user ) {
			$table->appendElement( $this->makeUserRow( $user ) );
		}

		return $table;
	}

	private function makeUserRow( $user )
	{
		$row = HTML::make( 'tr' )
			->appendElement( HTML::make( 'td' )
				->appendElement( $editLink = HTML::make( 'a' ) ) );

		$editLink->setAttribute( 'href' , $this->base . '/users/view?id=' . $user->user_id )
			->appendText( $user->user_email );

		$nameColumn = HTML::make( 'td' );
		if ( $user->user_display_name !== null ) {
			$nameColumn->appendText( $user->user_display_name );
		} else {
			$nameColumn->appendElement( HTML::make( 'em' )->appendText( 'N/A' ) );
		}
		$row->appendElement( $nameColumn );

		return $row;
	}
}


class Ctrl_UsersView
	extends Controller
{

	public function handle( Page $page )
	{
		try {
			$id = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'users';
		}

		$user = Loader::DAO( 'users' )->getUserById( $id );
		if ( $user === null ) {
			return 'users';
		}

		$page->setTitle( $user->user_view_name );

		return array(
		       	Loader::View( 'box' , 'User information' , Loader::View( 'users_view' , $user ) )
				->addButton( BoxButton::create( 'Edit user' , 'users/edit?id=' . $id )
					->setClass( 'icon edit' ) ) ,
			Loader::View( 'box' , 'Assigned tasks' , Loader::View( 'tasks_list' ,
						Loader::DAO( 'tasks' )->getUserTasks( $user ) , array( 'deps' , 'item' ) ) ) );
	}

}


class View_UsersView
	implements View
{
	private $user;

	public function __construct( $user )
	{
		$this->user = $user;
	}

	public function render( )
	{
		$fields = array( );

		$fields[] = HTML::make( 'dt' )->appendText( 'E-mail address:' );
		$fields[] = HTML::make( 'dd' )->appendText( $this->user->user_email );
		$fields[] = HTML::make( 'dt' )->appendText( 'Display name:' );
		if ( $this->user->user_display_name === null ) {
			$fields[] = HTML::make( 'dd' )->appendElement(
				HTML::make( 'em' )->appendText( 'None defined at this time' ) );
		} else {
			$fields[] = HTML::make( 'dd' )->appendText( $this->user->user_display_name );
		}

		return HTML::make( 'dl' )
			->append( $fields );
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
			->addField( Loader::Create( 'Field' , 'display-name' , 'text' )
					->setDescription( 'Display name:' )
					->setMandatory( false )
					->setValidator( Loader::Create( 'Validator_StringLength' , 'This display name',
										5 , 256 , true ) ) )
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
		$name = $this->form->field( 'display-name' );
		$error = Loader::DAO( 'users' )->addUser( $email->value( ) ,
			$p1->value( ) , $name->value( ) );

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


class Ctrl_UsersEditForm
	extends Controller
{

	public function handle( Page $page )
	{
		try {
			$userId = (int) $this->getParameter( 'id' );
		} catch ( ParameterException $e ) {
			return 'users';
		}
		$user = Loader::DAO( 'users' )->getUserById( $userId );
		if ( $user === null ) {
			return 'users';
		}

		$page->setTitle( 'Modify user ' . $user->user_view_name );

		$details = Loader::Create( 'Form' , 'Modify user' , 'user-edit' , 'Account details' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $user->user_id ) )
			->addField( Loader::Create( 'Field' , 'email' , 'text' )
					->setDescription( 'E-mail address:' )
					->setValidator( Loader::Create( 'Validator_Email' , 'Invalid address.' ) )
					->setDefaultValue( $user->user_email ) )
			->addField( Loader::Create( 'Field' , 'display-name' , 'text' )
					->setDescription( 'Display name:' )
					->setMandatory( false )
					->setValidator( Loader::Create( 'Validator_StringLength' , 'This display name',
										0 , 256 , true ) )
					->setDefaultValue( $user->user_display_name ) )
			->addController( Loader::Ctrl( 'users_edit' ) )
			->setURL( 'users/view?id=' . $userId );

		$password = Loader::Create( 'Form' , 'Modify password' , 'user-set-password' , 'Account password' )
			->addField( Loader::Create( 'Field' , 'id' , 'hidden' )
				->setDefaultValue( $user->user_id ) )
			->addField( Loader::Create( 'Field' , 'pass' , 'password' )
					->setDescription( 'New password:' )
					->setValidator( Loader::Create( 'Validator_StringLength' , 'This password' , 8 ) ) )
			->addField( Loader::Create( 'Field' , 'pass2' , 'password' )
					->setDescription( 'Confirm new password:' ) )
			->addController( Loader::Ctrl( 'users_set_password' ) )
			->setURL( 'users/view?id=' . $userId );

		return array( $details->controller( ) , $password->controller( ) );
	}

}


class Ctrl_UsersEdit
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
		$id = $this->form->field( 'id' )->value( );
		$name = $this->form->field( 'display-name' )->value( );
		$emailField = $this->form->field( 'email' );
		$email = $emailField->value( );

		$error = Loader::DAO( 'users' )->modify( $id , $email , $name );
		switch ( $error ) {

			case 0:
				return true;

			case 1:
				$email->putError( 'Duplicate address.' );
				break;

			default:
				$email->putError( 'An unknown error occurred (' . $error . ')' );
				break;
		}

		return null;
	}
}


class Ctrl_UsersSetPassword
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
		$p1 = $this->form->field( 'pass' );
		$p2 = $this->form->field( 'pass2' );
		if ( $p1->value( ) != $p2->value( ) ) {
			$p1->putError( 'Passwords did not match.' );
			return null;
		}

		$id = $this->form->field( 'id' )->value( );
		Loader::DAO( 'users' )->setPassword( $id , $p1->value( ) );
		return true;
	}
}
