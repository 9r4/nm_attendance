# 依頼者の WSL（Ubuntu 26.04）と同じ版の Apache・PHP・MySQL で確認するための環境（Claude の検証環境用。build.sh から使う）
FROM ubuntu:26.04
ENV DEBIAN_FRONTEND=noninteractive TZ=Asia/Tokyo
RUN apt-get update -qq && apt-get install -y -qq apache2 libapache2-mod-php8.5 php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml mysql-server tzdata >/dev/null && rm -rf /var/lib/apt/lists/*
COPY attendance.conf /etc/apache2/conf-available/attendance.conf
RUN a2enconf attendance >/dev/null && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf && a2enconf servername >/dev/null \
 && sed -i 's/^bind-address.*/bind-address = 0.0.0.0/' /etc/mysql/mysql.conf.d/mysqld.cnf \
 && printf 'error_reporting = E_ALL\ndisplay_errors = Off\nlog_errors = On\n' > /etc/php/8.5/apache2/conf.d/99-verify.ini
# ↑ 確認では、PHP の非推奨・警告もすべて Apache のログに出す（画面には出さない）
COPY start.sh /start.sh
CMD ["bash", "/start.sh"]
