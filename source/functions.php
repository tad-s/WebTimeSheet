<?php
// データベースに接続する
function connectDb()
{
    $param = "mysql:dbname=" . DB_NAME . ";host=" . DB_HOST;

    try {
        $pdo = new PDO($param, DB_USER, DB_PASSWORD);
        $pdo->query('SET NAMES utf8;');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        echo $e->getMessage();
        exit;
    }
}

// 時間を15分単位で切り捨てる関数
function roundDownTo15Minutes($time)
{
    if (!$time || $time === '00:00:00' || $time === '00:00') {
        return $time;
    }
    
    // HH:MM形式またはHH:MM:SS形式の時間文字列を処理
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

// 一般ユーザーの編集権限をチェックする関数
function canEditMonth($yyyymm, $user)
{
    // 管理者権限またはedit_flgが1の場合は常に編集可能
    if ($user['auth_type'] == 1 || $user['edit_flg'] == 1) {
        return true;
    }
    
    // 一般ユーザーの場合
    if ($user['auth_type'] == 0) {
        $current_date = new DateTime();
        $current_year = $current_date->format('Y');
        $current_month = $current_date->format('m');
        $current_day = (int)$current_date->format('j');
        
        $target_date = new DateTime($yyyymm . '-01');
        $target_year = $target_date->format('Y');
        $target_month = $target_date->format('m');
        
        // 当月は編集可能
        if ($target_year == $current_year && $target_month == $current_month) {
            return true;
        }
        
        // 先月の場合、当月10日まで編集可能
        $last_month = new DateTime();
        $last_month->modify('first day of last month');
        if ($target_year == $last_month->format('Y') && $target_month == $last_month->format('m')) {
            return $current_day <= 10;
        }
        
        // それ以外は編集不可
        return false;
    }
    
    return false;
}

//日付を日(曜日)の形式に変換する
function time_format_dw($date)
{
    $format_date = NULL;
    $week = array('日', '月', '火', '水', '木', '金', '土');

    if ($date) {
        $format_date = date('j(' . $week[date('w', strtotime($date))] . ')', strtotime($date));
    }
    return $format_date;
}

// 時間のデータ形式を調整する
function format_time($value)
{
    if (!$value || $value == '00:00:00') {
        return NULL;
    } else {
        return date('H:i', strtotime($value));
    }
}

//スクリプト対策でDBに登録するtextを確認
function h($original_str)
{
    return htmlspecialchars($original_str, ENT_QUOTES, "UTF-8");
}



// ★新規：純粋な祝日（固定/ハッピーマンデー/春分秋分）だけを判定
//  - 振替休日と国民の休日は含めない
function isHolidayBase($date)
{
    $year  = (int)date('Y', strtotime($date));
    $month = (int)date('n', strtotime($date));
    $day   = (int)date('j', strtotime($date));
    $w     = (int)date('w', strtotime($date)); // 0=日

    // 固定祝日（2025年現在）
    $fixed = array(
        '01-01', // 元日
        '02-11', // 建国記念の日
        '02-23', // 天皇誕生日
        '04-29', // 昭和の日
        '05-03', // 憲法記念日
        '05-04', // みどりの日
        '05-05', // こどもの日
        '08-11', // 山の日
        '11-03', // 文化の日
        '11-23', // 勤労感謝の日
    );
    $key = sprintf('%02d-%02d', $month, $day);
    if (in_array($key, $fixed, true)) return true;

    // ハッピーマンデー
    $weekOfMonth = (int)ceil($day / 7);
    // 成人の日（1月第2月曜）
    if ($month == 1  && $w == 1 && $weekOfMonth == 2) return true;
    // 海の日（7月第3月曜）
    if ($month == 7  && $w == 1 && $weekOfMonth == 3) return true;
    // 敬老の日（9月第3月曜）
    if ($month == 9  && $w == 1 && $weekOfMonth == 3) return true;
    // スポーツの日（10月第2月曜）
    if ($month == 10 && $w == 1 && $weekOfMonth == 2) return true;

    // 春分・秋分（近似式）
    if ($month == 3) {
        $vernal = (int)floor(20.8431 + 0.242194 * ($year - 1980) - floor(($year - 1980)/4));
        if ($day == $vernal) return true;
    }
    if ($month == 9) {
        $autumn = (int)floor(23.2488 + 0.242194 * ($year - 1980) - floor(($year - 1980)/4));
        if ($day == $autumn) return true;
    }

    return false;
}

// ★新規：国民の休日判定
//  - 前日と翌日がいずれも「祝日（※ここでは base のみ）」なら当日を祝日扱い
//  - 例：2026/9/22（敬老の日と秋分の日に挟まれた火曜）
function isCitizenHoliday($date)
{
    if (isHolidayBase($date)) return false; // 当日が既に祝日なら対象外
    $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
    $tomorrow  = date('Y-m-d', strtotime($date . ' +1 day'));
    return (isHolidayBase($yesterday) && isHolidayBase($tomorrow));
}

// 振替休日判定
// - 「祝日が日曜日に当たった場合、その直後から続く“祝日列”の終端の次の平日を振替休日」
// - 連続祝日（例：5/3日→5/4祝→5/5祝→5/6振休）を正しく拾う
function isSubstituteHoliday($date) {
    // 当日がベース祝日/国民の休日なら振替ではない
    if (isHolidayBase($date) || isCitizenHoliday($date)) return false;

    // 直前に連続する祝日列を収集（※振替は含めない）
    $block = [];
    $d = date('Y-m-d', strtotime($date . ' -1 day'));
    while (isHolidayBase($d) || isCitizenHoliday($d)) {
        $block[] = $d;
        $d = date('Y-m-d', strtotime($d . ' -1 day'));
    }
    if (empty($block)) return false;

    // ★祝日列のどこかに「日曜日」が含まれていれば振替成立
    foreach ($block as $h) {
        if ((int)date('w', strtotime($h)) === 0) {
            return true;
        }
    }
    return false;
}

// ★統合：最終的な祝日判定
//  - ベース祝日 OR 国民の休日 OR 振替休日
function isHoliday($date)
{
    return isHolidayBase($date)
        || isCitizenHoliday($date)
        || isSubstituteHoliday($date);
}

// 日付の曜日を取得する（0:日曜日 〜 6:土曜日）
function getDayOfWeek($date)
{
    return date('w', strtotime($date));
}

// 行のCSSクラスを取得する
function getRowClass($date)
{
    $dayOfWeek = getDayOfWeek($date);
    
    if (isHoliday($date)) {
        return 'holiday-row';
    } elseif ($dayOfWeek == 0) { // 日曜日
        return 'sunday-row';
    } elseif ($dayOfWeek == 6) { // 土曜日
        return 'saturday-row';
    }
    
    return '';
}

// トークンを発行する処理
function set_token()
{
    $token = sha1(uniqid(mt_rand(), true));
    $_SESSION['CSRF_TOKEN'] = $token;
}

// トークンをチェックする処理
function check_token()
{
    if (empty($_SESSION['CSRF_TOKEN']) || ($_SESSION['CSRF_TOKEN'] != $_POST['CSRF_TOKEN'])) {
        unset($pdo);
        header('Location:http://localhost/dev/works/web/error.php');
        exit;
    }
}

// ユーザIDからuserを検索する
function getUserbyUserId($user_id, $pdo)
{
    $sql = "SELECT * FROM user WHERE id = :user_id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(":user_id" => $user_id));
    $user = $stmt->fetch();

    return $user ? $user : false;
}

// 名前の存在チェック
function checkName($user_name, $pdo)
{
    $sql = "SELECT * FROM user WHERE user_name = :user_name LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(":user_name" => $user_name));
    $name = $stmt->fetch();
    return $name ? true : false;
}

// 勤務時間を計算する（分単位で返す）
function calculateWorkMinutes($start_time, $end_time, $break_time)
{
    if (!$start_time || !$end_time) {
        return 0;
    }
    
    $start_minutes = timeToMinutes($start_time);
    $end_minutes = timeToMinutes($end_time);
    $break_minutes = $break_time ? timeToMinutes($break_time) : 0;
    
    if ($end_minutes <= $start_minutes) {
        return 0;
    }
    
    $work_minutes = $end_minutes - $start_minutes - $break_minutes;
    return $work_minutes > 0 ? $work_minutes : 0;
}

// 時間文字列を分に変換する
function timeToMinutes($time)
{
    if (!$time) return 0;
    $parts = explode(':', $time);
    return (int)$parts[0] * 60 + (int)$parts[1];
}

// 分を時間文字列に変換する
function minutesToTime($minutes)
{
    if ($minutes <= 0) return '';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%d:%02d', $hours, $mins);
}

// 勤務時間を取得する（HH:MM形式）
function getWorkTimeString($start_time, $end_time, $break_time)
{
    $minutes = calculateWorkMinutes($start_time, $end_time, $break_time);
    return minutesToTime($minutes);
}

// 勤務区分の選択肢を取得する
function getWorkTypeOptions()
{
    return array(
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
}

// 現場勤務区分の選択肢を取得する
function getGWorkTypeOptions()
{
    return array(
        1 => '通常',
        2 => '有休(全日)',
        3 => '有休(午前)',
        4 => '有休(午後)',
        5 => '欠勤',
        6 => '遅刻',
        7 => '早退',
        8 => '公休'
    );
}

// 勤務区分のDBの値から表示名を取得する
function getWorkTypeName($work_type)
{
    $options = getWorkTypeOptions();
    return isset($options[$work_type]) ? $options[$work_type] : '';
}

// 勤務区分のプルダウンHTMLを生成する
function generateWorkTypeSelect($name, $selected_value = 1, $class = '')
{
    $options = getWorkTypeOptions();
    $html = '<select name="' . $name . '" class="' . $class . '">';
    
    foreach ($options as $value => $text) {
        $selected = ($value == $selected_value) ? ' selected' : '';
        $html .= '<option value="' . $value . '"' . $selected . '>' . h($text) . '</option>';
    }
    
    $html .= '</select>';
    return $html;
}
?>