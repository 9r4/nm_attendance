#!/bin/bash
# 依頼者の WSL と同じ版（Ubuntu 26.04・Apache 2.4.66・PHP 8.5.4・MySQL 8.4.11）の確認用の環境を作って起動する（Claude の検証環境用）
# 使い方：bash docs/検証スクリプト/wsl環境/build.sh（リポジトリ直下で実行）
set -e
REPO=$(cd "$(dirname "$0")/../../.." && pwd)
CTX=$(mktemp -d)
cp "$(dirname "$0")/Dockerfile" "$(dirname "$0")/start.sh" "$CTX/"
# Apache の設定は、WSL と同じもの（docs/apache/attendance.conf）を、置き場所（/var/www/html/nm_attendance）に合わせて使う
sed 's#/var/www/html/attendance#/var/www/html/nm_attendance#g' "$REPO/docs/apache/attendance.conf" > "$CTX/attendance.conf"
docker info >/dev/null 2>&1 || { nohup dockerd > /tmp/dockerd.log 2>&1 & sleep 8; }
docker build -q -t attendance-wsl "$CTX" >/dev/null
rm -rf "$CTX"
docker rm -f attendance-wsl >/dev/null 2>&1 || true
docker run -d --name attendance-wsl -p 8080:80 -p 3307:3306 -v "$REPO":/var/www/html/nm_attendance attendance-wsl >/dev/null
# 初回は DB とテストデータを作ってから Apache が起動する。画面が開けるようになるまで待つ
for i in $(seq 1 120); do [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/nm_attendance/login.php)" = 200 ] && break; sleep 1; done
# 検証スクリプトの mysql コマンド（-uroot）が、この環境の MySQL につながるようにする
printf '[client]\nhost=127.0.0.1\nport=3307\npassword=verify\n' > ~/.my.cnf && chmod 600 ~/.my.cnf
docker exec attendance-wsl bash -c "php -v | head -1; apache2 -v | head -1; mysql -N -e 'SELECT VERSION()'"
