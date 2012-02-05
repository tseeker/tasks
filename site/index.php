<?php

include ( '../arse/includes/loader.inc.php' );
Loader::AddPath( dirname( __FILE__ ) . '/../includes' );
Loader::Create( 'URLMapper' , 'tasks' )->fromPathInfo( );


?>
