<?php
require_once('../config/config.php');
require_once('functions.php');

try {
    session_start();

    // ログイン状態をチェック
    if (!isset($_SESSION['USER'])) {
        header('Location:' . SITE_URL . './login.php');
        exit;
    }

    $session_user = $_SESSION['USER'];
    
    $pdo = connectDb();

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

    // 勤務データを取得（社内勤務関連カラムのみ）
    $sql = "SELECT date, id, start_time, end_time, break_time, work_type, comment FROM work WHERE user_id = :user_id AND DATE_FORMAT(date,'%Y-%m') = :date";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', (int)$session_user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':date', $yyyymm, PDO::PARAM_STR);
    $stmt->execute();
    $work_list = $stmt->fetchAll(PDO::FETCH_UNIQUE);

    // ファイル名を生成（shanai_user_no_name_yyyy-mm.csv形式）
    $user_no = isset($session_user['user_no']) ? $session_user['user_no'] : 'unknown';
    $user_name = isset($session_user['name']) ? $session_user['name'] : 'noname';
    
    // ファイル名に使用できない文字を除去
    $safe_user_no = preg_replace('/[^a-zA-Z0-9\-_]/', '', $user_no);
    $safe_user_name = preg_replace('/[^\p{L}\p{N}\-_]/u', '', $user_name);
    
    // 空の場合のフォールバック
    if (empty($safe_user_no)) $safe_user_no = 'user';
    if (empty($safe_user_name)) $safe_user_name = 'name';
    
    //$filename = 'shanai_' . $safe_user_no . '_' . $safe_user_name . '_' . $yyyymm . '.csv';
    $filename = $safe_user_no . '_' . $safe_user_name . '_勤務表(社内用)_' . $yyyymm . '.csv';

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
    fputcsv($output, ['Web勤怠管理システム（社内用）']);
    fputcsv($output, ['社員番号', $user_no]);
    fputcsv($output, ['氏名', $user_name]);
    fputcsv($output, ['対象月', date('Y年m月', strtotime($yyyymm))]);
    fputcsv($output, []); // 空行

    // テーブルヘッダー（社内勤務項目のみ7列）
    fputcsv($output, [
        '日', 
        '区分', 
        '出勤', 
        '退勤', 
        '休憩', 
        '勤務時間', 
        '備考'
    ]);

    $totalWorkMinutes = 0;
    $workDays = 0;
    $work_type_counts = array();
    
    // 区分別カウント用の配列を初期化
    $work_type_options = getWorkTypeOptions();
    foreach ($work_type_options as $key => $value) {
        $work_type_counts[$key] = 0;
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

        if (isset($work_list[$currentDate])) {
            $work = $work_list[$currentDate];

            if ($work['start_time'] && $work['start_time'] != '00:00:00') {
                $start_time = format_time($work['start_time']);
            }

            if ($work['end_time'] && $work['end_time'] != '00:00:00') {
                $end_time = format_time($work['end_time']);
            }

            if ($work['break_time'] && $work['break_time'] != '00:00:00') {
                $break_time = format_time($work['break_time']);
            }

            if ($work['work_type']) {
                $work_type = getWorkTypeName($work['work_type']);
                if (isset($work_type_counts[$work['work_type']])) {
                    $work_type_counts[$work['work_type']]++;
                }
            }

            if ($work['comment']) {
                $comment = $work['comment'];
            }

            // 勤務時間を計算
            if ($start_time && $end_time) {
                $work_minutes = calculateWorkMinutes($start_time, $end_time, $break_time);
                if ($work_minutes > 0) {
                    $work_time = minutesToTime($work_minutes);
                    $totalWorkMinutes += $work_minutes;
                    $workDays++;
                }
            }
        }

        fputcsv($output, [
            time_format_dw($currentDate),
            $work_type,
            $start_time,
            $end_time,
            $break_time,
            $work_time,
            $comment
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
        $totalWorkMinutes > 0 ? minutesToTime($totalWorkMinutes) : '0:00',
        ''
    ]);
    
    // 区分別集計
    fputcsv($output, []); // 空行
    fputcsv($output, ['区分別集計（社内勤務）']);
    foreach ($work_type_options as $key => $value) {
        if (isset($work_type_counts[$key])) {
            fputcsv($output, [$value, $work_type_counts[$key] . '日']);
        } else {
            fputcsv($output, [$value, '0日']);
        }
    }

    fclose($output);
    exit;

} catch (Exception $e) {
    // エラーログを出力
    error_log('CSV Export Error: ' . $e->getMessage());
    header('Location:' . SITE_URL . 'error.php');
    exit;
}
?>