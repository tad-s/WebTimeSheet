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

    $pdo = connectDb();

    $err = array();

    $target_date = date('Y-m-d');
    
    // モーダル用変数の初期化
    $modal_start_time = '';
    $modal_end_time = '';
    $modal_break_time = '01:00';
    $modal_work_type = 1;
    $modal_comment = '';
    $modal_able = '';
    
    // 現場勤務関連の変数を追加
    $modal_g_work_type = 1;
    $modal_g_start = '';
    $modal_g_end = '';
    $modal_g_break = '';
    $modal_g_com = '';
    $modal_g_able = '';

    //モーダルの自動表示判定★2025/0928基本的に自動モーダル表示しないように修正
    $modal_view_fig = FALSE;
    //$modal_view_fig = TRUE;

    // ★【なりすまし機能追加】なりすまし状態のチェック
    $is_impersonating = isset($_SESSION['IS_IMPERSONATING']) && $_SESSION['IS_IMPERSONATING'];
    $impersonate_target_name = isset($_SESSION['IMPERSONATE_TARGET_NAME']) ? $_SESSION['IMPERSONATE_TARGET_NAME'] : '';

    // 24時間表記対応の関数を追加
    function format_time_24h($value) {
        if (!$value || $value == '00:00:00' || $value == '00:00') {
            return '';
        }
        // 24時間を超える時間をそのまま表示
        $parts = explode(':', $value);
        if (count($parts) >= 2) {
            return sprintf('%d:%02d', (int)$parts[0], (int)$parts[1]);
        }
        return $value;
    }

    // ★出勤時間用の15分単位切り上げ関数を追加
    function roundUpTo15Minutes_24h($time) {
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
    }

    function roundDownTo15Minutes_24h($time) {
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
    }

    function calculateWorkMinutes_24h($start_time, $end_time, $break_time) {
        if (!$start_time || !$end_time) {
            return 0;
        }
        
        $start_parts = explode(':', $start_time);
        $end_parts = explode(':', $end_time);
        $break_parts = explode(':', $break_time ?: '00:00');
        
        $start_minutes = (int)$start_parts[0] * 60 + (int)$start_parts[1];
        $end_minutes = (int)$end_parts[0] * 60 + (int)$end_parts[1];
        $break_minutes = (int)$break_parts[0] * 60 + (int)$break_parts[1];
        
        // 退勤時間が出勤時間より小さい場合は翌日として計算
        if ($end_minutes <= $start_minutes) {
            $end_minutes += 24 * 60; // 24時間加算
        }
        
        $work_minutes = $end_minutes - $start_minutes - $break_minutes;
        return $work_minutes > 0 ? $work_minutes : 0;
    }


// デバッグ用（管理者、編集フラグ確認用）
//echo "<!-- -->DEBUG: auth_type=" . $session_user['auth_type'] . ", edit_flg=" . $session_user['edit_flg'] . "";
//echo "<!-- -->DEBUG: canEditMonth result=" . (canEditMonth($yyyymm, $session_user) ? 'true' : 'false') . "";



    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        
        if (isset($_POST['action'])) {
            
            if ($_POST['action'] == 'register') {
                //日報登録処理
                check_token();

                //入力値をPOSTパラメーターから取得
                $target_date = $_POST['target_date'];
                $modal_start_time = $_POST['modal_start_time'];
                $modal_end_time = $_POST['modal_end_time'];
                $modal_break_time = $_POST['modal_break_time'];
                $modal_work_type = $_POST['modal_work_type'];
                $modal_comment = $_POST['modal_comment'];
                
                // 現場勤務関連の入力値を取得
                $modal_g_work_type = $_POST['modal_g_work_type'];
                $modal_g_start = $_POST['modal_g_start'];
                $modal_g_end = $_POST['modal_g_end'];
                $modal_g_break = $_POST['modal_g_break'];
                $modal_g_com = $_POST['modal_g_com'];

                // ★【編集権限チェック追加】対象日が編集可能かチェック
                $target_yyyymm = date('Y-m', strtotime($target_date));
                if (!canEditMonth($target_yyyymm, $session_user)) {
                    $err['target_date'] = 'この月の勤怠は編集できません。';
                }

                //出勤時間の必須、形式チェック
			//欠勤の場合強制的に0⇒0930''
                if ($modal_work_type == 5) {
                    $modal_start_time = '';
                    $modal_end_time = '';
                    $modal_break_time = '';
                    //$modal_start_time = '00:00';
                    //$modal_end_time = '00:00';
                    //$modal_break_time = '00:00';
			//echo("</br><div>区分5</div></br></br>");
                } elseif ($modal_work_type == 8 ) {
			//公休の場合強制的に0⇒0930''
                    $modal_start_time = '';
                    $modal_end_time = '';
                    $modal_break_time = '';
                    //$modal_start_time = '00:00';
                    //$modal_end_time = '00:00';
                    //$modal_break_time = '00:00';					
			//echo("</br><div>区分3</div></br></br>");
                } elseif ($modal_work_type == 9 ) {
			//帰社日の場合強制的に17:30
                    $modal_end_time = '17:30';
			//echo("</br><div>区分9</div></br></br>");
                } elseif (!$modal_start_time && $modal_work_type != 5 && $modal_work_type != 8) {
                    $err['modal_start_time'] = '出勤時間を入力して下さい';
                } elseif ($modal_start_time && !preg_match('/^([01]?[0-9]|2[0-9]|3[01]):([0-5][0-9])$/', $modal_start_time)) {
                    $modal_start_time = '';
                    $err['modal_start_time'] = '出勤時間を正しく入力して下さい';
                }

                //退勤時間の形式チェック（24時間表記対応）
                if ($modal_end_time != '' && $modal_work_type != 5 && $modal_work_type != 8 && !preg_match('/^([01]?[0-9]|2[0-9]|3[0-1]):([0-5][0-9])$/', $modal_end_time)) {
                    $modal_end_time = '';
                    $err['modal_end_time'] = '退勤時間を正しく入力して下さい';
                }
                if ($modal_end_time == '') {
                    $modal_end_time = NULL;
                }

                //休息時間の形式チェック
                if ($modal_break_time != '' && $modal_work_type != 5 && $modal_work_type != 8 && !preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $modal_break_time)) {
                    $modal_break_time = '';
                    $err['modal_break_time'] = '休息時間を正しく入力して下さい';
                }

                // 現場出勤時間の形式チェック
				if ($modal_g_start != '' && !preg_match('/^([01]?[0-9]|2[0-9]|3[01]):([0-5][0-9])$/', $modal_g_start)) {
                    $modal_g_start = '';
                    $err['modal_g_start'] = '現場出勤時間を正しく入力して下さい';
                }

                // 現場退勤時間の形式チェック（24時間表記対応）
                if ($modal_g_end != '' && !preg_match('/^([01]?[0-9]|2[0-9]|3[0-1]):([0-5][0-9])$/', $modal_g_end)) {
                    $modal_g_end = '';
                    $err['modal_g_end'] = '現場退勤時間を正しく入力して下さい';
                }

                // 現場休憩時間の形式チェック
                if ($modal_g_break != '' && !preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $modal_g_break)) {
                    $modal_g_break = '';
                    $err['modal_g_break'] = '現場休憩時間を正しく入力して下さい';
                }

                //勤務区分のチェック
                if (!$modal_work_type || !in_array($modal_work_type, array_keys(getWorkTypeOptions()))) {
                    $err['modal_work_type'] = '勤務区分を選択して下さい';
                }

                //現場勤務区分のチェック
                //if (!$modal_g_work_type || !in_array($modal_g_work_type, array_keys(getWorkTypeOptions()))) {
                //    $err['modal_g_work_type'] = '現場勤務区分を選択して下さい';
                //}

                //備考の最大サイズチェック
                if (mb_strlen($modal_comment, 'utf-8') > 2000) {
                    $err['modal_comment'] = '備考が長すぎます。';
                }

				// ★2025/09/28【公休・欠勤時の備考欄必須チェック追加】
				// 公休(8)または欠勤(5)の場合は備考欄の入力を必須とする
				if (($modal_work_type == 5 || $modal_work_type == 8) && empty(trim($modal_comment))) {
					$err['modal_comment'] = '欠勤または公休の場合は備考欄の理由入力が必須です。';
				}

                //現場コメントの最大サイズチェック
                if (mb_strlen($modal_g_com, 'utf-8') > 2000) {
                    $err['modal_g_com'] = '現場コメントが長すぎます。';
                }

                ////出退勤時間整合性チェック（24時間表記対応）
				// 欠勤と公休の場合は整合性チェックをスキップ
				if ($modal_start_time && $modal_end_time && $modal_work_type != 5 && $modal_work_type != 8) {
					$start_parts = explode(':', $modal_start_time);
					$end_parts = explode(':', $modal_end_time);
					$start_hour = (int)$start_parts[0];
					$end_hour = (int)$end_parts[0];
					
					// 同日内での比較（24時未満の場合のみ）
					if ($end_hour < 24 && $start_hour < 24) {
						$start_minutes = $start_hour * 60 + (int)$start_parts[1];
						$end_minutes = $end_hour * 60 + (int)$end_parts[1];
						
						if ($start_minutes >= $end_minutes) {
							$err['modal_start_time'] = '出勤時間は退勤時間より先にして下さい。';
							$err['modal_end_time'] = '退勤時間は出勤時間より後にして下さい。';
						}
					}
				}

				//エラーがあればDB登録せずモーダル表示しエラー表示
                //★2025/09/28エラー表示対応
				//if (empty($err)) {
                if (!empty($err)) {
                    $modal_view_fig = TRUE;
                } else {
					//【2025/07/23修正箇所1】出勤時間は15分単位で切り上げ、退勤時間・現場退勤時間は15分単位で切り捨て
					$modal_start_time = roundUpTo15Minutes_24h($modal_start_time);
					if ($modal_end_time) {
						$modal_end_time = roundDownTo15Minutes_24h($modal_end_time);
					}
					if ($modal_g_start) {
						$modal_g_start = roundUpTo15Minutes_24h($modal_g_start);
					}
					if ($modal_g_end) {
						$modal_g_end = roundDownTo15Minutes_24h($modal_g_end);
					}
	
                    //対象日のデータがあるかどうかチェック
                    $sql = "SELECT id FROM work WHERE user_id = :user_id AND date = :date LIMIT 1";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':user_id', (int)$session_user['id'], PDO::PARAM_INT);
                    $stmt->bindValue(':date', $target_date, PDO::PARAM_STR);
                    $stmt->execute();
                    $work = $stmt->fetch();

                    if ($work) {
                        //対象日のデータがあればUPDATE
                        $sql = "UPDATE work SET start_time = :start_time, end_time = :end_time, break_time = :break_time, work_type = :work_type, comment = :comment, g_work_type = :g_work_type, g_start = :g_start, g_end = :g_end, g_break = :g_break, g_com = :g_com WHERE id = :id";
                        $stmt = $pdo->prepare($sql);
                        $stmt->bindValue(':id', (int)$work['id'], PDO::PARAM_INT);
                        $stmt->bindValue(':start_time', $modal_start_time, PDO::PARAM_STR);
                        $stmt->bindValue(':end_time', $modal_end_time, PDO::PARAM_STR);
                        $stmt->bindValue(':break_time', $modal_break_time, PDO::PARAM_STR);
                        $stmt->bindValue(':work_type', (int)$modal_work_type, PDO::PARAM_INT);
                        $stmt->bindValue(':comment', $modal_comment, PDO::PARAM_STR);
                        $stmt->bindValue(':g_work_type', (int)$modal_g_work_type, PDO::PARAM_INT);
                        $stmt->bindValue(':g_start', $modal_g_start ?: null, PDO::PARAM_STR);
                        $stmt->bindValue(':g_end', $modal_g_end ?: null, PDO::PARAM_STR);
                        $stmt->bindValue(':g_break', $modal_g_break ?: null, PDO::PARAM_STR);
                        $stmt->bindValue(':g_com', $modal_g_com, PDO::PARAM_STR);
                        $stmt->execute();
                    } else {
                        //対象日のデータがなければINSERT
                        $sql = "INSERT INTO work (user_id, user_no, date, start_time, end_time, break_time, work_type, comment, g_work_type, g_start, g_end, g_break, g_com) VALUES (:user_id, :user_no, :date, :start_time, :end_time, :break_time, :work_type, :comment, :g_work_type, :g_start, :g_end, :g_break, :g_com)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->bindValue(':user_id', (int)$session_user['id'], PDO::PARAM_INT);
                        $stmt->bindValue(':user_no', $session_user['user_no'], PDO::PARAM_STR);
                        $stmt->bindValue(':date', $target_date, PDO::PARAM_STR);
                        $stmt->bindValue(':start_time', $modal_start_time, PDO::PARAM_STR);
                        $stmt->bindValue(':end_time', $modal_end_time, PDO::PARAM_STR);
                        $stmt->bindValue(':break_time', $modal_break_time, PDO::PARAM_STR);
                        $stmt->bindValue(':work_type', (int)$modal_work_type, PDO::PARAM_INT);
                        $stmt->bindValue(':comment', $modal_comment, PDO::PARAM_STR);
                        $stmt->bindValue(':g_work_type', (int)$modal_g_work_type, PDO::PARAM_INT);
                        $stmt->bindValue(':g_start', $modal_g_start ?: null, PDO::PARAM_STR);
                        $stmt->bindValue(':g_end', $modal_g_end ?: null, PDO::PARAM_STR);
                        $stmt->bindValue(':g_break', $modal_g_break ?: null, PDO::PARAM_STR);
                        $stmt->bindValue(':g_com', $modal_g_com, PDO::PARAM_STR);
                        $stmt->execute();
                    }
                    // 登録成功時のみモーダルを閉じる
                    $modal_view_fig = FALSE;
                }
				
            } elseif ($_POST['action'] == 'delete') {
                //勤怠データ削除処理
                check_token();
                
                $delete_date = $_POST['delete_date'];
                
                // ★【編集権限チェック追加】削除対象日が編集可能かチェック
                $delete_yyyymm = date('Y-m', strtotime($delete_date));
                if (!canEditMonth($delete_yyyymm, $session_user)) {
                    // 編集不可の場合はエラーページにリダイレクト
                    header('Location:' . SITE_URL . 'error.php');
                    exit;
                }
                
                $sql = "DELETE FROM work WHERE user_id = :user_id AND date = :date";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':user_id', (int)$session_user['id'], PDO::PARAM_INT);
                $stmt->bindValue(':date', $delete_date, PDO::PARAM_STR);
                $stmt->execute();
            }
        }
    } else {
        set_token();

        //当日のデータがあるかどうかチェック
        $sql = "SELECT * FROM work WHERE user_id = :user_id AND date = :date LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', (int)$session_user['id'], PDO::PARAM_INT);
        $stmt->bindValue(':date', date('Y-m-d'), PDO::PARAM_STR);
        $stmt->execute();
        $today_work = $stmt->fetch();

        if ($today_work) {
            $modal_start_time = $today_work['start_time'];
            $modal_end_time = $today_work['end_time'];
            $modal_break_time = $today_work['break_time'];
            $modal_work_type = $today_work['work_type'];
            $modal_comment = $today_work['comment'];
            
            // 現場勤務関連データの取得
            $modal_g_work_type = $today_work['g_work_type'] ?: 1;
            $modal_g_start = $today_work['g_start'];
            $modal_g_end = $today_work['g_end'];
            $modal_g_break = $today_work['g_break'];
            $modal_g_com = $today_work['g_com'];

            if (format_time_24h($modal_start_time) && format_time_24h($modal_end_time)) {
                $modal_view_fig = FALSE;
            }
        } else {
            $modal_start_time = '';
            $modal_end_time = '';
            $modal_break_time = '01:00';
            $modal_work_type = 1; // デフォルトは「通常」
            $modal_comment = '';
            
            // 現場勤務関連のデフォルト値
            $modal_g_work_type = 1;
            $modal_g_start = '';
            $modal_g_end = '';
            $modal_g_break = '01:00';
            $modal_g_com = '';
        }
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

        if ($check_date != $end_date && empty($err)) {
            //表示している画面が当月じゃなければモーダルを自動表示しない
            $modal_view_fig = FALSE;
        }
    } else {
        $yyyymm = date('Y-m');
        $day_count = date('t');
    }

    // 現場勤務関連のカラムも取得するようにSQLを修正
    $sql = "SELECT date, id, start_time, end_time, break_time, work_type, comment, g_work_type, g_start, g_end, g_break, g_com FROM work WHERE user_id = :user_id AND DATE_FORMAT(date,'%Y-%m') = :date";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', (int)$session_user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':date', $yyyymm, PDO::PARAM_STR);
    $stmt->execute();
    $work_list = $stmt->fetchAll(PDO::FETCH_UNIQUE);
} catch (Exception $e) {
    header('Location:' . SITE_URL . 'error.php');
    exit;
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

    <title>日報登録 | works</title>

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

        /* レスポンシブ対応とコンパクト化 */
        body {
            font-size: 0.9rem;
        }
        
        /* ヘッダー部分をコンパクトに */
        .header-section {
            padding: 0.5rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-bottom: 1rem;
        }
        
        .header-title {
            font-size: 1.5rem;
            font-weight: bold;
            margin: 0;
        }
        
        .user-info {
            font-size: 0.8rem;
            opacity: 0.9;
        }
        
        /* ボタン群のレスポンシブ対応 */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .action-buttons .btn {
            flex: 1;
            min-width: 120px;
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
        }
        
        @media (max-width: 576px) {
            .action-buttons .btn {
                min-width: 100px;
                font-size: 0.75rem;
                padding: 0.3rem 0.6rem;
            }
        }
        
        /* 月選択をコンパクトに */
        .month-selector {
            max-width: 200px;
            margin: 0 auto 1rem auto;
        }
        
        /* テーブルのコンパクト化 */
        .work-table {
            font-size: 0.8rem;
        }
        
        .work-table th,
        .work-table td {
            padding: 0.3rem 0.2rem !important;
            vertical-align: middle;
            line-height: 1.2;
            border: 1px solid #dee2e6;
        }
        
        .work-table th {
            background-color: #f8f9fa;
            font-size: 0.75rem;
            font-weight: 600;
            text-align: center;
        }
        
        .work-table .day-col {
            width: 15%;
            min-width: 40px;
            font-weight: 600;
        }
        
        .work-table .type-col {
            width: 12%;
            min-width: 35px;
        }
        
        .work-table .time-col {
            width: 10%;
            min-width: 35px;
        }
        
        .work-table .comment-col {
            width: 30%;
            word-break: break-word;
        }
        
        .work-table .action-col {
            width: 13%;
            min-width: 45px;
        }
        
        /* ボタンのコンパクト化 */
        .btn-edit,
        .btn-delete-work {
            padding: 0.15rem 0.3rem !important;
            font-size: 0.7rem !important;
            line-height: 1;
            border-radius: 0.2rem;
            margin: 0 0.1rem;
        }
        
        .btn-edit i,
        .btn-delete-work i {
            font-size: 0.7rem;
        }

        .btn-delete-work {
            height: 25px;
        }
        
        /* スマホでのテーブル調整 */
        @media (max-width: 576px) {
            .work-table {
                font-size: 0.7rem;
            }
            
            .work-table th,
            .work-table td {
                padding: 0.2rem 0.1rem !important;
            }
            
            .work-table th {
                font-size: 0.65rem;
            }
            
            .btn-edit,
            .btn-delete-work {
                padding: 0.1rem 0.2rem !important;
                font-size: 0.65rem !important;
                margin: 0 0.05rem;
            }
            
            .btn-edit i,
            .btn-delete-work i {
                font-size: 0.65rem;
            }
        }
        
        /* 土日祝日の行の色分け */
        .saturday-row {
            background-color: #e3f2fd !important;
        }
        
        .sunday-row {
            background-color: #ffebee !important;
        }
        
        .holiday-row {
            background-color: #fff3e0 !important;
        }
        
        .saturday-row:hover {
            background-color: #bbdefb !important;
        }
        
        .sunday-row:hover {
            background-color: #ffcdd2 !important;
        }
        
        .holiday-row:hover {
            background-color: #ffe0b2 !important;
        }
        
        /* 異常値の警告表示 */
        .warning-text {
            color: #dc3545 !important;
            font-weight: bold !important;
        }
        
        /* 集計セクションをコンパクトに */
        .summary-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .summary-card {
            background: white;
            border-radius: 0.3rem;
            padding: 0.8rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .summary-card h6 {
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            color: #495057;
        }
        
        .summary-item {
            font-size: 0.8rem;
            margin-bottom: 0.3rem;
        }
        
        /* 一括登録ボタン */
        .bulk-upload-btn {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            border: none;
            border-radius: 25px;
            padding: 0.8rem 2rem;
            font-weight: bold;
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
            transition: all 0.3s ease;
        }
        
        .bulk-upload-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(255, 193, 7, 0.4);
        }
        
        /* アラート改善 */
        .result-alert {
            border-radius: 0.5rem;
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* 現場勤務セクションのスタイル */
        .field-work-section {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 15px;
            margin-top: 15px;
        }
        
        .field-work-title {
            color: #495057;
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        
        /* モバイル用のオーバーフロー対応 */
        .table-responsive {
            border-radius: 0.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* コンテナのパディング調整 */
        .main-container {
            padding: 0.5rem;
        }
        
        @media (min-width: 768px) {
            .main-container {
                padding: 1rem;
            }
        }
    </style>
</head>

<body class="text-center bg-light">

    <!-- ★【なりすまし機能追加】なりすまし中の警告表示 -->
    <?php if ($is_impersonating): ?>
    <div class="impersonation-alert">
        <i class="fas fa-user-secret"></i> 
        管理者として「<?php echo h($impersonate_target_name); ?>」の勤怠を代理入力中です
        <div>
            <a href="<?php echo SITE_URL ?>admin/return_from_impersonation.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> 社員一覧に戻る
            </a>
        </div>
    </div>
    <?php endif; ?>

	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="d-flex justify-content-between align-items-center pt-3 pl-3 pr-3">
					<!-- 左側: ユーザー情報 -->
					<div class="text-left">
						<small class="text-muted">
							ユーザー: <?php echo isset($session_user['user_no']) ? h($session_user['user_no']) : '未設定'; ?> - 
							<?php echo isset($session_user['name']) ? h($session_user['name']) : '名前未設定'; ?>
						</small>
					</div>
					
					<!-- 右側: マニュアルリンク -->
					<div class="text-right">
						<a href="https://note.com/ready_stork1506/n/n3015d215e291" target="_blank" class="btn btn-sm btn-outline-info" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
							<i class="fas fa-book"></i> マニュアル
						</a>
					</div>
				</div>
			</div>
		</div>
	</div>

    <!-- 一括登録結果表示 -->
    <?php if (isset($_SESSION['BULK_UPLOAD_RESULT'])) : ?>
        <?php $result = $_SESSION['BULK_UPLOAD_RESULT']; ?>
        <div class="container-fluid mt-2">
            <div class="alert alert-<?php echo $result['error_count'] > 0 ? 'warning' : 'success'; ?> alert-dismissible fade show" role="alert">
                <strong>一括登録結果:</strong> 
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
        </div>
        <?php unset($_SESSION['BULK_UPLOAD_RESULT']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['BULK_UPLOAD_ERROR'])) : ?>
        <div class="container-fluid mt-2">
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>アップロードエラー:</strong> <?php echo h($_SESSION['BULK_UPLOAD_ERROR']); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        </div>
        <?php unset($_SESSION['BULK_UPLOAD_ERROR']); ?>
    <?php endif; ?>

    <form class="border rounded bg-white form-time-table">

        <h1 class="h3 my-3">月別勤怠表</h1>

<!--2025/10/30ボタン追加に伴うデザイン修正-->
<div class="d-flex flex-wrap justify-content-end align-items-center mb-2">
    <a class="btn btn-info mr-2 mb-1" href="fare.php?m=<?php echo urlencode($yyyymm) ?>">
        <i class="fas fa-train"></i> 交通費入力
    </a>
    <a class="btn btn-success mr-2 mb-1" href="export_csv.php?m=<?php echo urlencode($yyyymm) ?>">
        <i class="fas fa-file-csv"></i> CSV出力(社内用)
    </a>
    <a class="btn btn-info mr-2 mb-1" href="export_csv_field.php?m=<?php echo urlencode($yyyymm) ?>">
        <i class="fas fa-file-csv"></i> CSV出力(現場用)
    </a>
    <!-- ★【なりすまし機能追加】なりすまし中はログアウトボタンを非表示 -->
    <?php if (!$is_impersonating): ?>
    <a class="btn btn-outline-primary mb-1" href="<?php echo SITE_URL ?>logout.php">ログアウト</a>
    <?php endif; ?>
</div>

<!--
        <div style="width:355px" class="float-right">
            <a style="padding-right:8px" class="btn btn-info mr-2" href="fare.php?m=<?php echo urlencode($yyyymm) ?>">
                <i class="fas fa-train"></i> 交通費入力
            </a>

            <a style="padding-right:8px" class="btn btn-success mr-2" href="org_export_csv.php?m=<?php echo urlencode($yyyymm) ?>">
                <i class="fas fa-file-csv"></i> CSV出力(両方)
            </a>

			<a style="padding-right:8px" class="btn btn-success mr-2" href="export_csv.php?m=<?php echo urlencode($yyyymm) ?>">
				<i class="fas fa-file-csv"></i> CSV出力(社内用)
			</a>
			<a style="padding-right:8px" class="btn btn-info mr-2" href="export_csv_field.php?m=<?php echo urlencode($yyyymm) ?>">
				<i class="fas fa-file-csv"></i> CSV出力(現場用)
			</a>
-->
            <!-- ★【なりすまし機能追加】なりすまし中はログアウトボタンを非表示 -->
<!--
            <?php if (!$is_impersonating): ?>
            <a style="padding-right:8px" class="btn bbb btn-outline-primary" href="<?php echo SITE_URL ?>logout.php">ログアウト</a>
            <?php endif; ?>
        </div>
-->
        <select class="form-control rounded-pill mb-3" name="m" onchange="submit(this.form)">
            <option value="<?php echo date('Y-m') ?>"><?php echo date('Y/m') ?></option>
            <?php for ($i = 1; $i < 12; $i++) : ?>
                <?php $target_yyyymm = strtotime("-{$i}months"); ?>
                <option value="<?php echo date('Y-m', $target_yyyymm) ?>" <?php if ($yyyymm == date('Y-m', $target_yyyymm)) echo 'selected' ?>><?php echo date('Y/m', $target_yyyymm) ?></option>
            <?php endfor; ?>
        </select>

        <table class="table table-bordered">
            <thead>
                <tr class="bg-light">
                    <th class="fix-col">日</th>
                    <th class="fix-col">区分</th>
                    <th class="fix-col">出勤</th>
                    <th class="fix-col">退勤</th>
                    <th class="fix-col">休憩</th>
                    <th class="fix-col">勤務時間</th>
                    <th>備考</th>
                    <?php 
                    // ★【編集権限チェック追加】現在表示中の月の編集権限をチェック
                    if (canEditMonth($yyyymm, $session_user)) {
                        echo '<th class="fix-col"></th>';
                    }
                    ?>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_work_minutes = 0;
                $total_work_days = 0;
                $work_type_counts = array();
                $g_work_type_counts = array();
                
                // 区分別カウント用の配列を初期化
                $work_type_options = getWorkTypeOptions();
                foreach ($work_type_options as $key => $value) {
                    $work_type_counts[$key] = 0;
                }
                ?>

                <?php 
                // 区分別カウント用の配列を初期化(現場用)
                $g_work_type_options = getGWorkTypeOptions();
                foreach ($g_work_type_options as $key => $value) {
                    $g_work_type_counts[$key] = 0;
                }
                ?>
                
                <?php for ($i = 1; $i <= $day_count; $i++) : ?>
                    <?php
                    $current_date = $yyyymm . '-' . sprintf('%02d', $i);
                    $row_class = getRowClass($current_date);
                    $is_holiday_or_weekend = (getDayOfWeek($current_date) == 0 || getDayOfWeek($current_date) == 6 || isHoliday($current_date));
                    
                    $start_time = '';
                    $end_time = '';
                    $break_time = '';
                    $work_type = '';
                    $work_type_value = 1;
                    $comment = '';
                    $comment_long = '';
                    $work_time = '';
                    
                    // 現場勤務関連の変数
                    $g_work_type_value = 1;
                    $g_start = '';
                    $g_end = '';
                    $g_break = '';
                    $g_com = '';
                    $g_com_long = '';

                    if (isset($work_list[date('Y-m-d', strtotime($current_date))])) {
                        $work = $work_list[date('Y-m-d', strtotime($current_date))];

                        if ($work['start_time']) {
                            $start_time = format_time_24h($work['start_time']);
                        }

                        if ($work['end_time']) {
                            $end_time = format_time_24h($work['end_time']);
                        }

                        if ($work['break_time']) {
                            $break_time = format_time_24h($work['break_time']);
                        }

                        if ($work['work_type']) {
                            $work_type = getWorkTypeName($work['work_type']);
                            $work_type_value = $work['work_type'];
                            $work_type_counts[$work_type_value]++;
                        }

                        if ($work['comment']) {
                            $comment = mb_strimwidth($work['comment'], 0, 40, '…');
                            $comment_long = $work['comment'];
                        }
                        
                        // 現場勤務関連データの取得
                        $g_work_type_value = $work['g_work_type'] ?: 0;
                        
                        if ($work['g_start']) {
                            $g_start = format_time_24h($work['g_start']);
                        }
                        
                        if ($work['g_end']) {
                            $g_end = format_time_24h($work['g_end']);
                        }
                        
                        if ($work['g_break']) {
                            $g_break = format_time_24h($work['g_break']);
                        }
                        
                        if ($work['g_com']) {
                            $g_com_long = $work['g_com'];
                        }
                        
                        // 勤務時間を計算
                        $work_minutes = calculateWorkMinutes_24h($start_time, $end_time, $break_time);
                        if ($work_minutes > 0) {
                            $work_time = minutesToTime($work_minutes);
                            $total_work_minutes += $work_minutes;
                            $total_work_days++;
                        }
                    }

                    // 警告条件の判定
                    $has_work_data = ($start_time || $end_time || $break_time || $comment_long);
                    $should_warn = false;
                    
                    // 公休の場合で何かしらの値が入っている場合
                    if ($work_type_value == 8 && $has_work_data) {
                        $should_warn = true;
                    }

                    // 欠勤の場合で何かしらの値が入っている場合
                    if ($work_type_value == 5 && $has_work_data) {
                        $should_warn = true;
                    }
                    
                    // 休祝日で何かしらの値が入っている場合
                    if ($is_holiday_or_weekend && $has_work_data) {
                        $should_warn = true;
                    }

					//★2025/09/28新しい警告条件を追加：欠勤と公休以外で退勤時間が登録されていない時
                    if ($work_type_value != 5 && $work_type_value != 8 && $start_time && !$end_time) {
                        $should_warn = true;
                    }

                    //★2025/09/28新しい警告条件を追加：出勤時間と退勤時間が登録されているのに勤務時間が計算できない、またはマイナス値の場合
                    if ($work_type_value != 5 && $work_type_value != 8 && $start_time && $end_time) {
                        $work_minutes = calculateWorkMinutes_24h($start_time, $end_time, $break_time);
                        if ($work_minutes <= 0) {
                            $should_warn = true;
                        }
                    }

					//★2025/09/30新しい警告条件を追加：帰社日で出勤時間が登録されていない時
                    if ($work_type_value == 9 && !$start_time) {
                        $should_warn = true;
                    }

					// ★0928削除ボタン表示の判定条件を修正
					// データベースに何らかの勤怠レコードが存在する場合は削除ボタンを表示
					if (isset($work_list[date('Y-m-d', strtotime($current_date))])) {
						$has_work_data = true; // データベースにレコードが存在する場合は削除ボタンを表示
					} else {
						$has_work_data = false; // データベースにレコードが存在しない場合は削除ボタンを非表示
					}

                    $warning_class = $should_warn ? 'warning-text' : '';
                    ?>
                    <tr class="<?php echo $row_class ?>">
                        <th scope="row"><?php echo time_format_dw($current_date) ?></th>
                        <td class="<?php echo $warning_class ?>"><?php echo h($work_type) ?></td>
                        <td class="<?php echo $warning_class ?>"><?php echo $start_time ?></td>
                        <td class="<?php echo $warning_class ?>"><?php echo $end_time ?></td>
                        <td class="<?php echo $warning_class ?>"><?php echo $break_time ?></td>
                        <td class="<?php echo $warning_class ?>"><?php echo $work_time ?></td>
                        <td class="<?php echo $warning_class ?>"><?php echo h($comment) ?></td>
                        <td class="d-none"><?php echo h($comment_long) ?></td>
                        <td class="d-none"><?php echo $work_type_value ?></td>
                        <!-- 現場勤務関連の隠し項目 -->
                        <td class="d-none"><?php echo $g_work_type_value ?></td>
                        <td class="d-none"><?php echo $g_start ?></td>
                        <td class="d-none"><?php echo $g_end ?></td>
                        <td class="d-none"><?php echo $g_break ?></td>
                        <td class="d-none"><?php echo h($g_com_long) ?></td>
                        <?php 
                        // ★【編集権限チェック追加】編集可能な月のみボタンを表示
                        if (canEditMonth($yyyymm, $session_user)): 
                        ?>
                        <td>
                            <button type="button" class="btn btn-default h-auto py-0" data-toggle="modal" data-target="#inputModal" data-day="<?php echo $current_date ?>">
                                <i class="fa-solid fa-pencil"></i>
                            </button>
                            <?php if ($has_work_data): ?>
                            <button type="button" class="btn btn-danger btn-delete-work" 
                                    data-date="<?php echo $current_date ?>">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <!-- 集計表 -->
        <div class="mt-4 p-3 bg-light border rounded">
            <h5 class="mb-3">勤務集計</h5>
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">勤務時間・日数</h6>
                            <p class="card-text">
                                <strong>総勤務時間:</strong> <?php echo $total_work_minutes > 0 ? minutesToTime($total_work_minutes) : '0:00' ?><br>
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

        <!-- 一括登録ボタン -->
        <div class="mt-3 text-center">
            <button type="button" class="btn btn-warning" data-toggle="modal" data-target="#bulkUploadModal">
                <i class="fas fa-upload"></i> 一括登録
            </button>
        </div>

    </form>

    <!-- Modal -->
    <form method="post">
        <div class="modal fade" id="inputModal" tabindex="-1" aria-labelledby="inputModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <p></p>

                        <h5 class="modal-title" id="exampleModalLavel">日報登録</h5>

                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="container">
                            <div class="alert alert-primary" role="alert">
                                <?php echo date('n', strtotime($yyyymm)) ?>/<span id="modal_day"><?php echo time_format_dw($target_date) ?></span>
                            </div>
                            
                            <!-- 勤務区分 -->
                            <div class="form-group mb-3">
                                <label for="modal_work_type" class="form-label">勤務区分</label>
                                <select class="form-control <?php if (isset($err['modal_work_type'])) echo 'is-invalid'; ?>" id="modal_work_type" name="modal_work_type">
                                    <?php
                                    $work_type_options = getWorkTypeOptions();
                                    foreach ($work_type_options as $value => $text) {
                                        $selected = ($value == $modal_work_type) ? ' selected' : '';
                                        echo '<option value="' . $value . '"' . $selected . '>' . h($text) . '</option>';
                                    }
                                    ?>
                                </select>
                                <div class="invalid-feedback"><?php echo $err['modal_work_type'] ?></div>
                            </div>

                            <div class="row">
                                <div class="col-sm">
                                    <div class="input-group">
										<input type="text" class="form-control <?php if (isset($err['modal_start_time'])) echo 'is-invalid'; ?>" placeholder="出勤" id="modal_start_time" name="modal_start_time" value="<?php echo format_time_24h($modal_start_time) ?>" pattern="[0-3]?[0-9]:[0-5][0-9]" maxlength="5">
                                        <div class="input-group-prepend">
                                            <button type="button" class="input-group-text" id="start_btn">打刻</button>
                                        </div>
                                        <div class="invalid-feedback"><?php echo $err['modal_start_time'] ?></div>
                                    </div>
                                </div>
                                <div class="col-sm">
                                    <div class="input-group">
                                        <input type="text" class="form-control <?php if (isset($err['modal_end_time'])) echo 'is-invalid'; ?>" placeholder="退勤" id="modal_end_time" name="modal_end_time" value="<?php echo format_time_24h($modal_end_time) ?>">
                                        <div class="input-group-prepend">
                                            <button type="button" class="input-group-text" id="end_btn">打刻</button>
                                        </div>
                                        <div class="invalid-feedback"><?php echo $err['modal_end_time'] ?></div>
                                    </div>
                                </div>

                                <div class="col-sm">
                                    <div class="input-group">
                                        <input type="text" class="form-control <?php if (isset($err['modal_break_time'])) echo 'is-invalid'; ?>" placeholder="休憩" id="modal_break_time" name="modal_break_time" value="<?php echo format_time_24h($modal_break_time) ?>">
                                        <div class="invalid-feedback"><?php echo $err['modal_break_time'] ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group pt-3">
                                <textarea class="form-control <?php if (isset($err['modal_comment'])) echo 'is-invalid'; ?>" id="modal_comment" name="modal_comment" rows="3" placeholder="備考"><?php echo isset($modal_comment) ? h($modal_comment) : ''; ?></textarea>
                                <div class="invalid-feedback"><?php echo isset($err['modal_comment']) ? $err['modal_comment'] : ''; ?></div>
                            </div>

                            <!-- 現場勤務セクション -->
                            <div class="field-work-section">
                                <div class="field-work-title">
                                    <i class="fas fa-building"></i> 現場勤務情報
                                </div>
                                
                                <!-- 現場勤務区分 -->
                                <div class="form-group mb-3">
                                    <label for="modal_g_work_type" class="form-label">現場区分</label>
                                    <select class="form-control <?php if (isset($err['modal_g_work_type'])) echo 'is-invalid'; ?>" id="modal_g_work_type" name="modal_g_work_type">
                                        <?php
	                                $g_work_type_options = getGWorkTypeOptions();
                                        foreach ($g_work_type_options as $value => $text) {
                                            $selected = ($value == $modal_g_work_type) ? ' selected' : '';
                                            echo '<option value="' . $value . '"' . $selected . '>' . h($text) . '</option>';
                                        }
                                        ?>
                                    </select>
                                    <div class="invalid-feedback"><?php echo $err['modal_g_work_type'] ?></div>
                                </div>

                                <div class="row">
                                    <div class="col-sm">
                                        <div class="input-group">
											<input type="text" class="form-control <?php if (isset($err['modal_g_start'])) echo 'is-invalid'; ?>" placeholder="現場出勤" id="modal_g_start" name="modal_g_start" value="<?php echo format_time_24h($modal_g_start) ?>" pattern="[0-3]?[0-9]:[0-5][0-9]" maxlength="5">
                                            <div class="input-group-prepend">
                                                <button type="button" class="input-group-text" id="g_start_btn">打刻</button>
                                            </div>
                                            <div class="invalid-feedback"><?php echo $err['modal_g_start'] ?></div>
                                        </div>
                                    </div>
                                    <div class="col-sm">
                                        <div class="input-group">
                                            <input type="text" class="form-control <?php if (isset($err['modal_g_end'])) echo 'is-invalid'; ?>" placeholder="現場退勤" id="modal_g_end" name="modal_g_end" value="<?php echo format_time_24h($modal_g_end) ?>">
                                            <div class="input-group-prepend">
                                                <button type="button" class="input-group-text" id="g_end_btn">打刻</button>
                                            </div>
                                            <div class="invalid-feedback"><?php echo $err['modal_g_end'] ?></div>
                                        </div>
                                    </div>

                                    <div class="col-sm">
                                        <div class="input-group">
                                            <input type="text" class="form-control <?php if (isset($err['modal_g_break'])) echo 'is-invalid'; ?>" placeholder="現場休憩" id="modal_g_break" name="modal_g_break" value="<?php echo format_time_24h($modal_g_break) ?>">
                                            <div class="invalid-feedback"><?php echo $err['modal_g_break'] ?></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group pt-3">
                                    <textarea class="form-control <?php if (isset($err['modal_g_com'])) echo 'is-invalid'; ?>" id="modal_g_com" name="modal_g_com" rows="3" placeholder="現場コメント"><?php echo isset($modal_g_com) ? h($modal_g_com) : ''; ?></textarea>
                                    <div class="invalid-feedback"><?php echo isset($err['modal_g_com']) ? $err['modal_g_com'] : ''; ?></div>
                                </div>
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
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="CSRF_TOKEN" value="<?php echo $_SESSION['CSRF_TOKEN'] ?>">
    </form>

    <!-- 削除用の隠しフォーム -->
    <form method="post" id="delete-work-form" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="delete_date" id="delete_date" value="">
        <input type="hidden" name="CSRF_TOKEN" value="<?php echo $_SESSION['CSRF_TOKEN'] ?>">
    </form>

    <!-- 一括登録Modal -->
    <form method="post" action="bulk_upload.php" enctype="multipart/form-data">
        <div class="modal fade" id="bulkUploadModal" tabindex="-1" aria-labelledby="bulkUploadModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="bulkUploadModalLabel">勤怠一括登録</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="container">
                            <div class="alert alert-info" role="alert">
                                <small>
                                    <strong>フォーマット:</strong><br>
                                    勤務日,社内勤務区分,出勤,退勤,休憩,備考,現場勤務区分,現場出勤,現場退勤,現場休憩,現場備考<br>
                                    <strong>例:</strong> 07/09,1,09:00,18:00,01:00,特になし,1,09:00,18:00,01:00,結合テスト実施<br>
					社内勤務区分(1:通常,2:有休(全),3:有休(午前),4:有休(午後),5:欠勤,6:遅刻,7:早退,8:公休,9:帰社日)<br>
					現場勤務区分(1:通常,2:有休(全),3:有休(午前),4:有休(午後),5:欠勤,6:遅刻,7:早退,8:公休)<br>
                                    <strong>注意:</strong> 既存データがある日は上書きされます
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

    <script src="//code.jquery.com/jquery.js"></script>
    <script src="js/bootstrap.min.js"></script>

    <script>
        // 【2025/07/23修正箇所】出勤時間を15分単位で切り上げ、退勤時間を15分単位で切り捨てるJS関数を追加
        function roundUpTo15Minutes(hour, minute) {
            let roundedMinute;
            if (minute > 0) {
                roundedMinute = Math.ceil(minute / 15) * 15;
                if (roundedMinute >= 60) {
                    hour += 1;
                    roundedMinute = 0;
                }
            } else {
                roundedMinute = 0;
            }
            return {
                hour: hour,
                minute: roundedMinute
            };
        }

        function roundDownTo15Minutes(hour, minute) {
            const roundedMinute = Math.floor(minute / 15) * 15;
            return {
                hour: hour,
                minute: roundedMinute
            };
        }

        <?php if ($modal_view_fig) : ?>
            var inputModal = new bootstrap.Modal(document.getElementById('inputModal'));
            inputModal.toggle();
        <?php endif ?>
        
        // 勤怠データ削除ボタンクリック時の処理
        $(document).on('click', '.btn-delete-work', function() {
            var date = $(this).data('date');
            var dateText = $(this).closest('tr').find('th').text();
            
            if (confirm(dateText + ' の勤怠データを削除しますか？\n※行は残り、登録データのみが削除されます。')) {
                $('#delete_date').val(date);
                $('#delete-work-form').submit();
            }
        });
        
        // // ★0930追加部分：モーダルを開いたときの処理
        // $('#inputModal').on('show.bs.modal', function () {
        //     // 初期化（編集可能状態に戻す）
        //     $('#modal_end_time').prop('disabled', false);
        //     $('#end_btn').prop('disabled', false);

        //     // 勤務区分をチェック
        //     var type = $('#modal_work_type').val();
        //     if (type == 9) { // 帰社日
        //         $('#modal_end_time').val('17:30').prop('disabled', true);
        //         $('#end_btn').prop('disabled', true);
        //     }
        // });

        // 区分変更時の自動入力処理
        $('#modal_work_type').change(function() {
            var workType = $(this).val();
            
            switch(workType) {
                case '2': // 有休(全日)
                    $('#modal_start_time').val('09:00');
                    $('#modal_end_time').val('17:30');
                    $('#modal_break_time').val('01:00');
                    $('#modal_end_time').prop('disabled', false);
                    $('#end_btn').prop('disabled', false);
                    break;
                case '3': // 有休(午前)
                    $('#modal_start_time').val('09:00');
                    $('#modal_end_time').val(''); // 実際の稼働時間を入力
                    $('#modal_break_time').val('01:00');
                    $('#modal_end_time').prop('disabled', false);
                    $('#end_btn').prop('disabled', false);
                    break;
                case '4': // 有休(午後)
                    $('#modal_start_time').val('');
                    $('#modal_end_time').val('17:30');
                    $('#modal_break_time').val('00:00');
                    $('#modal_end_time').prop('disabled', false);
                    $('#end_btn').prop('disabled', false);
                    break;
                case '5': // 欠勤
                    $('#modal_start_time').val('');
                    $('#modal_end_time').val('');
                    $('#modal_break_time').val('');
                    //$('#modal_start_time').val('00:00');
                    //$('#modal_end_time').val('00:00');
                    //$('#modal_break_time').val('00:00');
                    $('#modal_end_time').prop('disabled', false);
                    $('#end_btn').prop('disabled', false);
                    //$('#modal_start_time').prop('disabled', true);
                    //$('#modal_end_time').prop('disabled', true);
                    //$('#modal_break_time').prop('disabled', true);
                    //$('#start_btn').prop('disabled', true);
                    //$('#end_btn').prop('disabled', true);

					// 現場勤務も欠勤に設定
					$('#modal_g_work_type').val('5');
					$('#modal_g_start').val('');
					$('#modal_g_end').val('');
					$('#modal_g_break').val('');
					//$('#modal_g_start').val('00:00');
					//$('#modal_g_end').val('00:00');
					//$('#modal_g_break').val('00:00');
					//$('#modal_g_work_type').prop('disabled', true);
					//$('#modal_g_start').prop('disabled', true);
					//$('#modal_g_end').prop('disabled', true);
					//$('#modal_g_break').prop('disabled', true);
					//$('#g_start_btn').prop('disabled', true);
					//$('#g_end_btn').prop('disabled', true);
					break;
                case '8': // 公休
                    $('#modal_start_time').val('');
                    $('#modal_end_time').val('');
                    $('#modal_break_time').val('');
                    //$('#modal_start_time').val('00:00');
                    //$('#modal_end_time').val('00:00');
                    //$('#modal_break_time').val('00:00');
                    $('#modal_end_time').prop('disabled', false);
                    $('#end_btn').prop('disabled', false);
                    //$('#modal_start_time').prop('disabled', true);
                    //$('#modal_end_time').prop('disabled', true);
                    //$('#modal_break_time').prop('disabled', true);
                    //$('#start_btn').prop('disabled', true);
                    //$('#end_btn').prop('disabled', true);

					// 現場勤務も公休に設定
					$('#modal_g_work_type').val('8');
					$('#modal_g_start').val('');
					$('#modal_g_end').val('');
					$('#modal_g_break').val('');
					//$('#modal_g_start').val('00:00');
					//$('#modal_g_end').val('00:00');
					//$('#modal_g_break').val('00:00');
					//$('#modal_g_work_type').prop('disabled', true);
					//$('#modal_g_start').prop('disabled', true);
					//$('#modal_g_end').prop('disabled', true);
					//$('#modal_g_break').prop('disabled', true);
					//$('#g_start_btn').prop('disabled', true);
					//$('#g_end_btn').prop('disabled', true);
                    break;
                case '9': // 帰社日
                    $('#modal_end_time').val('17:30');
                    $('#modal_break_time').val('01:00');					
                    $('#modal_end_time').prop('disabled', true);
                    $('#end_btn').prop('disabled', true);
                    break;
                default:
                    // その他の区分の場合は休憩時間のみ設定
                    $('#modal_break_time').val('01:00');
                    // その他の区分の場合は明示的に有効設定
                    $('#modal_start_time').prop('disabled', false);
                    $('#modal_end_time').prop('disabled', false);
                    $('#modal_break_time').prop('disabled', false);
                    $('#start_btn').prop('disabled', false);
                    $('#end_btn').prop('disabled', false);

					// 現場勤務も有効に戻す
					$('#modal_g_work_type').prop('disabled', false);
					$('#modal_g_start').prop('disabled', false);
					$('#modal_g_end').prop('disabled', false);
					$('#modal_g_break').prop('disabled', false);
					$('#g_start_btn').prop('disabled', false);
					$('#g_end_btn').prop('disabled', false);
                    break;
            }
        });

        // ★追加部分：モーダルを閉じたときに入力制御をリセット
        $('#inputModal').on('hidden.bs.modal', function () {
            $('#modal_end_time').prop('disabled', false);
        });


        // 現場区分変更時の自動入力処理
        $('#modal_g_work_type').change(function() {
            var workType = $(this).val();
            
            switch(workType) {
                case '2': // 有休(全日)
                    $('#modal_g_start').val('09:00');
                    $('#modal_g_end').val('17:30');
                    $('#modal_g_break').val('01:00');
                    break;
                case '3': // 有休(午前)
                    $('#modal_g_start').val('09:00');
                    $('#modal_g_end').val(''); // 実際の稼働時間を入力
                    $('#modal_g_break').val('01:00');
                    break;
                case '4': // 有休(午後)
                    $('#modal_g_start').val('');
                    $('#modal_g_end').val('17:30');
                    $('#modal_g_break').val('00:00');
                    break;
                case '5': // 欠勤
					$('#modal_g_start').val('');
					$('#modal_g_end').val('');
					$('#modal_g_break').val('');
					//$('#modal_g_start').val('00:00');
					//$('#modal_g_end').val('00:00');
					//$('#modal_g_break').val('00:00');
                    //$('#modal_g_start').prop('disabled', true);
                    //$('#modal_g_end').prop('disabled', true);
                    //$('#modal_g_break').prop('disabled', true);
                    break;
                case '8': // 公休
					$('#modal_g_start').val('');
					$('#modal_g_end').val('');
					$('#modal_g_break').val('');
					//$('#modal_g_start').val('00:00');
					//$('#modal_g_end').val('00:00');
					//$('#modal_g_break').val('00:00');
                    //$('#modal_g_start').prop('disabled', true);
                    //$('#modal_g_end').prop('disabled', true);
                    //$('#modal_g_break').prop('disabled', true);
                    break;
                default:
                    // その他の区分の場合は休憩時間のみ設定
                    $('#modal_g_break').val('01:00');
                    $('#modal_g_start').prop('disabled', false);
                    $('#modal_g_end').prop('disabled', false);
                    $('#modal_g_break').prop('disabled', false);
                    break;
            }
        });

        // 【2025/07/23修正箇所】出勤打刻ボタンを15分単位切り上げに修正
        $('#start_btn').click(function() {
            const now = new Date();
            const rounded = roundUpTo15Minutes(now.getHours(), now.getMinutes());
            const hour = rounded.hour.toString().padStart(2, '0');
            const minute = rounded.minute.toString().padStart(2, '0');
            $('#modal_start_time').val(hour + ':' + minute);
        })
    	// 退勤の打刻ボタンは15分単位切り捨てのまま
        $('#end_btn').click(function() {
            const now = new Date();
            const rounded = roundDownTo15Minutes(now.getHours(), now.getMinutes());
            const hour = rounded.hour.toString().padStart(2, '0');
            const minute = rounded.minute.toString().padStart(2, '0');
            $('#modal_end_time').val(hour + ':' + minute);
        })

        // 現場出勤の打刻ボタンも15分単位切り上げに修正
        $('#g_start_btn').click(function() {
            const now = new Date();
            const rounded = roundUpTo15Minutes(now.getHours(), now.getMinutes());
            const hour = rounded.hour.toString().padStart(2, '0');
            const minute = rounded.minute.toString().padStart(2, '0');
            $('#modal_g_start').val(hour + ':' + minute);
        })
    	// 現場退勤の打刻ボタンは15分単位切り捨てのまま
        $('#g_end_btn').click(function() {
            const now = new Date();
            const rounded = roundDownTo15Minutes(now.getHours(), now.getMinutes());
            const hour = rounded.hour.toString().padStart(2, '0');
            const minute = rounded.minute.toString().padStart(2, '0');
            $('#modal_g_end').val(hour + ':' + minute);
        })

        $('#inputModal').on('show.bs.modal', function(event) {
            var button = $(event.relatedTarget)
            var target_day = button.data('day')

            /*編集ボタンが押された対象日の表データを取得*/
            var day = button.closest('tr').children('th')[0].innerText
            var start_time = button.closest('tr').children('td')[1].innerText
            var end_time = button.closest('tr').children('td')[2].innerText
            var break_time = button.closest('tr').children('td')[3].innerText
            var Work_time = button.closest('tr').children('td')[4].innerText
            var comment = button.closest('tr').children('td')[6].innerText
            var work_type_db = button.closest('tr').children('td')[7].innerText
            
            // 現場勤務関連データの取得（隠し項目から）
            var g_work_type_db = button.closest('tr').children('td')[8].innerText
            var g_start = button.closest('tr').children('td')[9].innerText
            var g_end = button.closest('tr').children('td')[10].innerText
            var g_break = button.closest('tr').children('td')[11].innerText
            var g_com = button.closest('tr').children('td')[12].innerText

            /*取得したデータをモーダルの各欄に設定*/
            $('#modal_day').text(day)
            $('#modal_work_type').val(work_type_db)
            $('#modal_start_time').val(start_time)
            $('#modal_end_time').val(end_time)
            $('#modal_break_time').val(break_time)
            $('#modal_comment').val(comment)
            $('#target_date').val(target_day)
            
            // 現場勤務関連データの設定
            $('#modal_g_work_type').val(g_work_type_db || '1')
            $('#modal_g_start').val(g_start)
            $('#modal_g_end').val(g_end)
            $('#modal_g_break').val(g_break)
            $('#modal_g_com').val(g_com)

            /*エラー表示をクリア*/
            $('#modal_work_type').removeClass('is-invalid')
            $('#modal_start_time').removeClass('is-invalid')
            $('#modal_end_time').removeClass('is-invalid')
            $('#modal_break_time').removeClass('is-invalid')
            $('#modal_comment').removeClass('is-invalid')
            $('#modal_g_work_type').removeClass('is-invalid')
            $('#modal_g_start').removeClass('is-invalid')
            $('#modal_g_end').removeClass('is-invalid')
            $('#modal_g_break').removeClass('is-invalid')
            $('#modal_g_com').removeClass('is-invalid')

            // ★0930追加部分：モーダルを開いたときの処理
            // 初期化（編集可能状態に戻す）
            $('#modal_end_time').prop('disabled', false);
            $('#end_btn').prop('disabled', false);

            // 勤務区分をチェック
            if (work_type_db == 9) { // 帰社日
                $('#modal_end_time').val('17:30').prop('disabled', true);
                $('#end_btn').prop('disabled', true);
            }
        })   

        
        // 新規登録時の初期値設定
        $('#inputModal').on('shown.bs.modal', function(event) {
            var button = $(event.relatedTarget)
            var start_time = button.closest('tr').children('td')[1].innerText
            var end_time = button.closest('tr').children('td')[2].innerText
            var break_time = button.closest('tr').children('td')[3].innerText
            var Work_time = button.closest('tr').children('td')[4].innerText
            var comment = button.closest('tr').children('td')[6].innerText
            var work_type_db = button.closest('tr').children('td')[7].innerText
            
            // 新規登録の場合（データが空の場合）
            if (!start_time && !end_time && !break_time && !comment) {
                // 区分に応じた初期値を設定
                var workType = $('#modal_work_type').val();
                if (workType == '3') { // 有休(午前)
                    $('#modal_break_time').val('00:00');
                    $('#modal_g_break').val('00:00');
                } else {
                    $('#modal_break_time').val('01:00');
                    $('#modal_g_break').val('01:00');
                }
            }
        })

        // 一括登録フォーム送信時の処理
        $('form[action="bulk_upload.php"]').on('submit', function(e) {
            var fileInput = $('#bulk_file')[0];
            var textInput = $('#bulk_data').val().trim();
            
            // ファイルまたはテキストのどちらかが入力されているかチェック
            if ((!fileInput.files || fileInput.files.length === 0) && !textInput) {
                alert('ファイルを選択するか、テキストデータを入力してください。');
                e.preventDefault();
                return false;
            }
            
            // ファイルが選択されている場合のバリデーション
            if (fileInput.files && fileInput.files.length > 0) {
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
            }
            
            // 確認ダイアログ
            if (!confirm('データを一括登録しますか？\n※既存データがある日は上書きされます。')) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });

        // ファイル選択時にテキストエリアをクリア
        $('#bulk_file').on('change', function() {
            if (this.files && this.files.length > 0) {
                $('#bulk_data').val('');
            }
        });

        // テキストエリア入力時にファイル選択をクリア
        $('#bulk_data').on('input', function() {
            if ($(this).val().trim() !== '') {
                $('#bulk_file').val('');
            }
        });
    </script>

</body>

</html>