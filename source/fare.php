<?php
require_once('../config/config.php');
require_once('functions.php');

try {
    //1.ログイン状態をチェック
    session_start();

    if (!isset($_SESSION['USER'])) {
        //ログインされていない場合はログイン画面へ
        header('Location:' . SITE_URL . './login.php');
        exit;
    }

    //ログインユーザーの情報をセッションから取得
    $session_user = $_SESSION['USER'];

    // ★【なりすまし機能追加】なりすまし状態のチェック
    $is_impersonating = isset($_SESSION['IS_IMPERSONATING']) && $_SESSION['IS_IMPERSONATING'];
    $impersonate_target_name = isset($_SESSION['IMPERSONATE_TARGET_NAME']) ? $_SESSION['IMPERSONATE_TARGET_NAME'] : '';


    $pdo = connectDb();

    $err = array();

    $target_date = date('Y-m-d');
    
    // モーダル用変数の初期化
    $modal_expense_type = 0; // t_flagから変更
    $modal_date = date('Y-m-d');
    $modal_from_name = '';
    $modal_to_name = '';
    $modal_cost = '';
    $modal_bikou = '';
    $modal_round_trip = 0; // 往復フラグ

    //モーダルの自動表示判定
    $modal_view_fig = FALSE;

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        
        if (isset($_POST['action'])) {
            
            if ($_POST['action'] == 'register') {
                //交通費登録処理
                check_token();

                //入力値をPOSTパラメーターから取得
                $edit_id = $_POST['edit_id'] ?? null;
                $modal_expense_type = isset($_POST['modal_expense_type']) ? (int)$_POST['modal_expense_type'] : 0;
                $modal_date = $_POST['modal_date'];
                $modal_from_name = $_POST['modal_from_name'];
                $modal_to_name = $_POST['modal_to_name'];
                $modal_cost = $_POST['modal_cost'];
                $modal_bikou = $_POST['modal_bikou'];
                $modal_round_trip = isset($_POST['modal_round_trip']) ? 1 : 0;

                // ★【編集権限チェック追加】対象日が編集可能かチェック
                $target_yyyymm = date('Y-m', strtotime($modal_date));
                if (!canEditMonth($target_yyyymm, $session_user)) {
                    $err['modal_date'] = 'この月の交通費は編集できません。';
                }

                //出発駅名の必須チェック
                if (!$modal_from_name) {
                    $err['modal_from_name'] = '出発駅名を入力して下さい';
                } elseif (mb_strlen($modal_from_name, 'utf-8') > 20) {
                    $err['modal_from_name'] = '出発駅名が長すぎます。';
                }

                //到着駅名の必須チェック
                if (!$modal_to_name) {
                    $err['modal_to_name'] = '到着駅名を入力して下さい';
                } elseif (mb_strlen($modal_to_name, 'utf-8') > 20) {
                    $err['modal_to_name'] = '到着駅名が長すぎます。';
                }

                //金額の必須・形式チェック
                if ($modal_cost<1) {
                    $err['modal_cost'] = '値は1以上にする必要があります。';
                } elseif (!preg_match('/^[0-9]+$/', $modal_cost)) {
                    $err['modal_cost'] = '金額は数字で入力して下さい';
                } elseif ((int)$modal_cost > 9999999) {
                    $err['modal_cost'] = '金額が大きすぎます。';
                }

                //備考の最大サイズチェック
                if (mb_strlen($modal_bikou, 'utf-8') > 100) {
                    $err['modal_bikou'] = '備考が長すぎます。';
                }

                if (empty($err)) {
                    // 定期の場合は片道金額をそのまま、それ以外で往復チェックがある場合は往復料金
                    $total_cost = ($modal_expense_type == 1) ? (int)$modal_cost : 
                                  ($modal_round_trip ? (int)$modal_cost * 2 : (int)$modal_cost);
                    
                    if ($edit_id) {
                        //更新処理
                        $sql = "UPDATE fare SET t_flag = :t_flag, date = :date, from_name = :from_name, to_name = :to_name, cost = :cost, total_cost = :total_cost, bikou = :bikou, oufuku_f = :oufuku_f WHERE id = :id AND user_no = :user_no";
                        $stmt = $pdo->prepare($sql);
                        $stmt->bindValue(':id', (int)$edit_id, PDO::PARAM_INT);
                        $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
                        $stmt->bindValue(':t_flag', $modal_expense_type, PDO::PARAM_INT);
                        $stmt->bindValue(':date', $modal_date, PDO::PARAM_STR);
                        $stmt->bindValue(':from_name', $modal_from_name, PDO::PARAM_STR);
                        $stmt->bindValue(':to_name', $modal_to_name, PDO::PARAM_STR);
                        $stmt->bindValue(':cost', (int)$modal_cost, PDO::PARAM_INT);
                        $stmt->bindValue(':total_cost', $total_cost, PDO::PARAM_INT);
                        $stmt->bindValue(':bikou', $modal_bikou, PDO::PARAM_STR);
                        $stmt->bindValue(':oufuku_f', $modal_round_trip, PDO::PARAM_INT);
                        $stmt->execute();
                    } else {
                        //新規登録
                        $sql = "INSERT INTO fare (user_no, user_name, t_flag, date, from_name, to_name, cost, total_cost, bikou, oufuku_f) VALUES (:user_no, :user_name, :t_flag, :date, :from_name, :to_name, :cost, :total_cost, :bikou, :oufuku_f)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
                        $stmt->bindValue(':user_name', $session_user['name'], PDO::PARAM_STR);
                        $stmt->bindValue(':t_flag', $modal_expense_type, PDO::PARAM_INT);
                        $stmt->bindValue(':date', $modal_date, PDO::PARAM_STR);
                        $stmt->bindValue(':from_name', $modal_from_name, PDO::PARAM_STR);
                        $stmt->bindValue(':to_name', $modal_to_name, PDO::PARAM_STR);
                        $stmt->bindValue(':cost', (int)$modal_cost, PDO::PARAM_INT);
                        $stmt->bindValue(':total_cost', $total_cost, PDO::PARAM_INT);
                        $stmt->bindValue(':bikou', $modal_bikou, PDO::PARAM_STR);
                        $stmt->bindValue(':oufuku_f', $modal_round_trip, PDO::PARAM_INT);
                        $stmt->execute();
                    }

                    // 初期化
                    $modal_expense_type = 0;
                    $modal_date = date('Y-m-d');
                    $modal_from_name = '';
                    $modal_to_name = '';
                    $modal_cost = '';
                    $modal_bikou = '';
                    $modal_round_trip = 0;
                } else {
                    $modal_view_fig = TRUE;
                }
            } elseif ($_POST['action'] == 'delete') {
                //削除処理
                check_token();
                
                $delete_id = $_POST['delete_id'];
                
                // ★【編集権限チェック追加】削除対象データの月が編集可能かチェック
                // まず削除対象データの情報を取得
                $sql = "SELECT date FROM fare WHERE id = :id AND user_no = :user_no LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':id', (int)$delete_id, PDO::PARAM_INT);
                $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
                $stmt->execute();
                $delete_target = $stmt->fetch();
                
                if ($delete_target) {
                    $delete_yyyymm = date('Y-m', strtotime($delete_target['date']));
                    if (!canEditMonth($delete_yyyymm, $session_user)) {
                        // 編集不可の場合はエラーページにリダイレクト
                        header('Location:' . SITE_URL . 'error.php');
                        exit;
                    }
                }
                
                $sql = "DELETE FROM fare WHERE id = :id AND user_no = :user_no";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':id', (int)$delete_id, PDO::PARAM_INT);
                $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
                $stmt->execute();
            }
        }
    } else {
        set_token();
    }

    //2.交通費データを取得
    if (isset($_GET['m'])) {
        $yyyymm = $_GET['m'];
        $day_count = date('t', strtotime($yyyymm));

        if (count(explode('-', $yyyymm)) != 2) {
            throw new Exception('日付の指定が不正', 500);
        }

        //今月～過去12か月の範囲内かどうか
        $check_date = new DateTime($yyyymm . '-01');
        $start_date = new DateTime('first day of -11 month 00:00');
        $end_date = new DateTime('first day of this month 00:00');

        if ($check_date < $start_date || $end_date < $check_date) {
            throw new Exception('日付の範囲が不正', 500);
        }
    } else {
        $yyyymm = date('Y-m');
        $day_count = date('t');
    }

    // 定期の交通費データを取得（t_flag = 1）
    $sql = "SELECT * FROM fare WHERE user_no = :user_no AND t_flag = 1 AND DATE_FORMAT(date,'%Y-%m') = :date ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
    $stmt->bindValue(':date', $yyyymm, PDO::PARAM_STR);
    $stmt->execute();
    $teiki_list = $stmt->fetchAll();

    // 社内経費（給与合算）・現場請求の交通費データを取得（t_flag = 0 or 3）
    $sql = "SELECT * FROM fare WHERE user_no = :user_no AND t_flag IN (0, 3) AND DATE_FORMAT(date,'%Y-%m') = :date ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
    $stmt->bindValue(':date', $yyyymm, PDO::PARAM_STR);
    $stmt->execute();
    $kyuyo_genba_list = $stmt->fetchAll();

    // 社内経費（現金精算）の交通費データを取得（t_flag = 2）
    $sql = "SELECT * FROM fare WHERE user_no = :user_no AND t_flag = 2 AND DATE_FORMAT(date,'%Y-%m') = :date ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
    $stmt->bindValue(':date', $yyyymm, PDO::PARAM_STR);
    $stmt->execute();
    $genkin_list = $stmt->fetchAll();

} catch (Exception $e) {
    header('Location:' . SITE_URL . 'error.php');
    exit;
}

// 経費区分の選択肢を取得する関数
function getExpenseTypeOptions() {
    return array(
        0 => '社内経費(給与合算)',
        1 => '定期',
        2 => '社内経費(現金精算)',
        3 => '現場請求'
    );
}

// 経費区分のDBの値から表示名を取得する関数
function getExpenseTypeName($type) {
    $options = getExpenseTypeOptions();
    return isset($options[$type]) ? $options[$type] : '';
}

?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">

    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.5.1/css/all.css">

    <title>交通費入力 | works</title>

    <style>
        /* ★【なりすまし機能追加】なりすまし中の警告バー用CSS */
        .impersonation-alert {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border-radius: 0.25rem;
            text-align: center;
            font-weight: bold;
        }

        .impersonation-alert .btn {
            margin-top: 0.5rem;
        }
        
        .teiki-section {
            background-color: #e8f5e8;
            border: 1px solid #c3e6c3;
            border-radius: 0.375rem;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .kyuyo-genba-section {
            background-color: #e8f4f8;
            border: 1px solid #b8e6f0;
            border-radius: 0.375rem;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .genkin-section {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 0.375rem;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .total-section {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 15px;
            margin-top: 20px;
        }
        
        .section-title {
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 15px;
            color: #495057;
        }
        
        .btn-delete {
            font-size: 0.8rem;
            padding: 2px 8px;
        }
        
        .fare-table {
            font-size: 0.9rem;
        }
        
        .fare-table td,
        .fare-table th {
            padding: 0.5rem 0.3rem;
            vertical-align: middle;
        }
        
        .btn-edit {
            font-size: 0.8rem;
            padding: 2px 8px;
            margin-right: 2px;
        }
    </style>

</head>

<body class="text-center bg-light">

    <!-- ★【なりすまし機能追加】なりすまし中の警告表示 -->
    <?php if ($is_impersonating): ?>
    <div class="impersonation-alert">
        <i class="fas fa-user-secret"></i> 
        管理者として「<?php echo h($impersonate_target_name); ?>」の交通費を代理入力中です
        <div>
            <a href="<?php echo SITE_URL ?>admin/return_from_impersonation.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> 社員一覧に戻る
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- 一括登録結果表示 -->
    <?php if (isset($_SESSION['FARE_BULK_UPLOAD_RESULT'])) : ?>
        <?php $result = $_SESSION['FARE_BULK_UPLOAD_RESULT']; ?>
        <div class="alert alert-<?php echo $result['error_count'] > 0 ? 'warning' : 'success'; ?> alert-dismissible fade show" role="alert">
            <strong>交通費一括登録結果:</strong> 
            処理対象 <?php echo isset($result['total_lines']) ? $result['total_lines'] : '0'; ?>行、
            成功 <?php echo $result['success_count']; ?>件、
            エラー <?php echo $result['error_count']; ?>件
            
            <?php if (!empty($result['errors'])) : ?>
                <hr>
                <strong>エラー詳細:</strong>
                <div style="max-height: 200px; overflow-y: auto;">
                    <?php foreach (array_slice($result['errors'], 0, 10) as $error) : ?>
                        <div><small><?php echo h($error); ?></small></div>
                    <?php endforeach; ?>
                    <?php if (count($result['errors']) > 10) : ?>
                        <div><small>...他<?php echo count($result['errors']) - 10; ?>件のエラーがあります</small></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php unset($_SESSION['FARE_BULK_UPLOAD_RESULT']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['FARE_BULK_UPLOAD_ERROR'])) : ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>アップロードエラー:</strong> <?php echo h($_SESSION['FARE_BULK_UPLOAD_ERROR']); ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php unset($_SESSION['FARE_BULK_UPLOAD_ERROR']); ?>
    <?php endif; ?>

    <!-- ユーザー情報表示 -->
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="text-left pt-3 pl-3">
                    <small class="text-muted">
                        ユーザー: <?php echo isset($session_user['user_no']) ? h($session_user['user_no']) : '未設定'; ?> - 
                        <?php echo isset($session_user['name']) ? h($session_user['name']) : '名前未設定'; ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <form class="border rounded bg-white form-time-table">

        <h1 class="h3 my-3">交通費入力</h1>
        <div class="float-right">
            <a class="btn btn-primary mr-2" href="index.php?m=<?php echo urlencode($yyyymm) ?>">
                <i class="fas fa-calendar-alt"></i> 勤務表へ戻る
            </a>
            <a class="btn btn-success mr-2" href="fare_export_csv.php?m=<?php echo urlencode($yyyymm) ?>">
                <i class="fas fa-file-csv"></i> CSV出力(社内用)
            </a>
            <a style="padding-right:8px" class="btn btn-info mr-2" href="fare_export_csv_field.php?m=<?php echo urlencode($yyyymm) ?>">
	        <i class="fas fa-file-csv"></i> CSV出力(現場用)
            </a>
			
            <!-- ★【編集権限チェック追加】編集可能な月のみ一括登録ボタンを表示 -->
            <?php if (canEditMonth($yyyymm, $session_user)): ?>
            <button type="button" class="btn btn-secondary mr-2" data-toggle="modal" data-target="#fareBulkUploadModal">
                <i class="fas fa-upload"></i> 交通費一括登録
            </button>
            <?php endif; ?>
            <!-- ★【なりすまし機能追加】なりすまし中はログアウトボタンを非表示 -->
            <?php if (!$is_impersonating): ?>
            <a href="<?php echo SITE_URL ?>logout.php" class="btn btn-outline-danger">
                <i class="fas fa-sign-out-alt"></i> ログアウト
            </a>
            <?php endif; ?>
        </div>

        <select class="form-control rounded-pill mb-3" name="m" onchange="submit(this.form)">
            <option value="<?php echo date('Y-m') ?>"><?php echo date('Y/m') ?></option>
            <?php for ($i = 1; $i < 12; $i++) : ?>
                <?php $target_yyyymm = strtotime("-{$i}months"); ?>
                <option value="<?php echo date('Y-m', $target_yyyymm) ?>" <?php if ($yyyymm == date('Y-m', $target_yyyymm)) echo 'selected' ?>><?php echo date('Y/m', $target_yyyymm) ?></option>
            <?php endfor; ?>
        </select>

        <!-- ★【編集権限チェック追加】編集可能な月のみ登録ボタンを表示 -->
        <?php if (canEditMonth($yyyymm, $session_user)): ?>
        <!-- 登録ボタン -->
        <div class="text-center mb-4">
            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#fareModal">
                <i class="fas fa-plus"></i> 交通費登録
            </button>
        </div>
        <?php endif; ?>

        <!-- 定期 -->
        <div class="teiki-section">
            <div class="section-title">
                <i class="fas fa-credit-card"></i> 定期
            </div>
            
            <?php if (!empty($teiki_list)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered fare-table">
                        <thead class="thead-light">
                            <tr>
                                <th>月/日</th>
                                <th>区間</th>
                                <th>備考</th>
                                <th>金額</th>
                                <th>月額</th>
                                <!-- ★【編集権限チェック追加】編集可能な月のみ操作列を表示 -->
                                <?php if (canEditMonth($yyyymm, $session_user)): ?>
                                <th></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="teiki-tbody">
                            <?php 
                            $teiki_total = 0;
                            foreach ($teiki_list as $teiki): 
                                $teiki_total += $teiki['total_cost'];
                            ?>
                            <tr>
                                <td><?php echo date('n/j', strtotime($teiki['date'])); ?></td>
                                <td><?php 
                                    $arrow = ($teiki['oufuku_f'] == 1) ? ' ⇔ ' : ' ⇒ ';
                                    echo h($teiki['from_name']) . $arrow . h($teiki['to_name']); 
                                ?></td>
                                <td><?php echo h($teiki['bikou']); ?></td>
                                <td>¥<?php echo number_format($teiki['cost']); ?></td>
                                <td>¥<?php echo number_format($teiki['total_cost']); ?></td>
                                <!-- ★【編集権限チェック追加】編集可能な月のみ操作ボタンを表示 -->
                                <?php if (canEditMonth($yyyymm, $session_user)): ?>
                                <td>
                                    <button type="button" class="btn btn-primary btn-edit" 
                                            data-toggle="modal" data-target="#fareModal" 
                                            data-id="<?php echo $teiki['id']; ?>"
                                            data-expense_type="<?php echo $teiki['t_flag']; ?>"
                                            data-date="<?php echo $teiki['date']; ?>"
                                            data-from="<?php echo h($teiki['from_name']); ?>"
                                            data-to="<?php echo h($teiki['to_name']); ?>"
                                            data-cost="<?php echo $teiki['cost']; ?>"
                                            data-bikou="<?php echo h($teiki['bikou']); ?>"
                                            data-round_trip="<?php echo $teiki['oufuku_f'] ?? 0; ?>">
                                        <i class="fa-solid fa-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-danger btn-delete" 
                                            data-id="<?php echo $teiki['id']; ?>">削除</button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted">定期のデータはありません。</p>
            <?php endif; ?>
        </div>

        <!-- 社内経費（給与合算）・現場請求 -->
        <div class="kyuyo-genba-section">
            <div class="section-title">
                <i class="fas fa-train"></i> 社内経費（給与合算）・現場請求
            </div>
            
            <?php if (!empty($kyuyo_genba_list)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered fare-table">
                        <thead class="thead-light">
                            <tr>
                                <th>月/日</th>
                                <th>区分</th>
                                <th>区間</th>
                                <th>備考</th>
                                <th>金額</th>
                                <th>金額(自動計算)</th>
                                <!-- ★【編集権限チェック追加】編集可能な月のみ操作列を表示 -->
                                <?php if (canEditMonth($yyyymm, $session_user)): ?>
                                <th></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="kyuyo-genba-tbody">
                            <?php 
                            $kyuyo_genba_total = 0;
                            foreach ($kyuyo_genba_list as $kyuyo_genba): 
                                $kyuyo_genba_total += $kyuyo_genba['total_cost'];
                            ?>
                            <tr>
                                <td><?php echo date('n/j', strtotime($kyuyo_genba['date'])); ?></td>
                                <td><?php echo h(getExpenseTypeName($kyuyo_genba['t_flag'])); ?></td>
                                <td><?php 
                                    $arrow = ($kyuyo_genba['oufuku_f'] == 1) ? ' ⇔ ' : ' ⇒ ';
                                    echo h($kyuyo_genba['from_name']) . $arrow . h($kyuyo_genba['to_name']); 
                                ?></td>
                                <td><?php echo h($kyuyo_genba['bikou']); ?></td>
                                <td>¥<?php echo number_format($kyuyo_genba['cost']); ?></td>
                                <td>¥<?php echo number_format($kyuyo_genba['total_cost']); ?></td>
                                <!-- ★【編集権限チェック追加】編集可能な月のみ操作ボタンを表示 -->
                                <?php if (canEditMonth($yyyymm, $session_user)): ?>
                                <td>
                                    <button type="button" class="btn btn-primary btn-edit" 
                                            data-toggle="modal" data-target="#fareModal" 
                                            data-id="<?php echo $kyuyo_genba['id']; ?>"
                                            data-expense_type="<?php echo $kyuyo_genba['t_flag']; ?>"
                                            data-date="<?php echo $kyuyo_genba['date']; ?>"
                                            data-from="<?php echo h($kyuyo_genba['from_name']); ?>"
                                            data-to="<?php echo h($kyuyo_genba['to_name']); ?>"
                                            data-cost="<?php echo $kyuyo_genba['cost']; ?>"
                                            data-bikou="<?php echo h($kyuyo_genba['bikou']); ?>"
                                            data-round_trip="<?php echo $kyuyo_genba['oufuku_f'] ?? 0; ?>">
                                        <i class="fa-solid fa-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-danger btn-delete" 
                                            data-id="<?php echo $kyuyo_genba['id']; ?>">削除</button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted">社内経費（給与合算）・現場請求のデータはありません。</p>
            <?php endif; ?>
        </div>

        <!-- 社内経費（現金精算） -->
        <div class="genkin-section">
            <div class="section-title">
                <i class="fas fa-money-bill-wave"></i> 社内経費（現金精算）
            </div>
            
            <?php if (!empty($genkin_list)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered fare-table">
                        <thead class="thead-light">
                            <tr>
                                <th>月/日</th>
                                <th>区間</th>
                                <th>備考</th>
                                <th>金額</th>
                                <th>金額(自動計算)</th>
                                <!-- ★【編集権限チェック追加】編集可能な月のみ操作列を表示 -->
                                <?php if (canEditMonth($yyyymm, $session_user)): ?>
                                <th></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="genkin-tbody">
                            <?php 
                            $genkin_total = 0;
                            foreach ($genkin_list as $genkin): 
                                $genkin_total += $genkin['total_cost'];
                            ?>
                            <tr>
                                <td><?php echo date('n/j', strtotime($genkin['date'])); ?></td>
                                <td><?php 
                                    $arrow = ($genkin['oufuku_f'] == 1) ? ' ⇔ ' : ' ⇒ ';
                                    echo h($genkin['from_name']) . $arrow . h($genkin['to_name']); 
                                ?></td>
                                <td><?php echo h($genkin['bikou']); ?></td>
                                <td>¥<?php echo number_format($genkin['cost']); ?></td>
                                <td>¥<?php echo number_format($genkin['total_cost']); ?></td>
                                <!-- ★【編集権限チェック追加】編集可能な月のみ操作ボタンを表示 -->
                                <?php if (canEditMonth($yyyymm, $session_user)): ?>
                                <td>
                                    <button type="button" class="btn btn-primary btn-edit" 
                                            data-toggle="modal" data-target="#fareModal" 
                                            data-id="<?php echo $genkin['id']; ?>"
                                            data-expense_type="<?php echo $genkin['t_flag']; ?>"
                                            data-date="<?php echo $genkin['date']; ?>"
                                            data-from="<?php echo h($genkin['from_name']); ?>"
                                            data-to="<?php echo h($genkin['to_name']); ?>"
                                            data-cost="<?php echo $genkin['cost']; ?>"
                                            data-bikou="<?php echo h($genkin['bikou']); ?>"
                                            data-round_trip="<?php echo $genkin['oufuku_f'] ?? 0; ?>">
                                        <i class="fa-solid fa-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-danger btn-delete" 
                                            data-id="<?php echo $genkin['id']; ?>">削除</button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted">社内経費（現金精算）のデータはありません。</p>
            <?php endif; ?>
        </div>

        <!-- 合計 -->
        <div class="total-section">
            <div class="section-title">
                <i class="fas fa-calculator"></i> 合計
            </div>
            <div class="row">
                <div class="col-md-2">
                    <p>定期合計: ¥<?php echo number_format($teiki_total ?? 0); ?></p>
                </div>
                <div class="col-md-3">
                    <p>社内経費(給与合算)/<br/>現場請求合計: ¥<?php echo number_format($kyuyo_genba_total ?? 0); ?></p>
                </div>
                <div class="col-md-2">
                    <strong>給与振込額: ¥<?php echo number_format(($teiki_total ?? 0) + ($kyuyo_genba_total ?? 0)); ?></strong>
                </div>
                <div class="col-md-3">
                    <p>社内経費(現金精算)合計: ¥<?php echo number_format($genkin_total ?? 0); ?></p>
                </div>
                <div class="col-md-2">
                    <strong>総合計: ¥<?php echo number_format(($teiki_total ?? 0) + ($kyuyo_genba_total ?? 0) + ($genkin_total ?? 0)); ?></strong>
                </div>
            </div>
        </div>

    </form>

    <!-- ★【編集権限チェック追加】編集可能な月のみモーダルを表示 -->
    <?php if (canEditMonth($yyyymm, $session_user)): ?>
    <!-- 交通費登録Modal -->
    <form method="post" id="fare-form">
        <div class="modal fade" id="fareModal" tabindex="-1" aria-labelledby="fareModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="fareModalLabel">交通費登録</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="container">
                            <div class="form-group">
                                <label for="modal_expense_type" class="form-label">経費区分</label>
                                <select class="form-control" id="modal_expense_type" name="modal_expense_type">
                                    <?php
                                    $expense_type_options = getExpenseTypeOptions();
                                    foreach ($expense_type_options as $value => $text) {
                                        $selected = ($value == $modal_expense_type) ? ' selected' : '';
                                        echo '<option value="' . $value . '"' . $selected . '>' . h($text) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="modal_date" class="form-label">日付</label>
                                <input type="date" class="form-control <?php if (isset($err['modal_date'])) echo 'is-invalid'; ?>" id="modal_date" name="modal_date" value="<?php echo $modal_date; ?>" required>
                                <div class="invalid-feedback"><?php echo $err['modal_date'] ?? ''; ?></div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="modal_from_name" class="form-label">出発駅</label>
                                        <input type="text" class="form-control <?php if (isset($err['modal_from_name'])) echo 'is-invalid'; ?>" id="modal_from_name" name="modal_from_name" value="<?php echo h($modal_from_name); ?>" placeholder="出発駅名" required>
                                        <div class="invalid-feedback"><?php echo $err['modal_from_name'] ?? ''; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="modal_to_name" class="form-label">到着駅</label>
                                        <input type="text" class="form-control <?php if (isset($err['modal_to_name'])) echo 'is-invalid'; ?>" id="modal_to_name" name="modal_to_name" value="<?php echo h($modal_to_name); ?>" placeholder="到着駅名" required>
                                        <div class="invalid-feedback"><?php echo $err['modal_to_name'] ?? ''; ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="modal_cost" class="form-label">金額</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">¥</span>
                                    </div>
                                    <input type="number" class="form-control text-right <?php if (isset($err['modal_cost'])) echo 'is-invalid'; ?>" id="modal_cost" name="modal_cost" value="<?php echo h($modal_cost); ?>" placeholder="0" min="0" max="9999999" required>
                                    <div class="invalid-feedback"><?php echo $err['modal_cost'] ?? ''; ?></div>
                                </div>
                                <small class="form-text text-muted" id="cost-help">定期の場合：月額、それ以外の場合：片道</small>
                            </div>

                            <div class="form-group" id="round-trip-section">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="modal_round_trip" name="modal_round_trip" <?php echo $modal_round_trip ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="modal_round_trip">
                                        往復で計算する（金額を2倍にする）
                                    </label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="modal_bikou" class="form-label">備考</label>
                                <textarea class="form-control <?php if (isset($err['modal_bikou'])) echo 'is-invalid'; ?>" id="modal_bikou" name="modal_bikou" rows="3" placeholder="案件先出社、帰社等"><?php echo h($modal_bikou); ?></textarea>
                                <div class="invalid-feedback"><?php echo $err['modal_bikou'] ?? ''; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-success">登録</button>
                    </div>
                </div>
            </div>
        </div>
        <input type="hidden" name="action" value="register">
        <input type="hidden" id="edit_id" name="edit_id" value="">
        <input type="hidden" name="CSRF_TOKEN" value="<?php echo $_SESSION['CSRF_TOKEN'] ?>">
    </form>

    <!-- 削除用の隠しフォーム -->
    <form method="post" id="delete-form" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="delete_id" id="delete_id" value="">
        <input type="hidden" name="CSRF_TOKEN" value="<?php echo $_SESSION['CSRF_TOKEN'] ?>">
    </form>

    <!-- 交通費一括登録モーダル -->
    <form method="post" action="fare_bulk_upload.php" enctype="multipart/form-data">
        <div class="modal fade" id="fareBulkUploadModal" tabindex="-1" role="dialog" aria-labelledby="fareBulkUploadModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="fareBulkUploadModalLabel">
                            <i class="fas fa-upload"></i> 交通費一括登録
                        </h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <div class="alert alert-info">
                                <small>
                                    <strong>対応フォーマット:</strong> CSV、TXT<br>
                                    <strong>項目順序:</strong> 経費フラグ,移動日,出発,到着,金額,備考,往復フラグ<br>
                                    <strong>経費フラグ:</strong> 0=社内経費(給与合算), 1=定期, 2=社内経費(現金精算), 3=現場請求<br>
                                    <strong>往復フラグ:</strong> 0=片道, 1=往復<br>
                                    <strong>日付形式:</strong> YYYY-MM-DD または MM/dd<br>
                                    1行目のヘッダーは自動的にスキップされます<br>
                                    <strong>注意:</strong> 既存データに追加されます
                                </small>
                            </div>
                            
                            <!-- ファイルアップロード -->
                            <div class="form-group">
                                <label for="bulk_file">ファイルをアップロード（テキストファイル .txt/.csv）</label>
                                <input type="file" class="form-control-file" id="bulk_file" name="bulk_file" accept=".txt,.csv">
                                <small class="form-text text-muted">最大1MBまで。テキストファイル（.txt, .csv）のみ対応</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-warning">一括登録実行</button>
                    </div>
                </div>
            </div>
        </div>
        <input type="hidden" name="CSRF_TOKEN" value="<?php echo $_SESSION['CSRF_TOKEN'] ?>">
    </form>
    <?php endif; ?>

    <script src="//code.jquery.com/jquery.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/works.js"></script>

    <script>
        <?php if ($modal_view_fig) : ?>
            $(document).ready(function() {
                $('#fareModal').modal('show');
            });
        <?php endif ?>

        // 削除ボタンクリック時の処理
        $(document).on('click', '.btn-delete', function() {
            var id = $(this).data('id');
            
            if (confirm('この交通費データを削除しますか？')) {
                $('#delete_id').val(id);
                $('#delete-form').submit();
            }
        });

        // 編集ボタンクリック時の処理
        $(document).on('click', '.btn-edit', function() {
            var id = $(this).data('id');
            var expense_type = $(this).data('expense_type');
            var date = $(this).data('date');
            var from = $(this).data('from');
            var to = $(this).data('to');
            var cost = $(this).data('cost');
            var bikou = $(this).data('bikou');
            var round_trip = $(this).data('round_trip');

            $('#edit_id').val(id);
            $('#modal_expense_type').val(expense_type);
            $('#modal_date').val(date);
            $('#modal_from_name').val(from);
            $('#modal_to_name').val(to);
            $('#modal_cost').val(cost);
            $('#modal_bikou').val(bikou);
            $('#modal_round_trip').prop('checked', round_trip == 1);

            // 経費区分に応じてUIを更新
            updateExpenseTypeUI();
        });

        // 経費区分変更時の処理
        $('#modal_expense_type').change(function() {
            updateExpenseTypeUI();
        });

        // 経費区分に応じたUI更新関数
        function updateExpenseTypeUI() {
            var expenseType = $('#modal_expense_type').val();
            
            if (expenseType == '1') {
                // 定期の場合
                $('#cost-help').text('定期の場合：月額');
                $('#round-trip-section').hide();
                $('#modal_round_trip').prop('checked', false);
            } else {
                // それ以外の場合
                $('#cost-help').text('それ以外の場合：片道');
                $('#round-trip-section').show();
            }
        }

        // モーダルが開かれたときに初期化
        $('#fareModal').on('show.bs.modal', function() {
            updateExpenseTypeUI();
        });

        // 新規登録時の初期化
        $('#fareModal').on('hidden.bs.modal', function() {
            $('#edit_id').val('');
            $('#modal_expense_type').val('0');
            $('#modal_date').val('<?php echo date('Y-m-d'); ?>');
            $('#modal_from_name').val('');
            $('#modal_to_name').val('');
            $('#modal_cost').val('');
            $('#modal_bikou').val('');
            $('#modal_round_trip').prop('checked', false);
            updateExpenseTypeUI();
        });

        // 交通費一括登録フォーム送信時の処理
        $('form[action="fare_bulk_upload.php"]').on('submit', function(e) {
            var fileInput = $('#bulk_file')[0];
            
            // ファイルが選択されているかチェック
            if (!fileInput.files || fileInput.files.length === 0) {
                alert('ファイルを選択してください。');
                e.preventDefault();
                return false;
            }
            
            // ファイルが選択されている場合のバリデーション
            var file = fileInput.files[0];
            var fileSize = file.size;
            var fileName = file.name;
            var fileExtension = fileName.split('.').pop().toLowerCase();
            
            // ファイルサイズチェック（1MB = 1048576 bytes）
            if (fileSize > 1048576) {
                alert('ファイルサイズが大きすぎます。1MB以下のファイルを選択してください。');
                e.preventDefault();
                return false;
            }
            
            // ファイル拡張子チェック
            if (fileExtension !== 'txt' && fileExtension !== 'csv') {
                alert('テキストファイル（.txt または .csv）のみアップロード可能です。');
                e.preventDefault();
                return false;
            }
            
            // 確認ダイアログ
            if (!confirm('交通費データを一括登録しますか？\n※既存データに追加されます。')) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    </script>

</body>

</html>