# RESET DASHBOARD

DELETE FROM wp_usermeta
WHERE user_id = 1
AND meta_key = 'kpi_setup_done';
