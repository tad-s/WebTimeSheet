-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- ホスト: mysql624.db.sakura.ne.jp
-- 生成日時: 2026 年 4 月 05 日 20:40
-- サーバのバージョン： 5.7.40-log
-- PHP のバージョン: 8.2.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- データベース: `yokumono49_works`
--

-- --------------------------------------------------------

--
-- テーブルの構造 `user`
--

CREATE TABLE `user` (
  `id` int(11) NOT NULL COMMENT 'ユーザーID',
  `user_no` varchar(4) NOT NULL COMMENT '社員番号',
  `name` varchar(20) NOT NULL COMMENT '名前',
  `password` varchar(60) NOT NULL COMMENT 'パスワード',
  `auth_type` int(11) DEFAULT NULL COMMENT '権限(0:一般,１:管理者)',
  `team_no` int(2) DEFAULT NULL COMMENT 'チームNO',
  `edit_flg` int(11) NOT NULL DEFAULT '0' COMMENT '過去月編集フラグ(0:不可,1:可)',
  `kubun` varchar(2) DEFAULT '00' COMMENT '社員区分',
  `update_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新日',
  `create_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日',
  `zaiseki_f` int(11) DEFAULT '1' COMMENT '在籍フラグ(0:退職,1:在籍,2:休職,3:その他)',
  `join_date` date DEFAULT NULL COMMENT '入社日',
  `retire_date` date DEFAULT NULL COMMENT '退職日'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- テーブルのデータのダンプ `user`
--

INSERT INTO `user` (`id`, `user_no`, `name`, `password`, `auth_type`, `team_no`, `edit_flg`, `kubun`, `update_date`, `create_date`, `zaiseki_f`, `join_date`, `retire_date`) VALUES
(1, '1234', '佐藤テスト用2', '$2y$10$0jwPDlk9ZgH.EnxhNssh/.FTMglzz7P40oSeEUkzYdGyU64R8Mv56', 1, 0, 1, '00', '2025-07-10 05:54:40', '2025-07-10 05:54:40', 3, NULL, NULL),
(2, '0000', 'テスト2', '$2y$10$0jwPDlk9ZgH.EnxhNssh/.FTMglzz7P40oSeEUkzYdGyU64R8Mv56', 1, NULL, 0, '00', '2025-07-10 05:54:40', '2025-07-10 05:54:40', 3, NULL, NULL),
(3, 'e074', '井上裕之', '$2y$10$rakn9ZvypiBGw1Aa3pjXp.s6QnZw6kapVs4KrvLeIpiiFMNPxpgwu', 0, 5, 1, '00', '2025-07-10 05:54:40', '2025-07-10 05:54:40', 1, NULL, NULL),
(4, 'e073', '佐藤直吏', '$2y$10$SAe.mKusun3XkGqRM39SbeGaRgMS2EwjdgKmmABA1vGdIAGITXaHO', 1, 5, 0, '00', '2025-07-12 15:36:44', '2025-07-12 15:36:44', 1, NULL, NULL),
(5, 'e001', '柴田尚範', '$2y$10$KohYPFL/7Zm.TsSf.ZPYbu8.PBYn3IRLd/lJxbr04QhIIAQTpIsdO', 1, 0, 0, '00', '2025-07-12 16:28:55', '2025-07-12 16:28:55', 1, NULL, NULL),
(6, 'e081', '臼木陽司', '$2y$10$3cy8FRnfpEBZi.zUcW7YfOlAgywYI2FXe36xC6yyj7SDpeGOfx.SW', 0, 3, 0, '00', '2025-07-12 17:53:37', '2025-07-12 17:53:37', 1, NULL, NULL),
(8, 'e082', '津久井隆輝', '$2y$10$DKCfHyLg5ZSeHWlm5uHCP.10Ag2yQK9nj1PcXm2F1fz8mOMeDt07a', 0, 5, 0, '00', '2025-07-12 18:03:57', '2025-07-12 18:03:57', 3, NULL, NULL),
(9, 'e083', '荻野翔太', '$2y$10$O.l6mou/RHs8iGHGRH9oeeMtV5PZoActyNtwn7kEMa1MqCf2goHZ2', 0, 2, 0, '00', '2025-07-12 18:04:23', '2025-07-12 18:04:23', 3, NULL, NULL),
(10, 'e051', '町田清剛', '$2y$10$MitUxKYSaJ1L2vae/cKs/.OjpsNCvch7DlHQgiwHNf2Qu0mbma3CK', 1, 2, 0, '00', '2025-07-22 09:34:41', '2025-07-22 09:34:41', 1, NULL, NULL),
(11, 'e058', '木島彩梨沙', '$2y$10$u3ZgshIHf2Nvmc.LvnPEeeRfLo.D1xpPwAbr.byEY4dDIK66Appfm', 0, 5, 0, '00', '2025-07-22 09:35:47', '2025-07-22 09:35:47', 1, NULL, NULL),
(12, 'e072', '坂本勝己', '$2y$10$3yR668V6Dt6e3DHiFlGj3u1xKAvyYEwFcYooT8vwD3VewGtOdUuPO', 1, 4, 0, '00', '2025-08-18 01:18:54', '2025-08-18 01:18:54', 1, NULL, NULL),
(13, 'e011', '金岩祐介', '$2y$10$kZT/Yw7iSDYG0tuPR/8Hc.8T9BpzbGxRWbHs0g9KQ8dFzbXsKzoQS', 0, 4, 0, '00', '2025-09-22 03:47:20', '2025-09-22 03:47:20', 1, NULL, NULL),
(14, 'e013', '松岡斉泰', '$2y$10$qly4YTdG8Y1SbqIuXVHoVuFA2J52wbjb9TFMToa/effdCFRAUQ42y', 0, 10, 0, '00', '2025-09-22 03:49:01', '2025-09-22 03:49:01', 1, NULL, NULL),
(15, 'e038', '塩原大介', '$2y$10$TkEcMY68y32o6RjA.KbWNub4zjyoyG13eRtR8qdh/BW4g.EsO.wx2', 0, 2, 0, '00', '2025-09-22 03:50:07', '2025-09-22 03:50:07', 1, NULL, NULL),
(16, 'e041', '増﨑裕紀', '$2y$10$hh.dp6fzVNdRXvvtNyZvnugWGVPKnf7ZWO4FOil4BQKk7OAsbrYyy', 0, 4, 0, '00', '2025-09-22 03:53:31', '2025-09-22 03:53:31', 1, NULL, NULL),
(17, 'e043', '川島健一', '$2y$10$hAo8J3hTptz0zmh.NJRD8O3TITPm/nsaVB8eLoCUnxKy6m/nZ7o8a', 0, 1, 0, '00', '2025-09-22 04:53:19', '2025-09-22 04:53:19', 1, NULL, NULL),
(18, 'e050', '大沼智夏(退職)', '$2y$10$ExItG6d9/xiDi0cNPlMjhOAO8QZxDhHIJYJCZRu8gXrWgWyYVSs6m', 0, 99, 0, '00', '2025-09-22 04:53:52', '2025-09-22 04:53:52', 1, NULL, NULL),
(19, 'e054', '石井佑弥', '$2y$10$jDo9chQGpFPz./ujbj/YhOmCLMjTEqvDcciS1d4TEWOZhpcSMMYAS', 1, 3, 0, '00', '2025-09-22 04:54:17', '2025-09-22 04:54:17', 1, NULL, NULL),
(20, 'e055', '滝本可純', '$2y$10$zJD1bDyKEkonVhc001AKSu/O4CYgxTVzPrUjdv5cel49726alMzBS', 0, 3, 0, '00', '2025-09-22 05:03:13', '2025-09-22 05:03:13', 1, NULL, NULL),
(21, 'e056', '間瀬田渉', '$2y$10$eSAzdW9X6OSVdaQSuNpymecpsk/64YqxQ8.LWZJLUlWc8aGgfKxCa', 0, 3, 0, '00', '2025-09-22 05:03:41', '2025-09-22 05:03:41', 1, NULL, NULL),
(22, 'e059', '龍宮克広', '$2y$10$3wd0ieXz5pgrlo4MYvQ.T.hqQBIr.SwUH6IXJfe17meASY5oH3Da6', 0, 4, 0, '00', '2025-09-22 05:04:01', '2025-09-22 05:04:01', 1, NULL, NULL),
(23, 'e060', '神保優翔', '$2y$10$Y9sZlqC7XxzHb1ElmNbPQOCeqSRy8aLceoQStZcZ2tcH6kdOvm/Le', 0, 3, 0, '00', '2025-09-22 05:04:15', '2025-09-22 05:04:15', 1, NULL, NULL),
(24, 'e061', '追分宥二', '$2y$10$c5fW0gVEZig84DbjsWAfyOyNFu/iXqBateegJJxLXXA/PED1o6lg6', 0, 1, 0, '00', '2025-09-22 05:04:28', '2025-09-22 05:04:28', 1, NULL, NULL),
(25, 'e063', '岡田将治', '$2y$10$ui9nykXnXkvvBjVq4LzY9uySUxB0BycOYy51UN0wiHS4vEMMZN1pu', 0, 4, 0, '00', '2025-09-22 05:04:46', '2025-09-22 05:04:46', 1, NULL, NULL),
(26, 'e066', '早川史晃', '$2y$10$RsFAEbt7MqqU/x3.ascizOK.zvaFdtbf51pzNIznQdmpMBXqsdKnC', 0, 2, 0, '00', '2025-09-22 05:05:35', '2025-09-22 05:05:35', 1, NULL, NULL),
(27, 'e067', '星野心哉', '$2y$10$h7Voc3SwF6zUucne7mZNAea4Q/lzdbM9ibKelNDplVOG6ExGcWF7S', 0, 4, 0, '00', '2025-09-22 05:05:59', '2025-09-22 05:05:59', 1, NULL, NULL),
(28, 'e068', '近久瑞紀', '$2y$10$pD7/o.5PDJbne74I22f8U.w0ePHhdsq1MRI6x5EUmI2SJhLQ8W9be', 0, 2, 0, '00', '2025-09-22 05:06:27', '2025-09-22 05:06:27', 1, NULL, NULL),
(29, 'e069', '片山紗綾', '$2y$10$Iq.mdlXuY0OjduHzpA/fZuamf1SAPAaP1rx8bNUPDOdw46CPdCMXO', 0, 2, 0, '00', '2025-09-22 05:06:42', '2025-09-22 05:06:42', 1, NULL, NULL),
(30, 'e070', '去田彩華', '$2y$10$DWTx8WZYOj8qf9wZP4wbB.3Ois6Wz1UTd4vUegKsguHVMXx0r3/Bq', 0, 2, 0, '00', '2025-09-22 05:07:40', '2025-09-22 05:07:40', 1, NULL, NULL),
(31, 'e071', '佐野ポール', '$2y$10$LtBaq2gYgN84B8MbXwT.AOnQpZViEOotsxIO5MWZESLyX9ZRmSqNO', 0, 1, 0, '00', '2025-09-22 05:09:16', '2025-09-22 05:09:16', 1, NULL, NULL),
(32, 'e075', '栄幸祐', '$2y$10$/CwtoLwbeQ21UZ5XpICQT.xG7Ro.YGOCj9wFvNJc2w4dGbuhlosqW', 1, 5, 0, '00', '2025-09-22 05:11:23', '2025-09-22 05:11:23', 1, NULL, NULL),
(33, 'e076', '高田優希', '$2y$10$a4YPad1aJhxI.V.Q8.TJnewVAjt6YkQ.Ujz684N29LupEvQmwepam', 0, 5, 0, '00', '2025-09-22 05:11:41', '2025-09-22 05:11:41', 1, NULL, NULL),
(34, 'e077', '田中里咲', '$2y$10$1sNyFq.IqjDpkuV5PX4/C.8hpQiiHtS.yBiDLaR77d.ZKnVsouOry', 0, 5, 0, '00', '2025-09-22 05:11:56', '2025-09-22 05:11:56', 1, NULL, NULL),
(35, 'e078', '髙嶋幸太', '$2y$10$UVQJGudnM6r.kK7NYXePCuEgJztUpNv8g1ndbzCNDvBwcf0Nr5sRi', 0, 5, 0, '00', '2025-09-22 05:12:18', '2025-09-22 05:12:18', 1, NULL, NULL),
(36, 'e080', 'グエンカート', '$2y$10$XBUwaAvejGJZfeBrXD1omuDgFCNHhdi.jjdxu0ublIpz3Sh1Ls6b.', 0, 5, 0, '00', '2025-09-22 05:12:38', '2025-09-22 05:12:38', 1, NULL, NULL),
(37, 'e084', '舘澤怜美', '$2y$10$a0kXFyuLcIcg6ffLKaI2HOq2SRz45nyaCwA8t4EY0Xlsymh22KJQG', 0, 2, 0, '00', '2025-09-22 05:13:15', '2025-09-22 05:13:15', 1, NULL, NULL),
(38, 'e085', '古井智也', '$2y$10$WiEPJPD.DsZ9k1NrftgBrechMXBv4NE9U2K.C4z4.cUMMbwYIr48y', 0, 10, 0, '00', '2025-09-22 05:13:32', '2025-09-22 05:13:32', 1, NULL, NULL),
(39, 'e014', '谷口順一', '$2y$10$1hDQfugMAD2Bb55MRUDS1.9yVksMBpdu4Z6/xp2RYNgLgJRYxmReK', 1, 1, 0, '00', '2025-09-22 05:15:17', '2025-09-22 05:15:17', 1, NULL, NULL),
(40, 'e086', '木下翔太', '$2y$10$AfWDXZiBZ1PEG4WGU6Q0H.PsnPgflbsZPFet1GfKBKT4yD/8YSaIS', 0, 2, 0, '00', '2026-01-29 06:45:21', '2026-01-29 06:45:21', 1, NULL, NULL),
(41, 'e087', '新井伸', '$2y$10$6pt47IRYgfP4nzTnlVCUX.o5DngDKKAKpGuQPpNvbHNrm0VJX7j56', 0, 10, 0, '00', '2026-03-12 14:54:57', '2026-03-12 14:54:57', 1, NULL, NULL);

--
-- ダンプしたテーブルのインデックス
--

--
-- テーブルのインデックス `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`id`);

--
-- ダンプしたテーブルの AUTO_INCREMENT
--

--
-- テーブルの AUTO_INCREMENT `user`
--
ALTER TABLE `user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'ユーザーID', AUTO_INCREMENT=42;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
