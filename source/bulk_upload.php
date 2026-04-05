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
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location:' . SITE_URL . 'index.php');
        exit;
    }

    check_token();
    
    $pdo = connectDb();
    
    $success_count = 0;
    $error_count = 0;
    $errors = array();
    
    $bulk_data = '';

    // ★【15分単位処理関数追加】出勤時間用の15分単位切り上げ関数
    if (!function_exists('roundUpTo15Minutes_24h')) { function roundUpTo15Minutes_24h($time) {
        if (!$time || $time === '00:00:00' || $time === '00:00') {
            return $time;
        }
        
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return $time;
        }
        
        $hours = (int)$parts[0];
        $minutes = (int)$parts[1];
        
        // 分が0の場合はそのまま、それ以外は15分単位で切り上げ
        if ($minutes > 0) {
            $roundedMinutes = ceil($minutes / 15) * 15;
            // 60分を超える場合は時間を繰り上げ
            if ($roundedMinutes >= 60) {
                $hours += 1;
                $roundedMinutes = 0;
            }
        } else {
            $roundedMinutes = 0;
        }
        
        return sprintf('%02d:%02d', $hours, $roundedMinutes);
    } }

    // ★【15分単位処理関数追加】退勤時間用の15分単位切り捨て関数
    if (!function_exists('roundDownTo15Minutes_24h')) { function roundDownTo15Minutes_24h($time) {
        if (!$time || $time === '00:00:00' || $time === '00:00') {
            return $time;
        }
        
        $parts = explode(':', $time);
        if (count($parts) < 2) {
            return $time;
        }
        
        $hours = (int)$parts[0];
        $minutes = (int)$parts[1];
        
        // 分を15分単位で切り捨て
        $roundedMinutes = floor($minutes / 15) * 15;
        
        return sprintf('%02d:%02d', $hours, $roundedMinutes);
    } }

    // ファイルアップロードまたはテキスト入力の処理
    if (isset($_FILES['bulk_file']) && $_FILES['bulk_file']['error'] === UPLOAD_ERR_OK) {
        // ファイルアップロードの場合
        $file_tmp = $_FILES['bulk_file']['tmp_name'];
        $file_type = $_FILES['bulk_file']['type'];
        $file_name = $_FILES['bulk_file']['name'];
        
        // ファイル形式のチェック（テキストファイルのみ許可）
        $allowed_types = array('text/plain', 'text/csv', 'application/csv', 'application/octet-stream');
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, array('txt', 'csv'))) {
            throw new Exception('テキストファイル（.txt, .csv）のみアップロード可能です。');
        }
        
        // ファイルサイズチェック（1MB以下）
        if ($_FILES['bulk_file']['size'] > 1048576) {
            throw new Exception('ファイルサイズが大きすぎます（1MB以下にしてください）。');
        }
        
        $bulk_data = file_get_contents($file_tmp);
        
        if ($bulk_data === false) {
            throw new Exception('ファイルの読み込みに失敗しました。');
        }
        
    } elseif (!empty($_POST['bulk_data'])) {
        // テキスト入力の場合
        $bulk_data = $_POST['bulk_data'];
    } else {
        throw new Exception('データが入力されていません。');
    }
    
    if (empty($bulk_data)) {
        throw new Exception('データが空です。');
    }
    
    // 改行コードを統一
    $bulk_data = str_replace(array("\r\n", "\r"), "\n", $bulk_data);
    $lines = explode("\n", $bulk_data);
    
    // 空行を除去
    $lines = array_filter($lines, function($line) {
        return trim($line) !== '';
    });
    
    if (empty($lines)) {
        throw new Exception('有効なデータが見つかりません。');
    }
    
    // ヘッダー行をスキップ（1行目）
    $data_lines = array_slice($lines, 1);
    
    if (empty($data_lines)) {
        throw new Exception('データ行が見つかりません。');
    }
    
    // 各行を処理
    foreach ($data_lines as $line_number => $line) {
        $row_number = $line_number + 2; // ヘッダー行を除くため+2
        
        try {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // CSVパース
            $fields = str_getcsv($line);
            
            // 必要な項目数をチェック
            if (count($fields) < 6) {
                $errors[] = "{$row_number}行目: 項目数が不足しています（最低6項目必要）";
                $error_count++;
                continue;
            }
            
            // 各項目を取得
            $date_str = isset($fields[0]) ? trim($fields[0]) : '';
            $work_type = isset($fields[1]) ? trim($fields[1]) : '';
            $start_time = isset($fields[2]) ? trim($fields[2]) : '';
            $end_time = isset($fields[3]) ? trim($fields[3]) : '';
            $break_time = isset($fields[4]) ? trim($fields[4]) : '';
            $comment = isset($fields[5]) ? trim($fields[5]) : '';
            $g_work_type = isset($fields[6]) ? trim($fields[6]) : '';
            $g_start = isset($fields[7]) ? trim($fields[7]) : '';
            $g_end = isset($fields[8]) ? trim($fields[8]) : '';
            $g_break = isset($fields[9]) ? trim($fields[9]) : '';
            $g_com = isset($fields[10]) ? trim($fields[10]) : '';
            
            // 日付の変換と検証
            if (empty($date_str)) {
                $errors[] = "{$row_number}行目: 勤務日が空です";
                $error_count++;
                continue;
            }
            
            // 日付フォーマットの変換（MM/DD → YYYY-MM-DD）
            if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $date_str, $matches)) {
                $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                $current_year = date('Y');
                $date = $current_year . '-' . $month . '-' . $day;
            } else {
                $errors[] = "{$row_number}行目: 勤務日の形式が正しくありません（MM/DD形式で入力してください）";
                $error_count++;
                continue;
            }
            
            // 日付の妥当性チェック
            if (!checkdate($month, $day, $current_year)) {
                $errors[] = "{$row_number}行目: 勤務日が有効な日付ではありません";
                $error_count++;
                continue;
            }
            
            // ★【編集権限チェック追加】対象日が編集可能かチェック
            $target_yyyymm = date('Y-m', strtotime($date));
            if (!canEditMonth($target_yyyymm, $session_user)) {
                $errors[] = "{$row_number}行目: {$date_str}の月（{$target_yyyymm}）は編集できません。編集権限がありません。";
                $error_count++;
                continue;
            }
            
            // 勤務区分の検証
            if (empty($work_type) || !is_numeric($work_type)) {
                $errors[] = "{$row_number}行目: 区分が正しくありません（数値で入力してください）";
                $error_count++;
                continue;
            }
            
            $work_type = intval($work_type);
            $work_type_options = getWorkTypeOptions();
            if (!array_key_exists($work_type, $work_type_options)) {
                $errors[] = "{$row_number}行目: 区分の値が無効です（1-8の範囲で入力してください）";
                $error_count++;
                continue;
            }
            
            // ★【欠勤・公休の時間項目強制変更追加】勤務区分が欠勤（5）または公休（8）の場合、時間項目を空文字に変更（DB登録しない）
            if ($work_type == 5 || $work_type == 8) {
                $start_time = '';
                $end_time = '';
                $break_time = '';
            }
            
            // 出勤時間の必須チェック（欠勤・公休以外）
            if ($work_type != 5 && $work_type != 8 && empty($start_time)) {
                $errors[] = "{$row_number}行目: 出勤時間が空です";
                $error_count++;
                continue;
            }
            
            // 時間形式の検証と変換
            $time_fields = array(
                'start_time' => '出勤時間',
                'end_time' => '退勤時間',
                'break_time' => '休憩時間'
            );
            
            foreach ($time_fields as $field => $field_name) {
                $time_value = $$field;
                if (!empty($time_value)) {
                    if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $time_value)) {
                        $errors[] = "{$row_number}行目: {$field_name}の形式が正しくありません（HH:MM形式で入力してください）";
                        $error_count++;
                        continue 2;
                    }
                }
            }
            
            // ★【欠勤・公休の現場勤務時間項目強制変更追加】社内勤務区分が欠勤・公休の場合、現場勤務区分も強制的に同じ区分にし、時間項目も空文字に変更
            if ($work_type == 5 || $work_type == 8) {
                // 現場勤務区分を社内勤務区分と同じにする
                $g_work_type = $work_type;
                $g_start = '';
                $g_end = '';
                $g_break = '';
            } elseif (!empty($g_work_type) && is_numeric($g_work_type)) {
                // 社内勤務区分が通常だが現場勤務区分が欠勤・公休の場合
                $g_work_type_val = intval($g_work_type);
                if ($g_work_type_val == 5 || $g_work_type_val == 8) {
                    $g_start = '';
                    $g_end = '';
                    $g_break = '';
                }
            }
            
            // 現場勤務時間の検証
            $g_time_fields = array(
                'g_start' => '現場出勤時間',
                'g_end' => '現場退勤時間',
                'g_break' => '現場休憩時間'
            );
            
            foreach ($g_time_fields as $field => $field_name) {
                $time_value = $$field;
                if (!empty($time_value)) {
                    if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $time_value)) {
                        $errors[] = "{$row_number}行目: {$field_name}の形式が正しくありません（HH:MM形式で入力してください）";
                        $error_count++;
                        continue 2;
                    }
                }
            }
            
            // 現場勤務区分の検証
            if (!empty($g_work_type)) {
                if (!is_numeric($g_work_type)) {
                    $errors[] = "{$row_number}行目: 現場区分が正しくありません（数値で入力してください）";
                    $error_count++;
                    continue;
                }
                
                $g_work_type = intval($g_work_type);
                if (!array_key_exists($g_work_type, $work_type_options)) {
                    $errors[] = "{$row_number}行目: 現場区分の値が無効です（1-8の範囲で入力してください）";
                    $error_count++;
                    continue;
                }
            }
            
            // ★【15分単位処理追加】出勤時間は切り上げ、退勤時間は切り捨て処理を適用
            if (!empty($start_time)) {
                $start_time = roundUpTo15Minutes_24h($start_time);
            }
            if (!empty($end_time)) {
                $end_time = roundDownTo15Minutes_24h($end_time);
            }
            if (!empty($g_start)) {
                $g_start = roundUpTo15Minutes_24h($g_start);
            }
            if (!empty($g_end)) {
                $g_end = roundDownTo15Minutes_24h($g_end);
            }
            
            // 空文字を NULL に変換
            $end_time = empty($end_time) ? null : $end_time;
            $break_time = empty($break_time) ? null : $break_time;
            $g_work_type = empty($g_work_type) ? null : $g_work_type;
            $g_start = empty($g_start) ? null : $g_start;
            $g_end = empty($g_end) ? null : $g_end;
            $g_break = empty($g_break) ? null : $g_break;
            $g_com = empty($g_com) ? null : $g_com;
            
            // 既存データの確認
            $sql = "SELECT id FROM work WHERE user_id = :user_id AND date = :date LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', (int)$session_user['id'], PDO::PARAM_INT);
            $stmt->bindValue(':date', $date, PDO::PARAM_STR);
            $stmt->execute();
            $existing_work = $stmt->fetch();
            
            if ($existing_work) {
                // 更新
                $sql = "UPDATE work SET start_time = :start_time, end_time = :end_time, break_time = :break_time, work_type = :work_type, comment = :comment, g_work_type = :g_work_type, g_start = :g_start, g_end = :g_end, g_break = :g_break, g_com = :g_com WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':id', (int)$existing_work['id'], PDO::PARAM_INT);
                $stmt->bindValue(':start_time', $start_time, PDO::PARAM_STR);
                $stmt->bindValue(':end_time', $end_time, PDO::PARAM_STR);
                $stmt->bindValue(':break_time', $break_time, PDO::PARAM_STR);
                $stmt->bindValue(':work_type', (int)$work_type, PDO::PARAM_INT);
                $stmt->bindValue(':comment', $comment, PDO::PARAM_STR);
                $stmt->bindValue(':g_work_type', $g_work_type, PDO::PARAM_INT);
                $stmt->bindValue(':g_start', $g_start, PDO::PARAM_STR);
                $stmt->bindValue(':g_end', $g_end, PDO::PARAM_STR);
                $stmt->bindValue(':g_break', $g_break, PDO::PARAM_STR);
                $stmt->bindValue(':g_com', $g_com, PDO::PARAM_STR);
            } else {
                // 新規登録
                $sql = "INSERT INTO work (user_id, user_no, date, start_time, end_time, break_time, work_type, comment, g_work_type, g_start, g_end, g_break, g_com) VALUES (:user_id, :user_no, :date, :start_time, :end_time, :break_time, :work_type, :comment, :g_work_type, :g_start, :g_end, :g_break, :g_com)";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':user_id', (int)$session_user['id'], PDO::PARAM_INT);
                $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
                $stmt->bindValue(':date', $date, PDO::PARAM_STR);
                $stmt->bindValue(':start_time', $start_time, PDO::PARAM_STR);
                $stmt->bindValue(':end_time', $end_time, PDO::PARAM_STR);
                $stmt->bindValue(':break_time', $break_time, PDO::PARAM_STR);
                $stmt->bindValue(':work_type', (int)$work_type, PDO::PARAM_INT);
                $stmt->bindValue(':comment', $comment, PDO::PARAM_STR);
                $stmt->bindValue(':g_work_type', $g_work_type, PDO::PARAM_INT);
                $stmt->bindValue(':g_start', $g_start, PDO::PARAM_STR);
                $stmt->bindValue(':g_end', $g_end, PDO::PARAM_STR);
                $stmt->bindValue(':g_break', $g_break, PDO::PARAM_STR);
                $stmt->bindValue(':g_com', $g_com, PDO::PARAM_STR);
            }
            
            $stmt->execute();
            $success_count++;
            
        } catch (Exception $e) {
            $errors[] = "{$row_number}行目: " . $e->getMessage();
            $error_count++;
        }
    }
    
    // 結果をセッションに保存
    $_SESSION['BULK_UPLOAD_RESULT'] = array(
        'success_count' => $success_count,
        'error_count' => $error_count,
        'errors' => $errors,
        'total_lines' => count($data_lines)
    );
    
} catch (Exception $e) {
    $_SESSION['BULK_UPLOAD_ERROR'] = $e->getMessage();
}

// index.phpにリダイレクト
header('Location:' . SITE_URL . 'index.php');
exit;
?>