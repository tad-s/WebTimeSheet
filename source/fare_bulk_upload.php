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

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // CSRF トークンチェック
        check_token();

        $success_count = 0;
        $error_count = 0;
        $errors = array();
        $total_lines = 0;

        $file_content = '';

        // ファイルがアップロードされているかチェック
        if (isset($_FILES['bulk_file']) && $_FILES['bulk_file']['error'] === UPLOAD_ERR_OK) {
            $upload_file = $_FILES['bulk_file'];

            // ファイルサイズチェック（1MB以下）
            if ($upload_file['size'] > 1 * 1024 * 1024) {
                throw new Exception('ファイルサイズが大きすぎます（最大1MB）');
            }

            // ファイル拡張子チェック
            $allowed_extensions = array('txt', 'csv');
            $file_extension = strtolower(pathinfo($upload_file['name'], PATHINFO_EXTENSION));
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception('対応していないファイル形式です（.txt, .csvのみ対応）');
            }

            // ファイル内容を読み込み
            $file_content = file_get_contents($upload_file['tmp_name']);
            if ($file_content === false) {
                throw new Exception('ファイルの読み込みに失敗しました');
            }

            // アップロードされたファイルを削除
            if (file_exists($upload_file['tmp_name'])) {
                unlink($upload_file['tmp_name']);
            }
        } else {
            throw new Exception('ファイルのアップロードに失敗しました');
        }

        // 文字エンコーディングの変換（Shift_JIS から UTF-8 へ）
        $encoding = mb_detect_encoding($file_content, array('UTF-8', 'Shift_JIS', 'EUC-JP'), true);
        if ($encoding !== 'UTF-8') {
            $file_content = mb_convert_encoding($file_content, 'UTF-8', $encoding);
        }

        // 改行で分割
        $lines = explode("\n", $file_content);
        
        foreach ($lines as $line_num => $line) {
            $line = trim($line);
            
            // 空行やヘッダー行をスキップ
            if (empty($line) || $line_num == 0 || strpos($line, '経費フラグ') !== false) {
                continue;
            }

            $total_lines++;

            // CSVデータを解析
            $data = str_getcsv($line);
            
            if (count($data) < 7) {
                $errors[] = "行" . ($line_num + 1) . ": データが不足しています（7項目必要）";
                $error_count++;
                continue;
            }

            try {
                // データを取得
                $t_flag = trim($data[0]);        // 経費フラグ
                $date = trim($data[1]);          // 移動日
                $from_name = trim($data[2]);     // 出発
                $to_name = trim($data[3]);       // 到着
                $cost = trim($data[4]);          // 金額
                $bikou = trim($data[5]);         // 備考
                $oufuku_f = trim($data[6]);      // 往復フラグ

                // データの妥当性チェック
                if (!is_numeric($t_flag) || $t_flag < 0 || $t_flag > 3) {
                    $errors[] = "行" . ($line_num + 1) . ": 経費フラグ - 無効な値です（0-3の値が必要）";
                    $error_count++;
                    continue;
                }

                if (empty($date)) {
                    $errors[] = "行" . ($line_num + 1) . ": 移動日 - 必須項目です";
                    $error_count++;
                    continue;
                }

                // 日付フォーマットの変換
                if (preg_match('/^(\d{2})\/(\d{2})$/', $date, $matches)) {
                    // MM/dd形式の場合、今年を追加
                    $formatted_date = date('Y') . '-' . $matches[1] . '-' . $matches[2];
                } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date)) {
                    // YYYY-MM-DD形式の場合はそのまま使用
                    $formatted_date = $date;
                } else {
                    $errors[] = "行" . ($line_num + 1) . ": 移動日 - 日付形式が正しくありません（YYYY-MM-DD または MM/dd）";
                    $error_count++;
                    continue;
                }

                // 日付の妥当性チェック
                $date_parts = explode('-', $formatted_date);
                if (count($date_parts) != 3 || !checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
                    $errors[] = "行" . ($line_num + 1) . ": 移動日 - 有効な日付ではありません";
                    $error_count++;
                    continue;
                }

                // ★【編集権限チェック追加】対象日が編集可能かチェック
                $target_yyyymm = date('Y-m', strtotime($formatted_date));
                if (!canEditMonth($target_yyyymm, $session_user)) {
                    $errors[] = "行" . ($line_num + 1) . ": {$date}の月（{$target_yyyymm}）は編集できません。編集権限がありません。";
                    $error_count++;
                    continue;
                }

                if (empty($from_name)) {
                    $errors[] = "行" . ($line_num + 1) . ": 出発 - 必須項目です";
                    $error_count++;
                    continue;
                }

                if (empty($to_name)) {
                    $errors[] = "行" . ($line_num + 1) . ": 到着 - 必須項目です";
                    $error_count++;
                    continue;
                }

                if (!is_numeric($cost) || $cost < 0) {
                    $errors[] = "行" . ($line_num + 1) . ": 金額 - 数値である必要があります";
                    $error_count++;
                    continue;
                }

                if (!is_numeric($oufuku_f) || ($oufuku_f != 0 && $oufuku_f != 1)) {
                    $errors[] = "行" . ($line_num + 1) . ": 往復フラグ - 0または1である必要があります";
                    $error_count++;
                    continue;
                }

                // total_costの計算
                // 経費フラグが1の場合または往復フラグが0の場合は金額の値をそのまま
                // 往復フラグが1の場合は金額の値の2倍
                if ($t_flag == 1 || $oufuku_f == 0) {
                    $total_cost = $cost;
                } else {
                    $total_cost = $cost * 2;
                }

                // 経費フラグが1の場合は往復フラグを無条件に1にする
                if ($t_flag == 1) {
                    $oufuku_f = 1;
                }

                // 新規データを挿入（既存データがあっても追加）
                $sql = "INSERT INTO fare (
                        user_id, user_no, user_name, date, t_flag, from_name, to_name, cost, total_cost, bikou, oufuku_f
                        ) VALUES (
                        :user_id, :user_no, :user_name, :date, :t_flag, :from_name, :to_name, :cost, :total_cost, :bikou, :oufuku_f
                        )";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':user_id', (int)$session_user['id'], PDO::PARAM_INT);
                $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
                $stmt->bindValue(':user_name', $session_user['name'], PDO::PARAM_STR);
                $stmt->bindValue(':date', $formatted_date, PDO::PARAM_STR);

                $stmt->bindValue(':t_flag', (int)$t_flag, PDO::PARAM_INT);
                $stmt->bindValue(':from_name', $from_name, PDO::PARAM_STR);
                $stmt->bindValue(':to_name', $to_name, PDO::PARAM_STR);
                $stmt->bindValue(':cost', (int)$cost, PDO::PARAM_INT);
                $stmt->bindValue(':total_cost', (int)$total_cost, PDO::PARAM_INT);
                $stmt->bindValue(':bikou', $bikou, PDO::PARAM_STR);
                $stmt->bindValue(':oufuku_f', (int)$oufuku_f, PDO::PARAM_INT);

                $stmt->execute();
                $success_count++;

            } catch (Exception $e) {
                $errors[] = "行" . ($line_num + 1) . ": " . $e->getMessage();
                $error_count++;
            }
        }

        // 結果をセッションに保存
        $_SESSION['FARE_BULK_UPLOAD_RESULT'] = array(
            'success_count' => $success_count,
            'error_count' => $error_count,
            'errors' => $errors,
            'total_lines' => $total_lines
        );

    } else {
        throw new Exception('不正なリクエストです');
    }

} catch (Exception $e) {
    $_SESSION['FARE_BULK_UPLOAD_ERROR'] = $e->getMessage();
}

// fare.phpにリダイレクト
header('Location:' . SITE_URL . 'fare.php');
exit;
?>