<?php
require_once('../../config/config.php');
require_once('../functions.php');

try {

    session_start();

    if (!isset($_SESSION['USER']) || $_SESSION['USER']['auth_type'] != 1) {
        //ログインされていない場合はログイン画面へ
        header('Location:' . SITE_URL . 'admin/login.php');
        exit;
    }

    $pdo = connectDb();

    // ログイン中の管理者のteam_noを取得
    $current_user_team_no = $_SESSION['USER']['team_no'];

    // team_noに基づいてクエリを構築
    if ($current_user_team_no == 0) {
        // team_no が 0 の場合は全てのユーザーを表示
        $sql = "SELECT * FROM user ORDER BY user_no";
        $stmt = $pdo->query($sql);
    } else {
        // team_no が 0 以外の場合は同じteam_noのユーザーのみ表示
        $sql = "SELECT * FROM user WHERE team_no = :team_no ORDER BY user_no";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':team_no', $current_user_team_no, PDO::PARAM_INT);
        $stmt->execute();
    }
    
    $user_list = $stmt->fetchAll();

    // 特別権限チェック（team_no が 0 かどうか）
    $is_super_admin = ($current_user_team_no == 0);

} catch (Exception $e) {
    header('Location:' . SITE_URL . 'error.php');
}
?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit-no">
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">

    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.5.1/css/all.css">

    <title>社員一覧 | works</title>

</head>

<body class="text-center bg-green">

    <form class="border rounded bg-white form-user-list" action="index.php">

        <h1 class="h3 my-3">社員一覧</h1>

        <div class="float-right p-1">
            <?php if ($is_super_admin): ?>
            <a class="btn btn-success mr-2" href="<?php echo SITE_URL ?>admin/user_register.php">
                <i class="fas fa-user-plus"></i> 新規ユーザー登録
            </a>
            <?php endif; ?>
            <a class="btn bbb btn-outline-primary" href="<?php echo SITE_URL ?>admin/logout.php">ログアウト</a>
        </div>

        <table class="table table-bordered">
            <thead>
                <tr class="bg-light">
                    <th scope="col">社員番号</th>
                    <th scope="col">社員名</th>
                    <th scope="col">チーム</th>
                    <th scope="col">権限</th>
                    <th scope="col">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($user_list as $user) : ?>
                    <tr>
                        <td scope="row"><?php echo h($user['user_no']); ?></td>
                        <td>
                            <?php if ($is_super_admin): ?>
                            <a href="<?php echo SITE_URL ?>admin/impersonate.php?user_id=<?php echo $user['id'] ?>" class="text-primary">
                                <i class="fas fa-user-circle"></i> <?php echo h($user['name']); ?>
                            </a>
                            <?php else: ?>
                            <i class="fas fa-user-circle"></i> <?php echo h($user['name']); ?>
                            <?php endif; ?>
                        </td>
                        <td scope="row"><?php echo h($user['team_no']); ?></td>
                        <td scope="row"><?php if ($user['auth_type'] == 1) echo '管理者'; else echo '一般ユーザー'; ?></td>
                        <td>
                            <a href="<?php echo SITE_URL ?>admin/user_result.php?id=<?php echo $user['id'] ?>" 
                               class="btn btn-sm btn-outline-secondary mr-1">
                                <i class="fas fa-calendar-alt"></i> 勤怠確認
                            </a>
                            <?php if ($is_super_admin): ?>
                            <a href="<?php echo SITE_URL ?>admin/user_edit.php?id=<?php echo $user['id'] ?>" 
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit"></i> 編集
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    </form>

    <script src="//code.jquery.com/jquery.js"></script>
    <script src="../js/bootstrap.min.js"></script>

</body>

</html>