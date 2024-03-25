CREATE TABLE IF NOT EXISTS `[DATABASE_PREFIX]faq_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category` int(11) NULL DEFAULT NULL,
  `question` varchar(255) DEFAULT NULL,
  `answer` longtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;