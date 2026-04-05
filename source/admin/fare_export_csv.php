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

    // 対象ユーザーのIDをパラメーターから取得
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

    // 交通費データを取得（全て）
    $sql = "SELECT * FROM fare WHERE user_no = :user_no AND DATE_FORMAT(date,'%Y-%m') = :date ORDER BY t_flag, date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_no', $target_user['user_no'], PDO::PARAM_STR);
    $stmt->bindValue(':date', $yyyymm, PDO::PARAM_STR);
    $stmt->execute();
    $fare_list = $stmt->fetchAll();

    // ファイル名を生成（fare_user_no_name_yyyy-mm.csv形式）
    $user_no = isset($target_user['user_no']) ? $target_user['user_no'] : 'unknown';
    $user_name = isset($target_user['name']) ? $target_user['name'] : 'noname';
    
    // ファイル名に使用できない文字を除去（日本語対応）
    $safe_user_no = preg_replace('/[^a-zA-Z0-9\-_]/', '', $user_no);
    $safe_user_name = preg_replace('/[^\p{L}\p{N}\-_]/u', '', $user_name);
    
    // 空の場合のフォールバック
    if (empty($safe_user_no)) $safe_user_no = 'user';
    if (empty($safe_user_name)) $safe_user_name = 'name';
    
    $filename = 'fare_' . $safe_user_no . '_' . $safe_user_name . '_' . $yyyymm . '.csv';

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
    fputcsv($output, ['Web勤怠管理システム - 交通費請求書']);
    fputcsv($output, ['社員NO', $user_no]);
    fputcsv($output, ['氏名', $user_name]);
    fputcsv($output, ['対象月', date('Y年m月', strtotime($yyyymm)) . ' 請求分']);
    fputcsv($output, []); // 空行

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

    // 定期区間のデータを出力
    $teiki_total = 0;
    $teiki_count = 0;
    $teiki_data = array_filter($fare_list, function($item) { return $item['t_flag'] == 1; });
    
    if (!empty($teiki_data)) {
        fputcsv($output, ['定期']);
        fputcsv($output, ['月/日', '区間', '備考', '月額']);
        
        foreach ($teiki_data as $teiki) {
            $date = date('n/j', strtotime($teiki['date']));
            $arrow = ($teiki['oufuku_f'] == 1) ? ' ⇔ ' : ' ⇒ ';
            $route = $teiki['from_name'] . $arrow . $teiki['to_name'];
            $bikou = $teiki['bikou'];
            $cost = '¥' . number_format($teiki['total_cost']);
            
            fputcsv($output, [$date, $route, $bikou, $cost]);
            $teiki_total += $teiki['total_cost'];
            $teiki_count++;
        }
        
        fputcsv($output, ['定期 小計', '', '', '¥' . number_format($teiki_total)]);
        fputcsv($output, []); // 空行
    }

    // 社内経費（給与合算）・現場請求のデータを出力
    $kyuyo_genba_total = 0;
    $kyuyo_genba_count = 0;
    $kyuyo_genba_data = array_filter($fare_list, function($item) { return $item['t_flag'] == 0 || $item['t_flag'] == 3; });
    
    if (!empty($kyuyo_genba_data)) {
        fputcsv($output, ['社内経費（給与合算）・現場請求']);
        fputcsv($output, ['月/日', '区分', '区間', '備考', '金額', '金額(自動計算)']);
        
        foreach ($kyuyo_genba_data as $kyuyo_genba) {
            $date = date('n/j', strtotime($kyuyo_genba['date']));
            $type = getExpenseTypeName($kyuyo_genba['t_flag']);
            $arrow = ($kyuyo_genba['oufuku_f'] == 1) ? ' ⇔ ' : ' ⇒ ';
            $route = $kyuyo_genba['from_name'] . $arrow . $kyuyo_genba['to_name'];
            $bikou = $kyuyo_genba['bikou'];
            $cost = '¥' . number_format($kyuyo_genba['cost']);
            $total_cost = '¥' . number_format($kyuyo_genba['total_cost']);
            
            fputcsv($output, [$date, $type, $route, $bikou, $cost, $total_cost]);
            $kyuyo_genba_total += $kyuyo_genba['total_cost'];
            $kyuyo_genba_count++;
        }
        
        fputcsv($output, ['社内経費（給与合算）・現場請求 小計', '', '', '', '', '¥' . number_format($kyuyo_genba_total)]);
        fputcsv($output, []); // 空行
    }

    // 社内経費（現金精算）のデータを出力
    $genkin_total = 0;
    $genkin_count = 0;
    $genkin_data = array_filter($fare_list, function($item) { return $item['t_flag'] == 2; });
    
    if (!empty($genkin_data)) {
        fputcsv($output, ['社内経費（現金精算）']);
        fputcsv($output, ['月/日', '区間', '備考', '金額', '金額(自動計算)']);
        
        foreach ($genkin_data as $genkin) {
            $date = date('n/j', strtotime($genkin['date']));
            $arrow = ($genkin['oufuku_f'] == 1) ? ' ⇔ ' : ' ⇒ ';
            $route = $genkin['from_name'] . $arrow . $genkin['to_name'];
            $bikou = $genkin['bikou'];
            $cost = '¥' . number_format($genkin['cost']);
            $total_cost = '¥' . number_format($genkin['total_cost']);
            
            fputcsv($output, [$date, $route, $bikou, $cost, $total_cost]);
            $genkin_total += $genkin['total_cost'];
            $genkin_count++;
        }
        
        fputcsv($output, ['社内経費（現金精算） 小計', '', '', '', '¥' . number_format($genkin_total)]);
        fputcsv($output, []); // 空行
    }

    // 合計
    $grand_total = $teiki_total + $kyuyo_genba_total + $genkin_total;
    $kyuyo_furikomi_total = $teiki_total + $kyuyo_genba_total; // 給与振込額
    
    fputcsv($output, ['合計']);
    fputcsv($output, ['定期合計', '¥' . number_format($teiki_total), '件数', $teiki_count . '件']);
    fputcsv($output, ['社内経費（給与合算）・現場請求合計', '¥' . number_format($kyuyo_genba_total), '件数', $kyuyo_genba_count . '件']);
    fputcsv($output, ['社内経費（現金精算）合計', '¥' . number_format($genkin_total), '件数', $genkin_count . '件']);
    fputcsv($output, []); // 空行
    fputcsv($output, ['給与振込額', '¥' . number_format($kyuyo_furikomi_total), '件数', ($teiki_count + $kyuyo_genba_count) . '件']);
    fputcsv($output, ['総合計', '¥' . number_format($grand_total), '総件数', ($teiki_count + $kyuyo_genba_count + $genkin_count) . '件']);
    
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