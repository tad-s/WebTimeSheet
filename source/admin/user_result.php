<?php
require_once('../../config/config.php');
require_once('../functions.php');

// 追加のヘルパー関数
if (!function_exists('getWorkTypeName_local')) {
    function getWorkTypeName_local($work_type, $options) {
        return isset($options[$work_type]) ? $options[$work_type] : '';
    }
}

if (!function_exists('format_time_local')) {
    function format_time_local($time) {
        if (!$time || $time == '00:00:00') return '';
        $parts = explode(':', $time);
        if (count($parts) >= 2) {
            return sprintf('%d:%02d', (int)$parts[0], (int)$parts[1]);
        }
        return $time;
    }
}

if (!function_exists('minutesToTime_local')) {
    function minutesToTime_local($minutes) {
        if ($minutes <= 0) return '';
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%d:%02d', $hours, $mins);
    }
}

if (!function_exists('calculateWorkMinutes_local')) {
    function calculateWorkMinutes_local($start_time, $end_time, $break_time) {
        if (!$start_time || !$end_time) return 0;

        $start_parts = explode(':', $start_time);
        $end_parts   = explode(':', $end_time);
        $break_parts = $break_time ? explode(':', $break_time) : ['0', '0'];

        $start_minutes = (int)$start_parts[0] * 60 + (int)$start_parts[1];
        $end_minutes   = (int)$end_parts[0]   * 60 + (int)$end_parts[1];
        $break_minutes = (int)$break_parts[0]  * 60 + (int)$break_parts[1];

        // 退勤が出勤以下の場合は翌日扱い（24時間超の勤務対応）
        if ($end_minutes <= $start_minutes) {
            $end_minutes += 24 * 60;
        }

        $work_minutes = $end_minutes - $start_minutes - $break_minutes;
        return $work_minutes > 0 ? $work_minutes : 0;
    }
}

if (!function_exists('time_format_dw_local')) {
    function time_format_dw_local($date) {
        $date_obj = new DateTime($date);
        $day_of_week = ['日', '月', '火', '水', '木', '金', '土'];
        $dow = $day_of_week[$date_obj->format('w')];
        return $date_obj->format('j') . '(' . $dow . ')';
    }
}

try {
    //ログイン状態をチェック
    session_start();

    if (!isset($_SESSION['USER']) || $_SESSION['USER']['auth_type'] != 1) {
        //ログインされていない場合はログイン画面へ
        header('Location:' . SITE_URL . 'admin/login.php');
        exit;
    }

    //対象ユーザーのIDをパラメーターから取得
    $user_id = $_REQUEST['id'];

    if (!$user_id) {
        throw new Exception('ユーザーIDが不正', 500);
    }

    $pdo = connectDb();

    // 対象ユーザー情報を取得
    $sql = "SELECT user_no, name FROM user WHERE id = :user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', (int)$user_id, PDO::PARAM_INT);
    $stmt->execute();
    $target_user = $stmt->fetch();

    if (!$target_user) {
        throw new Exception('ユーザーが見つかりません', 404);
    }

    // CSV出力処理
    if (isset($_GET['csv']) && $_GET['csv'] == 1) {
        // 月の指定を取得
        $yyyymm = isset($_GET['m']) ? $_GET['m'] : date('Y-m');
        $day_count = date('t', strtotime($yyyymm));

        // 日付の妥当性チェック
        if (count(explode('-', $yyyymm)) != 2) {
            throw new Exception('日付の指定が不正', 500);
        }

        // 今月～過去12か月の範囲内かどうか
        $check_date = new DateTime($yyyymm . '-01');
        $start_date = new DateTime('first day of -11 month 00:00');
        $end_date = new DateTime('first day of this month 00:00');

        if ($check_date < $start_date || $end_date < $check_date) {
            throw new Exception('日付の範囲が不正', 500);
        }

        // 勤務データを取得（現場勤務関連カラムも含む）
        $sql = "SELECT date, id, start_time, end_time, break_time, work_type, comment, g_work_type, g_start, g_end, g_break, g_com FROM work WHERE user_id = :user_id AND DATE_FORMAT(date,'%Y-%m') = :date";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', (int)$user_id, PDO::PARAM_INT);
        $stmt->bindValue(':date', $yyyymm, PDO::PARAM_STR);
        $stmt->execute();
        $work_list = $stmt->fetchAll(PDO::FETCH_UNIQUE);

        // ファイル名を生成（user_no_name_yyyy-mm.csv形式）
        $user_no = isset($target_user['user_no']) ? $target_user['user_no'] : 'unknown';
        $user_name = isset($target_user['name']) ? $target_user['name'] : 'noname';
        
        // ファイル名に使用できない文字を除去
        $safe_user_no = preg_replace('/[^a-zA-Z0-9\-_]/', '', $user_no);
        $safe_user_name = preg_replace('/[^\p{L}\p{N}\-_]/u', '', $user_name);
        
        // 空の場合のフォールバック
        if (empty($safe_user_no)) $safe_user_no = 'user';
        if (empty($safe_user_name)) $safe_user_name = 'name';
        
        $filename = $safe_user_no . '_' . $safe_user_name . '_' . $yyyymm . '.csv';

        // CSVダウンロード用のヘッダーを設定
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

        // BOM を出力（Excelで文字化けを防ぐため）
        echo "\xEF\xBB\xBF";

        // CSVデータを出力
        $output = fopen('php://output', 'w');

        // ヘッダー情報
        fputcsv($output, ['Web勤怠管理システム']);
        fputcsv($output, ['社員番号', $user_no]);
        fputcsv($output, ['氏名', $user_name]);
        fputcsv($output, ['対象月', date('Y年m月', strtotime($yyyymm))]);
        fputcsv($output, []); // 空行

        // テーブルヘッダー（現場勤務項目を追加）
        fputcsv($output, [
            '日', 
            '区分', 
            '出勤', 
            '退勤', 
            '休憩', 
            '勤務時間', 
            '備考',
            '現場区分',
            '現場出勤',
            '現場退勤', 
            '現場休憩',
            '現場勤務時間',
            '現場コメント'
        ]);

        // 勤務区分の選択肢を定義
        $work_type_options = array(
            1 => '通常',
            2 => '有休(全日)',
            3 => '有休(午前)',
            4 => '有休(午後)',
            5 => '欠勤',
            6 => '遅刻',
            7 => '早退',
            8 => '公休',
            9 => '帰社日'
        );

        $totalWorkMinutes = 0;
        $totalFieldWorkMinutes = 0;
        $workDays = 0;
        $fieldWorkDays = 0;
        $work_type_counts = array();
        $field_work_type_counts = array();
        
        // 区分別カウント用の配列を初期化
        foreach ($work_type_options as $key => $value) {
            $work_type_counts[$key] = 0;
            $field_work_type_counts[$key] = 0;
        }

        // データ行を出力
        for ($i = 1; $i <= $day_count; $i++) {
            $currentDate = $yyyymm . '-' . sprintf('%02d', $i);
            
            $start_time = '';
            $end_time = '';
            $break_time = '';
            $work_type = '';
            $comment = '';
            $work_time = '';
            
            // 現場勤務関連の変数
            $g_work_type = '';
            $g_start = '';
            $g_end = '';
            $g_break = '';
            $g_com = '';
            $g_work_time = '';

            if (isset($work_list[$currentDate])) {
                $work = $work_list[$currentDate];

                if ($work['start_time'] && $work['start_time'] != '00:00:00') {
                    $start_time = format_time_local($work['start_time']);
                }

                if ($work['end_time'] && $work['end_time'] != '00:00:00') {
                    $end_time = format_time_local($work['end_time']);
                }

                if ($work['break_time'] && $work['break_time'] != '00:00:00') {
                    $break_time = format_time_local($work['break_time']);
                }

                if ($work['work_type']) {
                    $work_type = getWorkTypeName_local($work['work_type'], $work_type_options);
                    if (isset($work_type_counts[$work['work_type']])) {
                        $work_type_counts[$work['work_type']]++;
                    }
                }

                if ($work['comment']) {
                    $comment = $work['comment'];
                }

                // 現場勤務関連データの処理
                
                // 現場勤務区分の処理
                if ($work['g_work_type']) {
                    $g_work_type = getWorkTypeName_local($work['g_work_type'], $work_type_options);
                    $g_work_type_value = $work['g_work_type'];
                } else {
                    // 現場勤務区分がない場合は社内勤務区分を使用
                    $g_work_type = $work_type;
                    $g_work_type_value = $work['work_type'];
                }
                
                if (isset($field_work_type_counts[$g_work_type_value])) {
                    $field_work_type_counts[$g_work_type_value]++;
                }

                // 現場出勤時間の処理
                if ($work['g_start'] !== null && $work['g_start'] !== '') {
                    if ($work['g_start'] == '00:00:00') {
                        $g_start = '00:00';
                    } else {
                        $g_start = format_time_local($work['g_start']);
                    }
                } else {
                    // 現場出勤時間がない場合は社内出勤時間を使用
                    $g_start = $start_time;
                }

                // 現場退勤時間の処理
                if ($work['g_end'] !== null && $work['g_end'] !== '') {
                    if ($work['g_end'] == '00:00:00') {
                        $g_end = '00:00';
                    } else {
                        $g_end = format_time_local($work['g_end']);
                    }
                } else {
                    // 現場退勤時間がない場合は社内退勤時間を使用
                    $g_end = $end_time;
                }

                // 現場休憩時間の処理
                if ($work['g_break'] !== null && $work['g_break'] !== '') {
                    if ($work['g_break'] == '00:00:00') {
                        $g_break = '00:00';
                    } else {
                        $g_break = format_time_local($work['g_break']);
                    }
                } else {
                    // 現場休憩時間がない場合は社内休憩時間を使用
                    $g_break = $break_time;
                }

                // 現場コメントの処理
                if ($work['g_com']) {
                    $g_com = $work['g_com'];
                } else {
                    // 現場コメントがない場合は社内備考を使用
                    $g_com = $comment;
                }

                // 勤務時間を計算
                if ($start_time && $end_time) {
                    $work_minutes = calculateWorkMinutes_local($start_time, $end_time, $break_time);
                    if ($work_minutes > 0) {
                        $work_time = minutesToTime_local($work_minutes);
                        $totalWorkMinutes += $work_minutes;
                        $workDays++;
                    }
                }

                // 現場勤務時間を計算
                if ($g_start && $g_end) {
                    $g_work_minutes = calculateWorkMinutes_local($g_start, $g_end, $g_break);
                    if ($g_work_minutes > 0) {
                        $g_work_time = minutesToTime_local($g_work_minutes);
                        $totalFieldWorkMinutes += $g_work_minutes;
                        $fieldWorkDays++;
                    }
                }
            }

            fputcsv($output, [
                time_format_dw_local($currentDate),
                $work_type,
                $start_time,
                $end_time,
                $break_time,
                $work_time,
                $comment,
                $g_work_type,
                $g_start,
                $g_end,
                $g_break,
                $g_work_time,
                $g_com
            ]);
        }

        // 合計行
        fputcsv($output, []); // 空行
        fputcsv($output, [
            '合計',
            '総勤務日数: ' . $workDays . '日',
            '',
            '',
            '',
            $totalWorkMinutes > 0 ? minutesToTime_local($totalWorkMinutes) : '0:00',
            '',
            '現場勤務日数: ' . $fieldWorkDays . '日',
            '',
            '',
            '',
            $totalFieldWorkMinutes > 0 ? minutesToTime_local($totalFieldWorkMinutes) : '0:00',
            ''
        ]);
        
        // 区分別集計
        fputcsv($output, []); // 空行
        fputcsv($output, ['区分別集計（通常勤務）']);
        foreach ($work_type_options as $key => $value) {
            if (isset($work_type_counts[$key])) {
                fputcsv($output, [$value, $work_type_counts[$key] . '日']);
            } else {
                fputcsv($output, [$value, '0日']);
            }
        }

        // 現場勤務区分別集計
        fputcsv($output, []); // 空行
        fputcsv($output, ['区分別集計（現場勤務）']);
        foreach ($work_type_options as $key => $value) {
            if (isset($field_work_type_counts[$key])) {
                fputcsv($output, [$value, $field_work_type_counts[$key] . '日']);
            } else {
                fputcsv($output, [$value, '0日']);
            }
        }

        fclose($output);
        exit;
    }

    $err = array();

    $target_date = '';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        //日報登録処理

        check_token();
        //入力値をPOSTパラメーターから取得
        $target_date = $_POST['target_date'];
        $modal_start_time = $_POST['modal_start_time'];
        $modal_end_time = $_POST['modal_end_time'];
        $modal_break_time = $_POST['modal_break_time'];
        $modal_comment = $_POST['modal_comment'];

        //出勤時間の必須、形式チェック
        if (!$modal_start_time) {
            $err['modal_start_time'] = '出勤時間を入力して下さい';
        } elseif (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $modal_start_time)) {
            $modal_start_time = '';
            $err['modal_start_time'] = '出勤時間を正しく入力して下さい';
        }

        //退勤時間の形式チェック

        if ($modal_end_time != '' && !preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $modal_end_time)) {
            $modal_end_time = '';
            $err['modal_end_time'] = '退勤時間を正しく入力して下さい';
        }


        //休息時間の形式チェック
        if ($modal_break_time != '' && !preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $modal_break_time)) {
            $modal_break_time = '';
            $err['modal_break_time'] = '休息時間を正しく入力して下さい';
        }
        if ($modal_break_time == '') {
            $modal_break_time = NULL;
        }

        //備考の最大サイズチェック
        if (mb_strlen($modal_comment, 'utf-8') > 2000) {
            $err['modal_comment'] = '備考が長すぎます。';
        }

        if (empty($err)) {

            //対象日のデータがあるかどうかチェック
            $sql = "SELECT id FROM work WHERE user_id = :user_id AND date = :date LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', (int)$user_id, PDO::PARAM_INT);
            $stmt->bindValue(':date', $target_date, PDO::PARAM_STR);
            $stmt->execute();
            $work = $stmt->fetch();

            if ($work) {
                //対象日のデータがあればUPDATE
                $sql = "UPDATE work SET start_time = :start_time, end_time = :end_time, break_time = :break_time, comment = :comment WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':id', (int)$work['id'], PDO::PARAM_INT);
                $stmt->bindValue(':start_time', $modal_start_time, PDO::PARAM_STR);
                $stmt->bindValue(':end_time', $modal_end_time, PDO::PARAM_STR);
                $stmt->bindValue(':break_time', $modal_break_time, PDO::PARAM_STR);
                $stmt->bindValue(':comment', $modal_comment, PDO::PARAM_STR);
                $stmt->execute();
            } else {
                //対象日のデータがなければINSERT
                $sql = "INSERT INTO  work (user_id, date, start_time, end_time, break_time, comment) VALUES (:user_id, :date, :start_time, :end_time, :break_time, :comment)";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':user_id', (int)$user_id, PDO::PARAM_INT);
                $stmt->bindValue(':date', $target_date, PDO::PARAM_STR);
                $stmt->bindValue(':start_time', $modal_start_time, PDO::PARAM_STR);
                $stmt->bindValue(':end_time', $modal_end_time, PDO::PARAM_STR);
                $stmt->bindValue(':break_time', $modal_break_time, PDO::PARAM_STR);
                $stmt->bindValue(':comment', $modal_comment, PDO::PARAM_STR);
                $stmt->execute();
            }
        }
    } else {

        set_token();

        $modal_start_time = '';
        $modal_end_time = '';
        $modal_break_time = '01:00';
        $modal_comment = '';
    }
    //2.ユーザーの業務日報データを取得
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

    $sql = "SELECT date, id, start_time, end_time, break_time, work_type, comment FROM work WHERE user_id = :user_id AND DATE_FORMAT(date,'%Y-%m') = :date";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', (int)$user_id, PDO::PARAM_INT);
    $stmt->bindValue(':date', $yyyymm, PDO::PARAM_STR);
    $stmt->execute();
    $work_list = $stmt->fetchAll(PDO::FETCH_UNIQUE);
} catch (Exception $e) {
    // CSV出力中のエラーの場合は、エラーページではなくエラーメッセージを表示
    if (isset($_GET['csv']) && $_GET['csv'] == 1) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'CSV出力エラー: ' . $e->getMessage() . "\n";
        echo 'ファイル: ' . $e->getFile() . "\n";
        echo '行: ' . $e->getLine() . "\n";
        echo 'スタックトレース: ' . $e->getTraceAsString();
        exit;
    }
    header('Location: error.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">

    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.5.1/css/all.css">

    <title>日報登録 | works</title>

</head>

<body class="text-center bg-green">

    <form class="border rounded bg-white form-time-table">

        <h1 class="h3 my-3">月別勤怠表</h1>

        <!-- 月選択＋ハンバーガーボタン行 -->
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
            <select class="form-control rounded-pill mb-1" style="max-width:130px;" name="m" onchange="submit(this.form)">
                <option value="<?php echo date('Y-m') ?>"><?php echo date('Y/m') ?></option>
                <?php for ($i = 1; $i < 12; $i++) : ?>
                    <?php $target_yyyymm = strtotime("-{$i}months"); ?>
                    <option value="<?php echo date('Y-m', $target_yyyymm) ?>" <?php if ($yyyymm == date('Y-m', $target_yyyymm)) echo 'selected' ?>><?php echo date('Y/m', $target_yyyymm) ?></option>
                <?php endfor; ?>
            </select>
            <!-- ハンバーガーボタン（スマホのみ表示） -->
            <button type="button" class="btn btn-outline-secondary btn-sm d-md-none"
                    data-toggle="collapse" data-target="#adminActionMenu" aria-expanded="false" aria-controls="adminActionMenu">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        <!-- ボタン群（PC:常時表示／スマホ:ハンバーガーで折りたたみ） -->
        <div class="collapse d-md-flex justify-content-end align-items-center action-menu mb-2" id="adminActionMenu">
            <a href="user_result.php?id=<?php echo $user_id ?>&csv=1&m=<?php echo $yyyymm ?>" class="btn btn-success rounded-pill px-4 mr-2">
                <i class="fas fa-download"></i> CSV出力
            </a>
            <a href="fare_view.php?id=<?php echo $user_id ?>&m=<?php echo $yyyymm ?>" class="btn btn-info rounded-pill px-4 mr-2">
                <i class="fas fa-train"></i> 交通費確認
            </a>
            <a href="user_list.php" class="btn btn-secondary rounded-pill px-4">社員一覧に戻る</a>
        </div>



        <div class="table-responsive">
        <table class="table table-bordered mb-0">
            <thead>
                <tr class="bg-light">
                    <th class="fix-col">日</th>
                    <th class="fix-col">区分</th>
                    <th class="fix-col">出勤</th>
                    <th class="fix-col">退勤</th>
                    <th class="fix-col">休憩</th>
                    <th class="fix-col">勤務時間</th>
                    <th>備考</th>
                    <th class="fix-col"></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $total_work_minutes = 0;
                $total_work_days = 0;
                $work_type_options = getWorkTypeOptions();
                $work_type_counts = array();
                foreach ($work_type_options as $key => $value) {
                    $work_type_counts[$key] = 0;
                }
                ?>
                <?php for ($i = 1; $i <= $day_count; $i++) : ?>
                    <?php
                    $start_time = '';
                    $end_time = '';
                    $break_time = '';
                    $work_type = '';
                    $work_type_value = 0;
                    $work_time = '';
                    $comment = '';
                    $comment_long = '';

                    if (isset($work_list[date('Y-m-d', strtotime($yyyymm . '-' . $i))])) {
                        $work = $work_list[date('Y-m-d', strtotime($yyyymm . '-' . $i))];

                        if ($work['start_time']) {
                            $start_time = format_time_local($work['start_time']);
                        }

                        if ($work['end_time']) {
                            $end_time = format_time_local($work['end_time']);
                        }

                        if ($work['break_time']) {
                            $break_time = format_time_local($work['break_time']);
                        }

                        if ($work['work_type']) {
                            $work_type_value = (int)$work['work_type'];
                            $work_type = getWorkTypeName($work_type_value);
                            if (isset($work_type_counts[$work_type_value])) {
                                $work_type_counts[$work_type_value]++;
                            }
                        }

                        if ($start_time && $end_time) {
                            $work_minutes = calculateWorkMinutes_local($start_time, $end_time, $break_time);
                            if ($work_minutes > 0) {
                                $work_time = minutesToTime_local($work_minutes);
                                $total_work_minutes += $work_minutes;
                                $total_work_days++;
                            }
                        }

                        if ($work['comment']) {
                            $comment = mb_strimwidth($work['comment'], 0, 40, '…');
                            $comment_long = $work['comment'];
                        }
                    }

                    ?>
                    <tr>
                        <th scope="row"><?php echo time_format_dw($yyyymm . '-' . $i) ?></th>
                        <td><?php echo h($work_type) ?></td>
                        <td><?php echo $start_time ?></td>
                        <td><?php echo $end_time ?></td>
                        <td><?php echo $break_time ?></td>
                        <td><?php echo $work_time ?></td>
                        <td><?php echo h($comment) ?></td>
                        <td class="d-none"><?php echo h($comment_long) ?></td>
                        <td><button type="button" class="btn btn-default h-auto py-0" data-toggle="modal" data-target="#inputModal" data-day="<?php echo $yyyymm . '-' . sprintf('%02d', $i) ?>"><i class="fa-solid fa-pencil"></i></button></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        </div><!-- /.table-responsive -->

        <!-- 集計表 -->
        <div class="mt-4 p-3 bg-light border rounded">
            <h5 class="mb-3">勤務集計</h5>
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">勤務時間・日数</h6>
                            <p class="card-text">
                                <strong>総勤務時間:</strong> <?php echo $total_work_minutes > 0 ? minutesToTime_local($total_work_minutes) : '0:00' ?><br>
                                <strong>総勤務日数:</strong> <?php echo $total_work_days ?>日
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">区分別集計</h6>
                            <div class="row">
                                <?php foreach ($work_type_options as $key => $value) : ?>
                                    <div class="col-6 col-md-3 mb-2">
                                        <small><strong><?php echo h($value) ?>:</strong> <?php echo $work_type_counts[$key] ?>日</small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <input type="hidden" name="id" value="<?= $user_id ?>">
    </form>

    <form method="post">
        <!-- Modal -->
        <div class="modal fade" id="inputModal" tabindex="-1" aria-labelledby="inputModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <p></p>
                        <h5 class="modal-title" id="exampleModalLabel">日報登録</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="container">
                            <div class="alert alert-primary" role="alert">
                                <?php echo date('n', strtotime($yyyymm)) ?>/<span id="modal_day"><?php echo time_format_dw($target_date) ?></span>
                            </div>
                            <div class="row">
                                <div class="col-sm">
                                    <div class="input-group">
                                        <input type="text" class="form-control <?php if (isset($err['modal_start_time'])) echo 'is-invalid'; ?>" placeholder="出勤" id="modal_start_time" name="modal_start_time" value="<?php echo format_time($modal_start_time) ?>">
                                        <div class="input-group-prepend">
                                            <button type="button" class="input-group-text" id="start_btn">打刻</button>
                                        </div>
                                        <div class="invalid-feedback"><?php echo $err['modal_start_time'] ?></div>
                                    </div>
                                </div>
                                <div class="col-sm">
                                    <div class="input-group">
                                        <input type="text" class="form-control <?php if (isset($err['modal_end_time'])) echo 'is-invalid'; ?>" placeholder="退勤" id="modal_end_time" name="modal_end_time" value="<?php echo format_time($modal_end_time) ?>">
                                        <div class="input-group-prepend">
                                            <button type="button" class="input-group-text" id="end_btn">打刻</button>
                                        </div>
                                        <div class="invalid-feedback"><?php echo $err['modal_end_time'] ?></div>
                                    </div>
                                </div>
                                <div class="col-sm">
                                    <input type="text" class="form-control <?php if (isset($err['modal_break_time'])) echo 'is-invalid'; ?>" placeholder="休憩" id="modal_break_time" name="modal_break_time" value="<?php echo format_time($modal_break_time) ?>">
                                </div>
                                <div class="invalid-feedback"><?php echo $err['modal_break_time'] ?></div>
                            </div>
                            <div class="form-group pt-3">
                                <textarea class="form-control <?php if (isset($err['comment'])) echo 'is-invalid'; ?>" id="modal_comment" name="modal_comment" rows="5" placeholder="備考"><?php echo $modal_comment ?></textarea>
                                <div class="invalid-feedback"><?php echo $err['comment'] ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary rounded-pill px-5">登録</button>
                    </div>
                </div>
            </div>
        </div>
        <input type="hidden" id="target_date" name="target_date" value="<?php echo $target_date ?>">
        <input type="hidden" name="CSRF_TOKEN" value="<?php echo $_SESSION['CSRF_TOKEN'] ?>">
    </form>

    <script src="//code.jquery.com/jquery.js"></script>
    <script src="../js/bootstrap.min.js"></script>

    <script>
        <?php if (!empty($err)) : ?>
            var inputModal = new bootstrap.Modal(document.getElementById('inputModal'));
            inputModal.toggle();
        <?php endif ?>
        $('#start_btn').click(function() {
            const now = new Date();
            const hour = now.getHours().toString().padStart(2, '0');
            const minute = now.getMinutes().toString().padStart(2, '0');
            $('#modal_start_time').val(hour + ':' + minute);
        })

        $('#end_btn').click(function() {
            const now = new Date();
            const hour = now.getHours().toString().padStart(2, '0');
            const minute = now.getMinutes().toString().padStart(2, '0');
            $('#modal_end_time').val(hour + ':' + minute);
        })

        $('#inputModal').on('show.bs.modal', function(event) {
            var button = $(event.relatedTarget)
            var target_day = button.data('day')

            //編集ボタンが押された対象日の表データを取得
            var day = button.closest('tr').children('th')[0].innerText
            var start_time = button.closest('tr').children('td')[1].innerText
            var end_time = button.closest('tr').children('td')[2].innerText
            var break_time = button.closest('tr').children('td')[3].innerText
            var comment = button.closest('tr').children('td')[6].innerText

            //取得したデータをモーダルの各欄に設定
            $('#modal_day').text(day)
            $('#modal_start_time').val(start_time)
            $('#modal_end_time').val(end_time)
            $('#modal_break_time').val(break_time)
            $('#modal_comment').val(comment)
            $('#target_date').val(target_day)

            /*エラー表示をクリア*/
            $('#modal_start_time').removeClass('is-invalid')
            $('#modal_end_time').removeClass('is-invalid')
            $('#modal_break_time').removeClass('is-invalid')
            $('#modal_comment').removeClass('is-invalid')
        })
    </script>

</body>


</html>