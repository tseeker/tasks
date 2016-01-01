<?php

$package[ 'requires' ][] = 'form';
$package[ 'requires' ][] = 'hub-page';

$package[ 'files' ][] = 'dao_users';
$package[ 'files' ][] = 'controllers';
$package[ 'files' ][] = 'pages';

$package[ 'extras' ][] = 'AuthenticatedPage';

$package[ 'ctrls' ][] = 'check_session';
$package[ 'ctrls' ][] = 'home_page';
$package[ 'ctrls' ][] = 'install';
$package[ 'ctrls' ][] = 'log_in';
$package[ 'ctrls' ][] = 'log_in_form';
$package[ 'ctrls' ][] = 'logged_out';
$package[ 'ctrls' ][] = 'logout';

$package[ 'pages' ][] = 'tasks_home';
$package[ 'pages' ][] = 'tasks_login';
$package[ 'pages' ][] = 'tasks_logout';
$package[ 'pages' ][] = 'tasks_install';

$package[ 'daos' ][] = 'users';
