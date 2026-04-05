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

    $pdo = connectDb();
    $err = array();
    $success_message = '';

    // 初期値
    $user_no = '';
    $name = '';
    $password = '';
    $password_confirm = '';
    $auth_type = 0; // デフォルトは一般ユーザー

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        check_token();

        // 入力値取得
        $user_no = $_POST['user_no'];
        $name = $_POST['name'];
        $password = $_POST['password'];
        $password_confirm = $_POST['password_confirm'];
        $auth_type = $_POST['auth_type'];

        // バリデーション
        if (!$user_no) {
            $err['user_no'] = '社員番号を入力して下さい。';
        } elseif (!preg_match('/^[0-9]+$/', $user_no)) {
            $err['user_no'] = '社員番号は半角数字で入力して下さい。';
        } elseif (mb_strlen($user_no, 'utf-8') != 3) {
            $err['user_no'] = '社員番号は3桁の数字で入力して下さい。';
        } else {
            // 社員番号の重複チェック（eを付けた形でチェック）
            $full_user_no = 'e' . $user_no;
            $sql = "SELECT COUNT(*) FROM user WHERE user_no = :user_no";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_no', $full_user_no, PDO::PARAM_STR);
            $stmt->execute();
            if ($stmt->fetchColumn() > 0) {
                $err['user_no'] = 'この社員番号は既に登録されています。';
            }
        }

        if (!$name) {
            $err['name'] = '社員名を入力して下さい。';
        } elseif (mb_strlen($name, 'utf-8') > 20) {
            $err['name'] = '社員名は20文字以内で入力して下さい。';
        }

        if (!$password) {
            $err['password'] = 'パスワードを入力して下さい。';
        } elseif (mb_strlen($password, 'utf-8') < 4) {
            $err['password'] = 'パスワードは4文字以上で入力して下さい。';
        } elseif (mb_strlen($password, 'utf-8') > 50) {
            $err['password'] = 'パスワードは50文字以内で入力して下さい。';
        }

        if (!$password_confirm) {
            $err['password_confirm'] = 'パスワード（確認）を入力して下さい。';
        } elseif ($password !== $password_confirm) {
            $err['password_confirm'] = 'パスワードが一致しません。';
        }

        if (!in_array($auth_type, ['0', '1'])) {
            $err['auth_type'] = '権限を正しく選択して下さい。';
        }

        // エラーがない場合、ユーザー登録
        if (empty($err)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            // 社員番号に"e"を付けて登録
            $full_user_no = 'e' . $user_no;
            
            $sql = "INSERT INTO user (user_no, name, password, auth_type, edit_flg, kubun) VALUES (:user_no, :name, :password, :auth_type, 0, '00')";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_no', $full_user_no, PDO::PARAM_STR);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':password', $hashed_password, PDO::PARAM_STR);
            $stmt->bindValue(':auth_type', (int)$auth_type, PDO::PARAM_INT);
            $stmt->execute();

            $success_message = 'ユーザーを登録しました。（社員番号: ' . $full_user_no . '）';
            
            // フォームをリセット
            $user_no = '';
            $name = '';
            $password = '';
            $password_confirm = '';
            $auth_type = 0;
        }
    } else {
        set_token();
    }

} catch (Exception $e) {
    header('Location:' . SITE_URL . 'error.php');
    exit;
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
    <title>新規ユーザー登録 | works</title>
</head>

<body class="text-center bg-green">

    <form class="border rounded bg-white form-login" method="post">
        <h2>Web勤怠管理システム</h2>
        <h1 class="h3 my-3">新規ユーザー登録</h1>

        <?php if ($success_message): ?>
            <div class="alert alert-success" role="alert">
                <?php echo h($success_message); ?>
            </div>
        <?php endif; ?>

        <div class="form-group pt-3">
            <input type="text" class="form-control rounded-pill <?php if (isset($err['user_no'])) echo 'is-invalid'; ?>" 
                   name="user_no" value="<?php echo h($user_no); ?>" placeholder="社員番号（3桁の数字）" maxlength="3">
            <div class="invalid-feedback"><?php echo isset($err['user_no']) ? $err['user_no'] : ''; ?></div>
            <small class="form-text text-muted">3桁の数字を入力してください。登録時に自動的に先頭に「e」が付きます。</small>
        </div>

        <div class="form-group pt-3">
            <input type="text" class="form-control rounded-pill <?php if (isset($err['name'])) echo 'is-invalid'; ?>" 
                   name="name" value="<?php echo h($name); ?>" placeholder="社員名">
            <div class="invalid-feedback"><?php echo isset($err['name']) ? $err['name'] : ''; ?></div>
        </div>

        <div class="form-group pt-3">
            <input type="password" class="form-control rounded-pill <?php if (isset($err['password'])) echo 'is-invalid'; ?>" 
                   name="password" placeholder="パスワード（4文字以上）">
            <div class="invalid-feedback"><?php echo isset($err['password']) ? $err['password'] : ''; ?></div>
        </div>

        <div class="form-group pt-3">
            <input type="password" class="form-control rounded-pill <?php if (isset($err['password_confirm'])) echo 'is-invalid'; ?>" 
                   name="password_confirm" placeholder="パスワード（確認）">
            <div class="invalid-feedback"><?php echo isset($err['password_confirm']) ? $err['password_confirm'] : ''; ?></div>
        </div>

        <div class="form-group pt-3">
            <select class="form-control rounded-pill <?php if (isset($err['auth_type'])) echo 'is-invalid'; ?>" name="auth_type">
                <option value="0" <?php if ($auth_type == 0) echo 'selected'; ?>>一般ユーザー</option>
                <option value="1" <?php if ($auth_type == 1) echo 'selected'; ?>>管理者</option>
            </select>
            <div class="invalid-feedback"><?php echo isset($err['auth_type']) ? $err['auth_type'] : ''; ?></div>
        </div>

        <div class="pt-3">
            <button type="submit" class="btn btn-primary text-white rounded-pill px-5 my-2">登録</button>
            <a href="<?php echo SITE_URL; ?>admin/user_list.php" class="btn btn-secondary rounded-pill px-5 my-2">戻る</a>
        </div>

        <input type="hidden" name="CSRF_TOKEN" value="<?php echo $_SESSION['CSRF_TOKEN']; ?>">
    </form>

    <script src="//code.jquery.com/jquery.js"></script>
    <script src="../js/bootstrap.min.js"></script>

</body>

</html>