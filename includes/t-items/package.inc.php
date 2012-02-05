<?php

$package[ 'requires' ][] = 'form';
$package[ 'requires' ][] = 't-basics';
$package[ 'requires' ][] = 't-data';

$package[ 'files' ][] = 'controllers';
$package[ 'files' ][] = 'fields';
$package[ 'files' ][] = 'page_controllers';
$package[ 'files' ][] = 'pages';
$package[ 'files' ][] = 'views';

$package[ 'extras' ][] = 'Item_NameField';
$package[ 'extras' ][] = 'Item_LocationField';

$package[ 'ctrls' ][] = 'add_item';
$package[ 'ctrls' ][] = 'add_item_form';
$package[ 'ctrls' ][] = 'delete_item';
$package[ 'ctrls' ][] = 'delete_item_form';
$package[ 'ctrls' ][] = 'edit_item';
$package[ 'ctrls' ][] = 'edit_item_form';
$package[ 'ctrls' ][] = 'move_item';
$package[ 'ctrls' ][] = 'move_item_form';
$package[ 'ctrls' ][] = 'item_details';
$package[ 'ctrls' ][] = 'item_tasks';
$package[ 'ctrls' ][] = 'items_tree';
$package[ 'ctrls' ][] = 'view_item';

$package[ 'views' ][] = 'item_details';
$package[ 'views' ][] = 'items_tree';

$package[ 'pages' ][] = 'tasks_items';
