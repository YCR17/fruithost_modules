<?php
	use fruithost\Storage\Database;
	
	Database::query('DROP TABLE IF EXISTS `' . DATABASE_PREFIX . 'domains`;');
	
	// @ToDo delete hosting folders?
?>