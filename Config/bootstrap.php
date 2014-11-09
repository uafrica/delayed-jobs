<?php

/**
 * =========================
 * DelayedJobs Plugin Config
 * =========================
 * 
 */
Configure::write("dj.service.name", "uep"); // This name should be unique for every parent app running on the same server
Configure::write("dj.max.hosts", 10); // Max number of hosts that is allowed to run
Configure::write("dj.max.retries", 25);
Configure::write("dj.max.execution.time", 6 * 60 * 60); // 6 Hours

