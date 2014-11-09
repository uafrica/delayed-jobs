CREATE TABLE `delayed_jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group` varchar(128) DEFAULT NULL,
  `class` varchar(128) NOT NULL,
  `method` varchar(128) NOT NULL,
  `payload` blob NOT NULL,
  `options` blob NOT NULL,
  `status` int(10) unsigned NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  `retries` int(10) unsigned NOT NULL DEFAULT '0',
  `last_message` varchar(512) DEFAULT NULL,
  `priority` int(10) NOT NULL DEFAULT '1',
  `run_at` datetime NOT NULL,
  `failed_at` datetime DEFAULT NULL,
  `locked_by` varchar(128) DEFAULT NULL,
  `pid` int(10) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `delayed_job_hosts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `host_name` varchar(256) NOT NULL,
  `worker_name` varchar(32) NOT NULL,
  `pid` int(10) unsigned NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  `status` int(10) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;