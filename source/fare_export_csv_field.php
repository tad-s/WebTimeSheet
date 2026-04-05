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

    // 現場請求(t_flag=3)のみ取得
    $sql = "SELECT * FROM fare WHERE user_no = :user_no AND DATE_FORMAT(date,'%Y-%m') = :date AND t_flag = 3 ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
    $stmt->bindValue(':date', $yyyymm, PDO::PARAM_STR);
    $stmt->execute();
    $fare_list = $stmt->fetchAll();

    // ファイル名を生成（fare_genba_user_no_name_yyyy-mm.csv形式）
    $user_no = isset($session_user['user_no']) ? $session_user['user_no'] : 'unknown';
    $user_name = isset($session_user['name']) ? $session_user['name'] : 'noname';
    
    // ファイル名に使用できない文字を除去（日本語対応）
    $safe_user_no = preg_replace('/[^a-zA-Z0-9\-_]/', '', $user_no);
    $safe_user_name = preg_replace('/[^\p{L}\p{N}\-_]/u', '', $user_name);
    
    // 空の場合のフォールバック
    if (empty($safe_user_no)) $safe_user_no = 'user';
    if (empty($safe_user_name)) $safe_user_name = 'name';
    
    //$filename = 'fare_genba_' . $safe_user_no . '_' . $safe_user_name . '_' . $yyyymm . '.csv';
    $filename = $safe_user_no . '_' . $safe_user_name . '_交通費(現場請求用)_' . $yyyymm . '.csv';

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
    fputcsv($output, ['Web勤怠管理システム - 交通費請求書（現場用）']);
    //fputcsv($output, ['デバッグ用', $sql,'user_no:',$session_user['user_no'],'yyyymm:',$yyyymm]);
    fputcsv($output, ['社員NO', $user_no]);
    fputcsv($output, ['氏名', $user_name]);
    fputcsv($output, ['対象月', date('Y年m月', strtotime($yyyymm)) . ' 請求分']);
    fputcsv($output, []); // 空行

    $genba_total = 0;
    $genba_count = 0;
    
    if (!empty($fare_list)) {
        fputcsv($output, ['現場請求']);
        fputcsv($output, ['月/日', '区間', '備考', '金額', '金額(自動計算)']);
        
        foreach ($fare_list as $genba) {
            $date = date('n/j', strtotime($genba['date']));
            $arrow = ($genba['oufuku_f'] == 1) ? ' ⇔ ' : ' ⇒ ';
            $route = $genba['from_name'] . $arrow . $genba['to_name'];
            $bikou = $genba['bikou'];
            $cost = '¥' . number_format($genba['cost']);
            $total_cost = '¥' . number_format($genba['total_cost']);
            
            fputcsv($output, [$date, $route, $bikou, $cost, $total_cost]);
            $genba_total += $genba['total_cost'];
            $genba_count++;
        }
        
        fputcsv($output, ['現場請求 小計', '', '', '', '¥' . number_format($genba_total)]);
        fputcsv($output, []); // 空行
    } else {
        fputcsv($output, ['現場請求データはありません']);
        fputcsv($output, []); // 空行
    }

    // 合計
    fputcsv($output, ['合計']);
    fputcsv($output, ['現場請求合計', '¥' . number_format($genba_total), '件数', $genba_count . '件']);
    
    fputcsv($output, []); // 空行
    
    // 出力日時
    fputcsv($output, ['出力日時', date('Y年m月d日 H:i:s')]);

    fclose($output);
    exit;

} catch (Exception $e) {
    // エラーログを出力
    error_log('Fare CSV Export Error: ' . $e->getMessage());
    header('Location:' . SITE_URL . 'error.php');
    exit;
}
?>