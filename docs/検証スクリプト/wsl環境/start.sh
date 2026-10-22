#!/bin/bash
# MySQL と Apache を起動する。初回だけ DB・ユーザー・テストデータを作る
# Ubuntu 26.04 の MySQL は systemd 用で service では起動できないため、直接起動して、使えるようになるまで待つ
mkdir -p /var/run/mysqld && chown mysql:mysql /var/run/mysqld && chmod 755 /var/run/mysqld
mysqld --user=mysql --daemonize --log-error=/var/log/mysql/error.log
for i in $(seq 1 60); do mysqladmin ping --silent 2>/dev/null && break; sleep 1; done
if [ ! -f /var/lib/mysql/.initialized ]; then
  mysql -e "CREATE DATABASE attendance_training CHARACTER SET utf8mb4; CREATE DATABASE attendance_test CHARACTER SET utf8mb4;
    CREATE USER 'attendance_app'@'localhost' IDENTIFIED BY 'nm'; GRANT ALL ON attendance_training.* TO 'attendance_app'@'localhost';
    CREATE USER 'root'@'%' IDENTIFIED BY 'verify'; GRANT ALL ON *.* TO 'root'@'%' WITH GRANT OPTION;"
  mysql attendance_training < /var/www/html/nm_attendance/docs/db/schema.sql
  mysql --default-character-set=utf8mb4 attendance_training < /var/www/html/nm_attendance/docs/db/test_data.sql
  H=$(php -r 'echo password_hash("Admin1234", PASSWORD_DEFAULT);')
  mysql attendance_training -e "INSERT INTO login_accounts (login_id, employee_id, password_hash, role) VALUES ('sysadmin', NULL, '$H', 'system_admin')"
  touch /var/lib/mysql/.initialized
fi
apache2ctl -D FOREGROUND
