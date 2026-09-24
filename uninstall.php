<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('ranau_fivepost_pickup_settings');
delete_transient('ranau_fivepost_sync_status');
