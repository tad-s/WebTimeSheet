<?php
require_once('../config/config.php');
require_once('functions.php');

try {
    session_start();

    if (!isset($_SESSION['USER'])) {
        header('Location:' . SITE_URL . './login.php');
        exit;
    }

    $session_user = $_SESSION['USER'];
    $pdo = connectDb();

    $yyyymm = isset($_GET['m']) ? $_GET['m'] : date('Y-m');
    $day_count = date('t', strtotime($yyyymm));

    if (count(explode('-', $yyyymm)) != 2) {
        throw new Exception('日付の指定が不正', 500);
    }

    $check_date = new DateTime($yyyymm . '-01');
    $start_date = new DateTime('first day of -11 month 00:00');
    $end_date = new DateTime('first day of this month 00:00');

    if ($check_date < $start_date || $end_date < $check_date) {
        throw new Exception('日付の範囲が不正', 500);
    }

    $sql = "SELECT date, id, start_time, end_time, break_time, work_type, comment, g_work_type, g_start, g_end, g_break, g_com FROM work WHERE user_id = :user_id AND DATE_FORMAT(date,'%Y-%m') = :date";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', (int)$session_user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':date', $yyyymm, PDO::PARAM_STR);
    $stmt->execute();
    $work_list = $stmt->fetchAll(PDO::FETCH_UNIQUE);

    $user_no = isset($session_user['user_no']) ? $session_user['user_no'] : 'unknown';
    $user_name = isset($session_user['name']) ? $session_user['name'] : 'noname';
    
    $safe_user_no = preg_replace('/[^a-zA-Z0-9\-_]/', '', $user_no);
    $safe_user_name = preg_replace('/[^\p{L}\p{N}\-_]/u', '', $user_name);
    
    if (empty($safe_user_no)) $safe_user_no = 'user';
    if (empty($safe_user_name)) $safe_user_name = 'name';
    
    //$filename = 'genba_' . $safe_user_no . '_' . $safe_user_name . '_' . $yyyymm . '.csv';
    $filename = $safe_user_no . '_' . $safe_user_name . '_勤務表(現場用)_' . $yyyymm . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    fputcsv($output, ['Web勤怠管理システム（現場用）']);
    fputcsv($output, ['社員番号', $user_no]);
    fputcsv($output, ['氏名', $user_name]);
    fputcsv($output, ['対象月', date('Y年m月', strtotime($yyyymm))]);
    fputcsv($output, []);

    fputcsv($output, ['日', '現場区分', '現場出勤', '現場退勤', '現場休憩', '現場勤務時間', '現場コメント']);

    $totalFieldWorkMinutes = 0;
    $fieldWorkDays = 0;
    $field_work_type_counts = array();
    
    $work_type_options = getWorkTypeOptions();
    foreach ($work_type_options as $key => $value) {
        $field_work_type_counts[$key] = 0;
    }

    for ($i = 1; $i <= $day_count; $i++) {
        $currentDate = $yyyymm . '-' . sprintf('%02d', $i);
        
        $g_work_type = '';
        $g_start = '';
        $g_end = '';
        $g_break = '';
        $g_com = '';
        $g_work_time = '';

        if (isset($work_list[$currentDate])) {
            $work = $work_list[$currentDate];

            // 社内勤務データ（補完用）
            $start_time = '';
            $end_time = '';
            $break_time = '';
            $work_type = '';
            $comment = '';

            if ($work['start_time'] && $work['start_time'] != '00:00:00') {
                $start_time = format_time_24h($work['start_time']);
            }
            if ($work['end_time'] && $work['end_time'] != '00:00:00') {
                $end_time = format_time_24h($work['end_time']);
            }
            if ($work['break_time'] && $work['break_time'] != '00:00:00') {
                $break_time = format_time_24h($work['break_time']);
            }
            if ($work['work_type']) {
                $work_type = getWorkTypeName($work['work_type']);
            }
            if ($work['comment']) {
                $comment = $work['comment'];
            }

            // 現場勤務区分の処理
            if ($work['g_work_type']) {
                $g_work_type = getWorkTypeName($work['g_work_type']);
                $g_work_type_value = $work['g_work_type'];
            } else {
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
                    $g_start = format_time_24h($work['g_start']);
                }
            } else {
                $g_start = $start_time;
            }

            // 現場退勤時間の処理
            if ($work['g_end'] !== null && $work['g_end'] !== '') {
                if ($work['g_end'] == '00:00:00') {
                    $g_end = '00:00';
                } else {
                    $g_end = format_time_24h($work['g_end']);
                }
            } else {
                $g_end = $end_time;
            }

            // 現場休憩時間の処理
            if ($work['g_break'] !== null && $work['g_break'] !== '') {
                if ($work['g_break'] == '00:00:00') {
                    $g_break = '00:00';
                } else {
                    $g_break = format_time_24h($work['g_break']);
                }
            } else {
                $g_break = $break_time;
            }

            // 現場コメントの処理
            if ($work['g_com']) {
                $g_com = $work['g_com'];
            } else {
                $g_com = $comment;
            }

            // 現場勤務時間を計算
            if ($g_start && $g_end) {
                $g_work_minutes = calculateWorkMinutes($g_start, $g_end, $g_break);
                if ($g_work_minutes > 0) {
                    $g_work_time = minutesToTime($g_work_minutes);
                    $totalFieldWorkMinutes += $g_work_minutes;
                    $fieldWorkDays++;
                }
            }
        }

        fputcsv($output, [
            time_format_dw($currentDate),
            $g_work_type,
            $g_start,
            $g_end,
            $g_break,
            $g_work_time,
            $g_com
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, [
        '合計',
        '現場勤務日数: ' . $fieldWorkDays . '日',
        '',
        '',
        '',
        $totalFieldWorkMinutes > 0 ? minutesToTime($totalFieldWorkMinutes) : '0:00',
        ''
    ]);
    
    fputcsv($output, []);
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

} catch (Exception $e) {
    error_log('CSV Export Error: ' . $e->getMessage());
    header('Location:' . SITE_URL . 'error.php');
    exit;
}
?>