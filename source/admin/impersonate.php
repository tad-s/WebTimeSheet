<?php
require_once('../../config/config.php');
require_once('../functions.php');

try {
    session_start();

    // 管理者権限チェック
    if (!isset($_SESSION['USER']) || $_SESSION['USER']['auth_type'] != 1) {
        header('Location:' . SITE_URL . 'admin/login.php');
        exit;
    }

    // なりすまし対象のユーザーIDを取得
    $target_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    
    if (!$target_user_id) {
        header('Location:' . SITE_URL . 'admin/user_list.php');
        exit;
    }

    $pdo = connectDb();

    // 対象ユーザーの情報を取得
    $sql = "SELECT * FROM user WHERE id = :id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $target_user_id, PDO::PARAM_INT);
    $stmt->execute();
    $target_user = $stmt->fetch();

    if (!$target_user) {
        header('Location:' . SITE_URL . 'admin/user_list.php');
        exit;
    }

    // 元の管理者情報を保存（セッションに退避）
    $_SESSION['ORIGINAL_ADMIN'] = $_SESSION['USER'];
    
    // なりすまし対象ユーザーとしてセッションを更新
    $_SESSION['USER'] = $target_user;
    
    // なりすましフラグを設定
    $_SESSION['IS_IMPERSONATING'] = true;
    $_SESSION['IMPERSONATE_TARGET_NAME'] = $target_user['name'];

    // 一般ユーザーの勤怠登録画面にリダイレクト
    header('Location:' . SITE_URL . 'index.php');
    exit;

} catch (Exception $e) {
    header('Location:' . SITE_URL . 'error.php');
    exit;
}
?>