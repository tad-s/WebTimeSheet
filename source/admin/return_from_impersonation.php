<?php
require_once('../../config/config.php');
require_once('../functions.php');

try {
    session_start();

    // なりすまし中かどうかをチェック
    if (!isset($_SESSION['IS_IMPERSONATING']) || !isset($_SESSION['ORIGINAL_ADMIN'])) {
        header('Location:' . SITE_URL . 'admin/user_list.php');
        exit;
    }

    // 元の管理者情報を復元
    $_SESSION['USER'] = $_SESSION['ORIGINAL_ADMIN'];
    
    // なりすまし情報をクリア
    unset($_SESSION['IS_IMPERSONATING']);
    unset($_SESSION['IMPERSONATE_TARGET_NAME']);
    unset($_SESSION['ORIGINAL_ADMIN']);

    // 管理者の社員一覧画面にリダイレクト
    header('Location:' . SITE_URL . 'admin/user_list.php');
    exit;

} catch (Exception $e) {
    header('Location:' . SITE_URL . 'error.php');
    exit;
}
?>