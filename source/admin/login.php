<?php
require_once('../../config/config.php');
require_once('../functions.php');

try {

    session_start();

    if (isset($_SESSION['USER']) && $_SESSION['USER']['auth_type'] == 1) {
        //ログイン済みの場合はHOME画面へ
        header('Location:' . SITE_URL . 'admin/user_list.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        //POST処理時

        check_token();

        //1.入力値を取得
        $user_no = $_POST['user_no'];
        $password = $_POST['password'];

        //2.バリデーションチェック
        $err = array();

        if (!$user_no) {
            $err['user_no'] = '社員番号を入力して下さい。';
        } elseif (!preg_match('/^[0-9]+$/', $user_no)) {
            $err['user_no'] = '社員番号を正しく入力して下さい。';
        } elseif (mb_strlen($user_no, 'utf-8') > 20) {
            $err['user_no'] = '社員番号が長すぎます。';
        }

        if (!$password) {
            $err['password'] = 'パスワードを入力して下さい。';
        }

        if (empty($err)) {
            //3.データベースに照合
            $pdo = connectDb();

            // 社員番号の処理：3桁の数字の場合は先頭にeを付ける
            $search_user_no = $user_no;
            if (preg_match('/^[0-9]{3}$/', $user_no)) {
                $search_user_no = 'e' . $user_no;
            }

            $sql = "SELECT * FROM user WHERE user_no = :user_no AND auth_type = 1 LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_no', $search_user_no, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                //4.ログイン処理(セッションに保存)
                $_SESSION['USER'] = $user;
                //5.HOME画面へ遷移
                header('Location:' . SITE_URL . 'admin/user_list.php');
                exit;
            } else {
                $err['password'] = '認証に失敗しました。';
            }
        }
    } else {
        //画面初回アクセス時

        set_token();

        $user_no = '';
        $password = '';
    }
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

    <title>管理者ログイン | works</title>

</head>

<body class="text-center bg-green">


    <form class="border rounded bg-white form-login" method="post">
        <h2>Web勤怠管理システム</h2>
        <h1 class="h3 my-3">管理者ログイン</h1>

        <div class="form-group pt-3">
            <input type="text" class="form-control rounded-pill <?php if (isset($err['user_no'])) echo 'is-invalid'; ?>" name="user_no" value="<?php echo $user_no ?>" placeholder="社員番号（3桁の数字）">
            <div class="invalid-feedback"><?php echo $err['user_no'] ?></div>
            <small class="form-text text-muted">3桁の数字を入力してください（例：123）</small>
        </div>

        <div class="form-group pt-3">
            <input type="password" class="form-control rounded-pill <?php if (isset($err['password'])) echo 'is-invalid'; ?>" name="password" placeholder="パスワード">
            <div class="invalid-feedback"><?php echo $err['password'] ?></div>
        </div>

        <button type="submit" class="btn btn-primary text-white rounded-pill px-5 my-4">ログイン</button>
        <input type="hidden" name="CSRF_TOKEN" value="<?php echo $_SESSION['CSRF_TOKEN'] ?>">
    </form>

</body>


</html>