<?php
require_once('../../config/config.php');


session_start();

// ログアウト処理
$_SESSION = array();

session_destroy();

header('Location:' . SITE_URL . 'admin/login.php');
