-- -------------------------------------------------------------
-- TablePlus 6.8.6(662)
--
-- https://tableplus.com/
--
-- Database: lacms
-- Generation Time: 2026-04-06 11:19:05.2030
-- -------------------------------------------------------------


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


DROP TABLE IF EXISTS `province`;
CREATE TABLE `province` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `camdx_id` int DEFAULT NULL,
  `pro_id` varchar(255) DEFAULT NULL,
  `pro_khname` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `pro_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;

INSERT INTO `province` (`id`, `camdx_id`, `pro_id`, `pro_khname`, `pro_name`) VALUES
(1, 1, '01', 'បន្ទាយមានជ័យ', 'Banteay Meanchey'),
(2, 2, '02', 'បាត់ដំបង', 'Battambang'),
(3, 3, '03', 'កំពង់ចាម', 'Kampong Cham'),
(4, 4, '04', 'កំពង់ឆ្នាំង', 'Kampong Chhnang'),
(5, 5, '05', 'កំពង់ស្ពឺ', 'Kampong Speu'),
(6, 6, '06', 'កំពង់ធំ', 'Kampong Thom'),
(7, 7, '07', 'កំពត', 'Kampot'),
(8, 8, '08', 'កណ្ដាល', 'Kandal'),
(9, 9, '09', 'កោះកុង', 'Koh Kong'),
(10, 10, '10', 'ក្រចេះ', 'Kratie'),
(11, 11, '11', 'មណ្ឌលគិរី', 'Mondul Kiri'),
(12, 12, '12', 'ភ្នំពេញ', 'Phnom Penh'),
(13, 13, '13', 'ព្រះវិហារ', 'Preah Vihear'),
(14, 14, '14', 'ព្រៃវែង', 'Prey Veng'),
(15, 15, '15', 'ពោធិ៍សាត់', 'Pursat'),
(16, 16, '16', 'រតនគិរី', 'Ratanak Kiri'),
(17, 17, '17', 'សៀមរាប', 'Siemreap'),
(18, 18, '18', 'ព្រះសីហនុ', 'Preah Sihanouk'),
(19, 19, '19', 'ស្ទឹងត្រែង', 'Stung Treng'),
(20, 20, '20', 'ស្វាយរៀង', 'Svay Rieng'),
(21, 21, '21', 'តាកែវ', 'Takeo'),
(22, 22, '22', 'ឧត្ដរមានជ័យ', 'Oddar Meanchey'),
(23, 23, '23', 'កែប', 'Kep'),
(24, 24, '24', 'ប៉ៃលិន', 'Pailin'),
(25, 25, '25', 'ត្បូងឃ្មុំ', 'Tboung Khmum');



/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;