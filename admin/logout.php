<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
$_SESSION = [];
session_destroy();
redirect('admin/login.php');
