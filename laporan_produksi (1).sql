-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 25 Okt 2026 pada 20.37
-- Versi server: 10.4.32-MariaDB
-- Versi PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `laporan_produksi`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `audit_koreksi_laporan`
--

CREATE TABLE `audit_koreksi_laporan` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `aksi` varchar(80) NOT NULL,
  `entitas` varchar(100) NOT NULL,
  `entitas_id` bigint(20) UNSIGNED NOT NULL,
  `data_sebelum` longtext NOT NULL,
  `data_sesudah` longtext NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `downtime`
--

CREATE TABLE `downtime` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `laporan_id` bigint(20) UNSIGNED NOT NULL,
  `waktu_mulai` datetime DEFAULT NULL,
  `waktu_selesai` datetime DEFAULT NULL,
  `durasi_menit` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `penyebab` varchar(150) NOT NULL,
  `keterangan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `downtime`
--

INSERT INTO `downtime` (`id`, `laporan_id`, `waktu_mulai`, `waktu_selesai`, `durasi_menit`, `penyebab`, `keterangan`, `created_at`) VALUES
(1, 1, '2026-10-15 22:26:33', '2026-10-15 22:27:16', 0, 'Ganti Material', '', '2026-10-15 15:26:33'),
(2, 1, '2026-10-15 22:27:39', '2026-10-15 22:28:06', 0, 'Ganti Mold', '', '2026-10-15 15:27:39'),
(3, 3, '2026-10-16 18:01:42', '2026-10-16 18:04:13', 2, 'Listrik Mati', '', '2026-10-16 11:01:42'),
(4, 4, '2026-10-16 19:55:50', '2026-10-16 23:32:43', 216, 'Menunggu Material', '', '2026-10-16 12:55:50'),
(5, 4, '2026-10-17 00:17:54', '2026-10-17 00:18:08', 0, 'Cleaning Mesin', '', '2026-10-16 17:17:54'),
(6, 7, '2026-10-18 00:55:21', '2026-10-18 00:58:11', 2, 'Cleaning Mesin', '', '2026-10-17 17:55:21'),
(7, 13, '2026-10-22 15:28:30', '2026-10-22 16:01:04', 32, 'Menunggu Material', '', '2026-10-22 08:28:30');

-- --------------------------------------------------------

--
-- Struktur dari tabel `laporan_ng_kategori`
--

CREATE TABLE `laporan_ng_kategori` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `laporan_id` bigint(20) UNSIGNED NOT NULL,
  `kategori_ng_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `laporan_ng_kategori`
--

INSERT INTO `laporan_ng_kategori` (`id`, `laporan_id`, `kategori_ng_id`, `created_at`) VALUES
(5, 11, 1, '2026-10-22 08:27:33'),
(6, 11, 3, '2026-10-22 08:27:33');

-- --------------------------------------------------------

--
-- Struktur dari tabel `laporan_produksi_jam`
--

CREATE TABLE `laporan_produksi_jam` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `planning_id` bigint(20) UNSIGNED NOT NULL,
  `slot_jam` tinyint(3) UNSIGNED NOT NULL,
  `waktu_mulai` datetime NOT NULL,
  `waktu_selesai` datetime NOT NULL,
  `operator_id` int(10) UNSIGNED NOT NULL,
  `jumlah_ok` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `jumlah_ng` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `keterangan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `counter_awal` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `counter_akhir` int(10) UNSIGNED NOT NULL DEFAULT 0
) ;

--
-- Dumping data untuk tabel `laporan_produksi_jam`
--

INSERT INTO `laporan_produksi_jam` (`id`, `planning_id`, `slot_jam`, `waktu_mulai`, `waktu_selesai`, `operator_id`, `jumlah_ok`, `jumlah_ng`, `keterangan`, `created_at`, `updated_at`, `counter_awal`, `counter_akhir`) VALUES
(1, 2, 4, '2026-10-14 10:30:00', '2026-10-14 11:30:00', 2, 0, 0, '', '2026-10-15 15:26:33', '2026-10-15 15:26:33', 0, 0),
(2, 5, 1, '2026-10-15 07:30:00', '2026-10-15 08:30:00', 2, 30, 0, '', '2026-10-15 16:57:49', '2026-10-15 16:57:49', 500010, 500040),
(3, 5, 2, '2026-10-15 08:30:00', '2026-10-15 09:30:00', 2, 33, 2, '', '2026-10-16 10:58:44', '2026-10-16 10:58:44', 0, 0),
(4, 3, 1, '2026-10-14 07:30:00', '2026-10-14 08:30:00', 2, 42, 2, '', '2026-10-16 12:53:25', '2026-10-16 17:53:09', 0, 0),
(5, 6, 1, '2026-10-16 15:30:00', '2026-10-16 16:30:00', 2, 25, 1, '', '2026-10-16 19:02:00', '2026-10-16 19:02:00', 0, 0),
(6, 7, 1, '2026-10-17 07:30:00', '2026-10-17 08:30:00', 2, 12, 0, '', '2026-10-17 08:48:50', '2026-10-17 08:49:16', 0, 0),
(7, 7, 2, '2026-10-17 08:30:00', '2026-10-17 09:30:00', 2, 5, 0, '', '2026-10-17 08:53:20', '2026-10-17 08:53:20', 0, 0),
(8, 7, 3, '2026-10-17 09:30:00', '2026-10-17 10:30:00', 2, 10, 2, '', '2026-10-17 17:57:28', '2026-10-17 17:57:28', 0, 0),
(9, 10, 1, '2026-10-21 23:30:00', '2026-10-22 00:30:00', 2, 40, 0, '', '2026-10-22 07:46:07', '2026-10-22 07:46:07', 0, 0),
(10, 10, 2, '2026-10-22 00:30:00', '2026-10-22 01:30:00', 2, 38, 2, '', '2026-10-22 07:46:21', '2026-10-22 07:46:44', 0, 0),
(11, 10, 3, '2026-10-22 01:30:00', '2026-10-22 02:30:00', 2, 36, 4, '', '2026-10-22 08:27:14', '2026-10-22 08:27:14', 0, 0),
(12, 10, 4, '2026-10-22 02:30:00', '2026-10-22 03:30:00', 2, 40, 0, '', '2026-10-22 08:28:06', '2026-10-22 08:28:06', 0, 0),
(13, 10, 5, '2026-10-22 03:30:00', '2026-10-22 04:30:00', 2, 28, 0, '', '2026-10-22 08:28:20', '2026-10-22 09:01:31', 0, 0),
(14, 10, 6, '2026-10-22 04:30:00', '2026-10-22 05:30:00', 2, 40, 0, '', '2026-10-22 09:01:51', '2026-10-22 09:01:51', 0, 0),
(15, 10, 7, '2026-10-22 05:30:00', '2026-10-22 06:30:00', 2, 40, 0, '', '2026-10-22 09:02:07', '2026-10-22 09:02:07', 0, 0),
(16, 10, 8, '2026-10-22 06:30:00', '2026-10-22 07:30:00', 2, 40, 0, '', '2026-10-22 09:02:15', '2026-10-22 09:02:15', 0, 0);

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_downtime`
--

CREATE TABLE `master_downtime` (
  `id` int(11) NOT NULL,
  `nama_downtime` varchar(100) NOT NULL,
  `keterangan` text DEFAULT NULL,
  `status` enum('AKTIF','NONAKTIF') NOT NULL DEFAULT 'AKTIF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `master_downtime`
--

INSERT INTO `master_downtime` (`id`, `nama_downtime`, `keterangan`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Ganti Material', 'Pergantian bahan baku produksi', 'AKTIF', '2026-10-15 10:02:38', '2026-10-15 10:02:38'),
(2, 'Setting Mesin', 'Penyesuaian parameter mesin', 'AKTIF', '2026-10-15 10:02:38', '2026-10-15 10:02:38'),
(3, 'Trouble Mesin', 'Gangguan atau kerusakan mesin', 'AKTIF', '2026-10-15 10:02:38', '2026-10-15 10:02:38'),
(4, 'Ganti Mold', 'Pergantian mold atau cetakan', 'AKTIF', '2026-10-15 10:02:38', '2026-10-15 10:02:38'),
(5, 'Menunggu Material', 'Material belum tersedia', 'AKTIF', '2026-10-15 10:02:38', '2026-10-15 10:02:38'),
(6, 'Menunggu Teknisi', 'Menunggu bantuan teknisi', 'AKTIF', '2026-10-15 10:02:38', '2026-10-15 10:02:38'),
(7, 'Menunggu QC / IPQC', 'Menunggu pemeriksaan kualitas', 'AKTIF', '2026-10-15 10:02:38', '2026-10-15 10:02:38'),
(8, 'Listrik Mati', 'Gangguan suplai listrik', 'AKTIF', '2026-10-15 10:02:38', '2026-10-15 10:02:38'),
(9, 'Cleaning Mesin', 'Pembersihan mesin atau area kerja', 'AKTIF', '2026-10-15 10:02:38', '2026-10-15 10:02:38'),
(10, 'Lain-lain', 'Penyebab downtime lainnya', 'AKTIF', '2026-10-15 10:02:38', '2026-10-15 10:02:38');

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_kategori_ng`
--

CREATE TABLE `master_kategori_ng` (
  `id` int(10) UNSIGNED NOT NULL,
  `nama_kategori` varchar(100) NOT NULL,
  `status` enum('AKTIF','NONAKTIF') NOT NULL DEFAULT 'AKTIF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `master_kategori_ng`
--

INSERT INTO `master_kategori_ng` (`id`, `nama_kategori`, `status`, `created_at`) VALUES
(1, 'Short Mold', 'AKTIF', '2026-10-22 07:57:59'),
(2, 'Flash', 'AKTIF', '2026-10-22 07:57:59'),
(3, 'Sink Mark', 'AKTIF', '2026-10-22 07:57:59'),
(4, 'Bending', 'AKTIF', '2026-10-22 07:57:59'),
(5, 'Black Spot', 'AKTIF', '2026-10-22 07:57:59'),
(6, 'Silver', 'AKTIF', '2026-10-22 07:57:59'),
(7, 'Warna Tidak Sesuai', 'AKTIF', '2026-10-22 07:57:59'),
(8, 'Dimensi Tidak Sesuai', 'AKTIF', '2026-10-22 07:57:59'),
(9, 'Lain-lain', 'AKTIF', '2026-10-22 07:57:59');

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_line`
--

CREATE TABLE `master_line` (
  `id` int(10) UNSIGNED NOT NULL,
  `kode_line` varchar(30) NOT NULL,
  `nama_line` varchar(100) NOT NULL,
  `status` enum('AKTIF','NONAKTIF') NOT NULL DEFAULT 'AKTIF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `master_line`
--

INSERT INTO `master_line` (`id`, `kode_line`, `nama_line`, `status`, `created_at`, `updated_at`) VALUES
(1, 'A1', 'Injection A1', 'AKTIF', '2026-10-13 09:44:03', '2026-10-13 09:44:03'),
(2, 'A2', 'Injection A2', 'AKTIF', '2026-10-13 10:00:09', '2026-10-13 10:00:09');

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_mesin`
--

CREATE TABLE `master_mesin` (
  `id` int(10) UNSIGNED NOT NULL,
  `line_id` int(10) UNSIGNED NOT NULL,
  `kode_mesin` varchar(30) NOT NULL,
  `nama_mesin` varchar(100) NOT NULL,
  `status` enum('AKTIF','NONAKTIF') NOT NULL DEFAULT 'AKTIF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `master_mesin`
--

INSERT INTO `master_mesin` (`id`, `line_id`, `kode_mesin`, `nama_mesin`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'MCH-55', 'Machine 55', 'AKTIF', '2026-10-13 09:45:04', '2026-10-13 09:46:23'),
(2, 2, 'MCH-3', 'Machine 03', 'AKTIF', '2026-10-13 10:00:43', '2026-10-13 10:00:43');

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_part`
--

CREATE TABLE `master_part` (
  `id` int(10) UNSIGNED NOT NULL,
  `kode_part` varchar(50) NOT NULL,
  `nama_part` varchar(150) NOT NULL,
  `cycle_time` decimal(10,2) NOT NULL,
  `cavity` int(10) UNSIGNED NOT NULL,
  `target_per_jam` int(10) UNSIGNED NOT NULL,
  `target_per_shift` int(10) UNSIGNED NOT NULL,
  `target_per_hari` int(10) UNSIGNED NOT NULL,
  `material` varchar(100) DEFAULT NULL,
  `warna` varchar(100) DEFAULT NULL,
  `terdapat_second_proses` tinyint(1) NOT NULL DEFAULT 0,
  `keterangan` text DEFAULT NULL,
  `status` enum('AKTIF','NONAKTIF') NOT NULL DEFAULT 'AKTIF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `berat_part` decimal(10,2) NOT NULL DEFAULT 0.00,
  `berat_runner` decimal(10,2) NOT NULL DEFAULT 0.00
) ;

--
-- Dumping data untuk tabel `master_part`
--

INSERT INTO `master_part` (`id`, `kode_part`, `nama_part`, `cycle_time`, `cavity`, `target_per_jam`, `target_per_shift`, `target_per_hari`, `material`, `warna`, `terdapat_second_proses`, `keterangan`, `status`, `created_at`, `updated_at`, `berat_part`, `berat_runner`) VALUES
(1, 'PRT-001', 'Housing A', 58.00, 1, 60, 240, 720, 'ABS', 'Putih', 0, '', 'AKTIF', '2026-10-13 09:52:37', '2026-10-13 09:54:32', 0.00, 0.00),
(2, 'PRT-002', 'Front Rear Top', 90.00, 1, 40, 290, 840, 'PP', 'Putih', 1, '', 'AKTIF', '2026-10-14 16:27:28', '2026-10-14 16:27:28', 0.00, 0.00),
(3, 'PRT-003', 'Cover Rear', 85.00, 1, 40, 293, 845, 'PP', 'Black', 1, 'PT. MEI', 'AKTIF', '2026-10-14 21:04:30', '2026-10-14 21:04:30', 0.00, 0.00),
(4, 'PRT-004', 'Cover Back', 30.00, 1, 50, 300, 900, 'PP', 'Hitam', 0, '', 'AKTIF', '2026-10-24 19:10:47', '2026-10-24 19:29:24', 0.10, 0.03),
(5, 'PRT-005', 'Cover White', 40.00, 1, 45, 350, 900, 'ABS', 'Hitam', 1, '', 'AKTIF', '2026-10-24 19:31:57', '2026-10-24 19:38:54', 0.16, 0.03),
(6, 'PRT-006', 'Cover White 2', 50.00, 1, 50, 300, 700, 'PP', 'White', 0, '', 'AKTIF', '2026-10-24 19:33:42', '2026-10-24 19:33:42', 0.10, 0.03);

-- --------------------------------------------------------

--
-- Struktur dari tabel `planning_produksi`
--

CREATE TABLE `planning_produksi` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tanggal_produksi` date NOT NULL,
  `shift` tinyint(3) UNSIGNED NOT NULL,
  `mesin_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `urutan` int(10) UNSIGNED NOT NULL,
  `waktu_mulai` datetime NOT NULL,
  `waktu_selesai` datetime NOT NULL,
  `cycle_time_snapshot` decimal(10,2) NOT NULL,
  `cavity_snapshot` int(10) UNSIGNED NOT NULL,
  `target_per_jam_snapshot` int(10) UNSIGNED NOT NULL,
  `target_per_shift_snapshot` int(10) UNSIGNED NOT NULL,
  `target_per_hari_snapshot` int(10) UNSIGNED NOT NULL,
  `terdapat_second_proses_snapshot` tinyint(1) NOT NULL DEFAULT 0,
  `target_planning` int(10) UNSIGNED NOT NULL,
  `status` enum('TERJADWAL','BERJALAN','SELESAI','BATAL') NOT NULL DEFAULT 'TERJADWAL',
  `dibuat_oleh` int(10) UNSIGNED NOT NULL,
  `dimulai_oleh` int(10) UNSIGNED DEFAULT NULL,
  `mulai_aktual` datetime DEFAULT NULL,
  `selesai_aktual` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `no_jo_injection` varchar(100) DEFAULT NULL,
  `no_jo_second_proses` varchar(100) DEFAULT NULL,
  `nama_operator_second_proses` varchar(100) DEFAULT NULL,
  `nama_ipqc` varchar(100) DEFAULT NULL,
  `counter_awal` int(10) UNSIGNED DEFAULT NULL,
  `counter_akhir` int(10) UNSIGNED DEFAULT NULL,
  `stop_jo_at` datetime DEFAULT NULL
) ;

--
-- Dumping data untuk tabel `planning_produksi`
--

INSERT INTO `planning_produksi` (`id`, `tanggal_produksi`, `shift`, `mesin_id`, `part_id`, `urutan`, `waktu_mulai`, `waktu_selesai`, `cycle_time_snapshot`, `cavity_snapshot`, `target_per_jam_snapshot`, `target_per_shift_snapshot`, `target_per_hari_snapshot`, `terdapat_second_proses_snapshot`, `target_planning`, `status`, `dibuat_oleh`, `dimulai_oleh`, `mulai_aktual`, `selesai_aktual`, `created_at`, `updated_at`, `no_jo_injection`, `no_jo_second_proses`, `nama_operator_second_proses`, `nama_ipqc`, `counter_awal`, `counter_akhir`, `stop_jo_at`) VALUES
(2, '2026-10-14', 1, 1, 1, 1, '2026-10-14 07:30:00', '2026-10-14 11:30:00', 58.00, 1, 60, 240, 720, 0, 240, 'SELESAI', 1, 2, '2026-10-14 22:59:33', '2026-10-15 22:28:35', '2026-10-14 15:58:00', '2026-10-15 15:28:35', 'JO-INJT-01', '', NULL, '', NULL, NULL, NULL),
(3, '2026-10-14', 1, 2, 1, 1, '2026-10-14 07:30:00', '2026-10-14 08:30:00', 58.00, 1, 60, 240, 720, 0, 60, 'SELESAI', 1, 2, '2026-10-16 19:43:58', '2026-10-17 01:24:29', '2026-10-14 16:03:34', '2026-10-16 18:24:29', NULL, NULL, NULL, NULL, 10000, 10044, NULL),
(4, '2026-10-14', 1, 2, 2, 2, '2026-10-14 08:30:00', '2026-10-14 11:30:00', 90.00, 1, 40, 290, 840, 0, 120, 'TERJADWAL', 1, NULL, NULL, NULL, '2026-10-14 16:41:51', '2026-10-14 16:41:51', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, '2026-10-15', 1, 1, 3, 1, '2026-10-15 07:30:00', '2026-10-15 11:30:00', 85.00, 1, 40, 293, 845, 1, 160, 'SELESAI', 1, 2, '2026-10-15 22:44:09', '2026-10-16 18:04:29', '2026-10-14 21:05:37', '2026-10-16 11:04:29', 'INJ-002', 'SP-002', 'Gunawan', 'pitri', 1000, 1065, NULL),
(6, '2026-10-16', 2, 1, 2, 1, '2026-10-16 15:30:00', '2026-10-16 23:30:00', 90.00, 1, 40, 290, 840, 1, 320, 'SELESAI', 1, 2, '2026-10-17 01:25:09', '2026-10-17 02:04:53', '2026-10-16 16:40:00', '2026-10-16 19:04:53', '12345', 'SP-12345', 'Gunawan', 'Fitri', 1000, 1026, '2026-10-17 02:02:41'),
(7, '2026-10-17', 1, 1, 1, 1, '2026-10-17 07:30:00', '2026-10-17 10:30:00', 58.00, 1, 60, 240, 720, 0, 180, 'SELESAI', 1, 2, '2026-10-17 02:07:51', '2026-10-18 01:10:10', '2026-10-16 19:06:56', '2026-10-17 18:10:10', 'INJ-002', '', '', 'Makmur ajib', 22, 51, '2026-10-18 00:59:15'),
(8, '2026-10-17', 3, 1, 2, 1, '2026-10-17 23:30:00', '2026-10-18 07:30:00', 90.00, 1, 40, 290, 840, 1, 320, 'TERJADWAL', 1, NULL, NULL, NULL, '2026-10-17 18:18:20', '2026-10-17 18:18:20', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(9, '2026-10-18', 1, 1, 3, 1, '2026-10-18 07:30:00', '2026-10-18 15:30:00', 85.00, 1, 40, 293, 845, 1, 320, 'SELESAI', 1, 2, '2026-10-18 10:09:06', '2026-10-21 20:58:57', '2026-10-17 19:28:48', '2026-10-21 13:58:57', NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-21 20:58:51'),
(10, '2026-10-21', 3, 1, 1, 1, '2026-10-21 23:30:00', '2026-10-22 07:30:00', 58.00, 1, 60, 240, 720, 0, 480, 'SELESAI', 1, 2, '2026-10-22 14:45:10', '2026-10-22 16:02:33', '2026-10-21 14:31:33', '2026-10-22 09:02:33', '1000123', '', '', 'Juned', 10000, 10308, '2026-10-22 16:02:28'),
(11, '2026-10-25', 1, 2, 2, 1, '2026-10-25 07:30:00', '2026-10-25 15:30:00', 90.00, 1, 40, 290, 840, 1, 320, 'TERJADWAL', 1, NULL, NULL, NULL, '2026-10-24 18:28:06', '2026-10-24 18:28:06', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(12, '2026-10-25', 2, 1, 1, 1, '2026-10-25 15:30:00', '2026-10-25 20:30:00', 58.00, 1, 60, 240, 720, 0, 300, 'TERJADWAL', 1, NULL, NULL, NULL, '2026-10-24 18:29:00', '2026-10-24 18:29:00', NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `nama` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','ppic','operator','leader','supervisor') NOT NULL,
  `status` enum('AKTIF','NONAKTIF') NOT NULL DEFAULT 'AKTIF',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`id`, `nama`, `username`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Administrator', 'admin', '$2y$10$hTwV3ArSZbOxf4pyHtuX8OVmRcvVBTPcNqwjPi677NzI0rsMUDY86', 'admin', 'AKTIF', '2026-10-13 09:34:15', '2026-10-13 09:34:15'),
(2, 'Ahmad', 'ahmad', '$2y$10$so4vnCoVGRJ4kMUDwV5Q2Okdw7W93j.iaX08rcAbtU8vtUWbc6aRC', 'operator', 'AKTIF', '2026-10-14 15:52:01', '2026-10-14 15:52:01'),
(3, 'Leader', 'leader', '$2y$10$9GDO5avVShywQbmzR0EJZuws37KuTVQeKtFq40yTlTJ00rkBdFKcO', 'leader', 'AKTIF', '2026-10-18 03:52:28', '2026-10-18 03:52:28');

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `audit_koreksi_laporan`
--
ALTER TABLE `audit_koreksi_laporan`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user_id` (`user_id`),
  ADD KEY `idx_audit_entitas` (`entitas`,`entitas_id`),
  ADD KEY `idx_audit_created_at` (`created_at`);

--
-- Indeks untuk tabel `downtime`
--
ALTER TABLE `downtime`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_downtime_laporan` (`laporan_id`);

--
-- Indeks untuk tabel `laporan_ng_kategori`
--
ALTER TABLE `laporan_ng_kategori`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_laporan_kategori` (`laporan_id`,`kategori_ng_id`),
  ADD KEY `idx_kategori_ng` (`kategori_ng_id`);

--
-- Indeks untuk tabel `laporan_produksi_jam`
--
ALTER TABLE `laporan_produksi_jam`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_laporan_planning_slot` (`planning_id`,`slot_jam`),
  ADD KEY `idx_laporan_operator` (`operator_id`),
  ADD KEY `idx_laporan_waktu` (`waktu_mulai`,`waktu_selesai`);

--
-- Indeks untuk tabel `master_downtime`
--
ALTER TABLE `master_downtime`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_kategori_ng`
--
ALTER TABLE `master_kategori_ng`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nama_kategori` (`nama_kategori`);

--
-- Indeks untuk tabel `master_line`
--
ALTER TABLE `master_line`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kode_line` (`kode_line`);

--
-- Indeks untuk tabel `master_mesin`
--
ALTER TABLE `master_mesin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kode_mesin` (`kode_mesin`),
  ADD KEY `idx_mesin_line` (`line_id`);

--
-- Indeks untuk tabel `master_part`
--
ALTER TABLE `master_part`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kode_part` (`kode_part`);

--
-- Indeks untuk tabel `planning_produksi`
--
ALTER TABLE `planning_produksi`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_planning_mesin_shift_urutan` (`tanggal_produksi`,`shift`,`mesin_id`,`urutan`),
  ADD KEY `idx_planning_tanggal_shift` (`tanggal_produksi`,`shift`),
  ADD KEY `idx_planning_mesin_status` (`mesin_id`,`status`),
  ADD KEY `idx_planning_part` (`part_id`),
  ADD KEY `fk_planning_user` (`dibuat_oleh`),
  ADD KEY `idx_planning_operator_status` (`dimulai_oleh`,`status`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `audit_koreksi_laporan`
--
ALTER TABLE `audit_koreksi_laporan`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `downtime`
--
ALTER TABLE `downtime`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `laporan_ng_kategori`
--
ALTER TABLE `laporan_ng_kategori`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT untuk tabel `laporan_produksi_jam`
--
ALTER TABLE `laporan_produksi_jam`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_downtime`
--
ALTER TABLE `master_downtime`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `master_kategori_ng`
--
ALTER TABLE `master_kategori_ng`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `master_line`
--
ALTER TABLE `master_line`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `master_mesin`
--
ALTER TABLE `master_mesin`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `master_part`
--
ALTER TABLE `master_part`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `planning_produksi`
--
ALTER TABLE `planning_produksi`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `downtime`
--
ALTER TABLE `downtime`
  ADD CONSTRAINT `fk_downtime_laporan` FOREIGN KEY (`laporan_id`) REFERENCES `laporan_produksi_jam` (`id`) ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `laporan_produksi_jam`
--
ALTER TABLE `laporan_produksi_jam`
  ADD CONSTRAINT `fk_laporan_operator` FOREIGN KEY (`operator_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_laporan_planning` FOREIGN KEY (`planning_id`) REFERENCES `planning_produksi` (`id`) ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `master_mesin`
--
ALTER TABLE `master_mesin`
  ADD CONSTRAINT `fk_mesin_line` FOREIGN KEY (`line_id`) REFERENCES `master_line` (`id`) ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `planning_produksi`
--
ALTER TABLE `planning_produksi`
  ADD CONSTRAINT `fk_planning_dimulai_oleh` FOREIGN KEY (`dimulai_oleh`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_planning_mesin` FOREIGN KEY (`mesin_id`) REFERENCES `master_mesin` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_planning_part` FOREIGN KEY (`part_id`) REFERENCES `master_part` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_planning_user` FOREIGN KEY (`dibuat_oleh`) REFERENCES `users` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
