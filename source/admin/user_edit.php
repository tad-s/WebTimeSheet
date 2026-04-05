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

    // ユーザーIDの取得
    $user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$user_id) {
        header('Location:' . SITE_URL . 'admin/user_list.php');
        exit;
    }

    $pdo = connectDb();
    $err = array();
    $success_message = '';

    // 編集対象ユーザーの取得
    $sql = "SELECT * FROM user WHERE id = :id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $edit_user = $stmt->fetch();

    if (!$edit_user) {
        header('Location:' . SITE_URL . 'admin/user_list.php');
        exit;
    }

    // 初期値設定
    $user_no = $edit_user['user_no'];
    $name = $edit_user['name'];
    $auth_type = $edit_user['auth_type'];
    $team_no = isset($edit_user['team_no']) ? $edit_user['team_no'] : '';
    $password = '';
    $password_confirm = '';

    // 社員番号から"e"を除いた部分を表示用に取得
    $display_user_no = (substr($user_no, 0, 1) === 'e') ? substr($user_no, 1) : $user_no;

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        check_token();

        // 入力値取得
        $input_user_no = $_POST['user_no'];
        $name = $_POST['name'];
        $password = $_POST['password'];
        $password_confirm = $_POST['password_confirm'];
        $auth_type = $_POST['auth_type'];
        $team_no = isset($_POST['team_no']) ? $_POST['team_no'] : '';

        // バリデーション
        if (!$input_user_no) {
            $err['user_no'] = '社員番号を入力して下さい。';
        } elseif (!preg_match('/^[0-9]+$/', $input_user_no)) {
            $err['user_no'] = '社員番号は半角数字で入力して下さい。';
        } elseif (mb_strlen($input_user_no, 'utf-8') != 3) {
            $err['user_no'] = '社員番号は3桁の数字で入力して下さい。';
        } else {
            // 社員番号の重複チェック（自分以外で同じ番号がないかチェック）
            $full_user_no = 'e' . $input_user_no;
            if ($full_user_no !== $edit_user['user_no']) {
                $sql = "SELECT COUNT(*) FROM user WHERE user_no = :user_no AND id != :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':user_no', $full_user_no, PDO::PARAM_STR);
                $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
                $stmt->execute();
                if ($stmt->fetchColumn() > 0) {
                    $err['user_no'] = 'この社員番号は既に登録されています。';
                }
            }
        }

        if (!$name) {
            $err['name'] = '社員名を入力して下さい。';
        } elseif (mb_strlen($name, 'utf-8') > 20) {
            $err['name'] = '社員名は20文字以内で入力して下さい。';
        }

        // パスワードが入力されている場合のみチェック
        if ($password) {
            if (mb_strlen($password, 'utf-8') < 4) {
                $err['password'] = 'パスワードは4文字以上で入力して下さい。';
            } elseif (mb_strlen($password, 'utf-8') > 50) {
                $err['password'] = 'パスワードは50文字以内で入力して下さい。';
            }

            if (!$password_confirm) {
                $err['password_confirm'] = 'パスワード（確認）を入力して下さい。';
            } elseif ($password !== $password_confirm) {
                $err['password_confirm'] = 'パスワードが一致しません。';
            }
        }

        if (!in_array($auth_type, ['0', '1'])) {
            $err['auth_type'] = '権限を正しく選択して下さい。';
        }

        // team_noのバリデーション
        if ($team_no !== '') {
            if (!is_numeric($team_no)) {
                $err['team_no'] = 'チーム番号は数字で入力して下さい。';
            } elseif ((int)$team_no < 0 || (int)$team_no > 99) {
                $err['team_no'] = 'チーム番号は0から99の範囲で入力して下さい。';
            }
        }

        // エラーがない場合、ユーザー更新
        if (empty($err)) {
            $full_user_no = 'e' . $input_user_no;
            
            // team_noの処理
            $update_team_no = ($team_no === '') ? null : (int)$team_no;
            
            if ($password) {
                // パスワードも更新
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql = "UPDATE user SET user_no = :user_no, name = :name, password = :password, auth_type = :auth_type, team_no = :team_no WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':user_no', $full_user_no, PDO::PARAM_STR);
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':password', $hashed_password, PDO::PARAM_STR);
                $stmt->bindValue(':auth_type', (int)$auth_type, PDO::PARAM_INT);
                if ($update_team_no === null) {
                    $stmt->bindValue(':team_no', null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(':team_no', $update_team_no, PDO::PARAM_INT);
                }
                $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
            } else {
                // パスワード以外を更新
                $sql = "UPDATE user SET user_no = :user_no, name = :name, auth_type = :auth_type, team_no = :team_no WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':user_no', $full_user_no, PDO::PARAM_STR);
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':auth_type', (int)$auth_type, PDO::PARAM_INT);
                if ($update_team_no === null) {
                    $stmt->bindValue(':team_no', null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(':team_no', $update_team_no, PDO::PARAM_INT);
                }
                $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
            }
            $stmt->execute();

            $success_message = 'ユーザー情報を更新しました。';
            
            // 更新後の情報を再取得
            $sql = "SELECT * FROM user WHERE id = :id LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $edit_user = $stmt->fetch();
            
            $user_no = $edit_user['user_no'];
            $display_user_no = (substr($user_no, 0, 1) === 'e') ? substr($user_no, 1) : $user_no;
            $auth_type = $edit_user['auth_type'];
            $team_no = isset($edit_user['team_no']) ? $edit_user['team_no'] : '';
            
            // パスワード欄をクリア
            $password = '';
            $password_confirm = '';
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
    <title>ユーザー編集 | works</title>
</head>

<body class="text-center bg-green">

    <form class="border rounded bg-white form-login" method="post">
        <h2>Web勤怠管理システム</h2>
        <h1 class="h3 my-3">ユーザー編集</h1>

        <?php if ($success_message): ?>
            <div class="alert alert-success" role="alert">
                <?php echo h($success_message); ?>
            </div>
        <?php endif; ?>

        <div class="form-group pt-3">
            <input type="text" class="form-control rounded-pill <?php if (isset($err['user_no'])) echo 'is-invalid'; ?>" 
                   name="user_no" value="<?php echo h($display_user_no); ?>" placeholder="社員番号（3桁の数字）" maxlength="3">
            <div class="invalid-feedback"><?php echo isset($err['user_no']) ? $err['user_no'] : ''; ?></div>
            <small class="form-text text-muted">3桁の数字を入力してください。保存時に自動的に先頭に「e」が付きます。</small>
        </div>

        <div class="form-group pt-3">
            <input type="text" class="form-control rounded-pill <?php if (isset($err['name'])) echo 'is-invalid'; ?>" 
                   name="name" value="<?php echo h($name); ?>" placeholder="社員名">
            <div class="invalid-feedback"><?php echo isset($err['name']) ? $err['name'] : ''; ?></div>
        </div>

        <div class="form-group pt-3">
            <input type="password" class="form-control rounded-pill <?php if (isset($err['password'])) echo 'is-invalid'; ?>" 
                   name="password" placeholder="パスワード（変更する場合のみ入力）">
            <div class="invalid-feedback"><?php echo isset($err['password']) ? $err['password'] : ''; ?></div>
            <small class="form-text text-muted">パスワードを変更しない場合は空欄のままにしてください。</small>
        </div>

        <div class="form-group pt-3">
            <input type="password" class="form-control rounded-pill <?php if (isset($err['password_confirm'])) echo 'is-invalid'; ?>" 
                   name="password_confirm" placeholder="パスワード（確認）">
            <div class="invalid-feedback"><?php echo isset($err['password_confirm']) ? $err['password_confirm'] : ''; ?></div>
        </div>

        <!-- パスワードリセット用のボタン -->
        <div class="form-group pt-2">
            <small class="form-text text-muted">
                ※ログインできない場合は、パスワードを「test」にリセットしてください。
            </small>
            <button type="button" class="btn btn-warning btn-sm" onclick="resetPassword()">
                パスワードを「test」にリセット
            </button>
        </div>

        <div class="form-group pt-3">
            <select class="form-control rounded-pill <?php if (isset($err['auth_type'])) echo 'is-invalid'; ?>" name="auth_type">
                <option value="0" <?php if ($auth_type == 0) echo 'selected'; ?>>一般ユーザー</option>
                <option value="1" <?php if ($auth_type == 1) echo 'selected'; ?>>管理者</option>
            </select>
            <div class="invalid-feedback"><?php echo isset($err['auth_type']) ? $err['auth_type'] : ''; ?></div>
        </div>

        <div class="form-group pt-3">
            <input type="text" class="form-control rounded-pill <?php if (isset($err['team_no'])) echo 'is-invalid'; ?>" 
                   name="team_no" value="<?php echo h($team_no); ?>" placeholder="チーム番号（0-99）">
            <div class="invalid-feedback"><?php echo isset($err['team_no']) ? $err['team_no'] : ''; ?></div>
            <small class="form-text text-muted">チーム番号を0-99の範囲で入力してください。空の場合は未設定となります。</small>
        </div>

        <div class="pt-3">
            <button type="submit" class="btn btn-primary text-white rounded-pill px-5 my-2">更新</button>
            <a href="<?php echo SITE_URL; ?>admin/user_list.php" class="btn btn-secondary rounded-pill px-5 my-2">戻る</a>
        </div>

        <input type="hidden" name="CSRF_TOKEN" value="<?php echo $_SESSION['CSRF_TOKEN']; ?>">
    </form>

    <script src="//code.jquery.com/jquery.js"></script>
    <script src="../js/bootstrap.min.js"></script>

    <script>
    function resetPassword() {
        if (confirm('このユーザーのパスワードを「test」にリセットしますか？')) {
            document.querySelector('input[name="password"]').value = 'test';
            document.querySelector('input[name="password_confirm"]').value = 'test';
            alert('パスワード欄に「test」が設定されました。「更新」ボタンをクリックして保存してください。');
        }
    }
    </script>

</body>

</html>