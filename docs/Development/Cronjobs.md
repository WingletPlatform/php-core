# Cronjobs

## To fix access issues to log files etc include www-data in cronjobs.

```* * * * * www-data php /var/www/html/bin/console some:command >> /var/www/logs/cron.log 2>&1```