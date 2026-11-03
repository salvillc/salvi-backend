##!/usr/bin/env bash
## ==============================================================================
## SALVI API - Database Backup & Google Drive Sync
## ==============================================================================
## Создает дамп MySQL (salvidb), сжимает (pigz/gzip), выгружает на Google Диск
## через rclone, ротирует локальные и облачные архивы и шлет алерты в Telegram.
## ==============================================================================
#set -eo pipefail
#
#PROJECT_DIR="/var/www/salvi/www_api"
#BACKUP_DIR="/var/www/salvi/db-backups/daily"
#RCLONE_REMOTE="gdrive:salvi-db-backups/daily"
#RETENTION_LOCAL_DAYS=10
#RETENTION_REMOTE_DAYS=10
#DATE_STR=$(date +'%Y-%m-%d_%H-%M-%S')
#
#log() {
#    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1"
#}
#
## 1. Извлечение настроек подключения к БД и Telegram из конфигов Yii2
#DB_USER="root"
#DB_PASS=""
#DB_HOST="127.0.0.1"
#DB_NAME="salvidb"
#TG_BOT_TOKEN=""
#TG_CHAT_ID=""
#
#if [ -f "$PROJECT_DIR/common/config/main-local.php" ]; then
#    DB_USER=$(php -r '$c = @include "'"$PROJECT_DIR"'/common/config/main-local.php"; echo $c["components"]["db"]["username"] ?? "root";' 2>/dev/null || echo "root")
#    DB_PASS=$(php -r '$c = @include "'"$PROJECT_DIR"'/common/config/main-local.php"; echo $c["components"]["db"]["password"] ?? "";' 2>/dev/null || echo "")
#    DB_DSN=$(php -r '$c = @include "'"$PROJECT_DIR"'/common/config/main-local.php"; echo $c["components"]["db"]["dsn"] ?? "";' 2>/dev/null || echo "")
#    if [ -n "$DB_DSN" ]; then
#        PARSED_DB=$(echo "$DB_DSN" | sed -n 's/.*dbname=\([^;]*\).*/\1/p')
#        PARSED_HOST=$(echo "$DB_DSN" | sed -n 's/.*host=\([^;]*\).*/\1/p')
#        [ -n "$PARSED_DB" ] && DB_NAME="$PARSED_DB"
#        [ -n "$PARSED_HOST" ] && DB_HOST="$PARSED_HOST"
#    fi
#fi
#
#if [ -f "$PROJECT_DIR/common/config/params-local.php" ]; then
#    TG_BOT_TOKEN=$(php -r '$p = @include "'"$PROJECT_DIR"'/common/config/params-local.php"; echo $p["telegramBotToken"] ?? "";' 2>/dev/null || true)
#    TG_CHAT_ID=$(php -r '$p = @include "'"$PROJECT_DIR"'/common/config/params-local.php"; echo $p["telegramChatId"] ?? "";' 2>/dev/null || true)
#fi
#
#send_tg_alert() {
#    local message="$1"
#    if [ -n "$TG_BOT_TOKEN" ] && [ -n "$TG_CHAT_ID" ]; then
#        curl -s -X POST "https://api.telegram.org/bot${TG_BOT_TOKEN}/sendMessage" \
#            -d "chat_id=${TG_CHAT_ID}" \
#            -d "text=${message}" \
#            -d "parse_mode=Markdown" > /dev/null 2>&1 || true
#    fi
#}
#
#on_error() {
#    local line_num=$1
#    local exit_code=$2
#    log "ОШИБКА: Сбой выполнения скрипта на строке $line_num (код: $exit_code)"
#    send_tg_alert "🚨 *ОШИБКА БЭКАПА БД (salvi-prod)*
#Строка: \`$line_num\` (код ошибки: \`$exit_code\`).
#Дамп базы данных *${DB_NAME}* не был успешно выгружен на Google Диск!"
#    exit "$exit_code"
#}
#trap 'on_error $LINENO $?' ERR
#
#mkdir -p "$BACKUP_DIR"
#BACKUP_FILE="$BACKUP_DIR/${DB_NAME}_${DATE_STR}.sql.gz"
#
#log "=== Старт резервного копирования БД: ${DB_NAME} ==="
#
## 2. Выбор компрессора (быстрый pigz, если установлен, иначе gzip)
#if command -v pigz >/dev/null 2>&1; then
#    COMPRESSOR="pigz -p 2"
#else
#    COMPRESSOR="gzip -c"
#fi
#
## 3. Безопасное создание дампа MySQL без показа пароля в ps
#MYSQL_CNF=$(mktemp)
#cat <<EOF > "$MYSQL_CNF"
#[client]
#user = $DB_USER
#password = $DB_PASS
#host = $DB_HOST
#EOF
#chmod 600 "$MYSQL_CNF"
#
#log "Создание дампа базы данных (mysqldump -> $COMPRESSOR)..."
#mysqldump --defaults-extra-file="$MYSQL_CNF" \
#    --single-transaction \
#    --quick \
#    --routines \
#    --triggers \
#    "$DB_NAME" | $COMPRESSOR > "$BACKUP_FILE"
#
#rm -f "$MYSQL_CNF"
#
#if [ ! -s "$BACKUP_FILE" ]; then
#    log "ОШИБКА: Файл бэкапа пуст или не был создан!"
#    rm -f "$BACKUP_FILE"
#    exit 1
#fi
#
#BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
#log "Дамп успешно создан (размер: $BACKUP_SIZE): $BACKUP_FILE"
#
## 4. Выгрузка на Google Диск через rclone
#if command -v rclone >/dev/null 2>&1; then
#    log "Выгрузка на Google Диск ($RCLONE_REMOTE)..."
#    rclone copy "$BACKUP_FILE" "$RCLONE_REMOTE"
#    log "Файл успешно скопирован на Google Диск."
#
#    # 5. Ротация в облаке: удаление бэкапов старше RETENTION_REMOTE_DAYS дней
#    log "Ротация на Google Диске (удаление бэкапов старше ${RETENTION_REMOTE_DAYS} дней)..."
#    rclone delete --min-age "${RETENTION_REMOTE_DAYS}d" "$RCLONE_REMOTE" 2>/dev/null || true
#else
#    log "ВНИМАНИЕ: Утилита rclone не установлена! Загрузка на Google Диск пропущена."
#    send_tg_alert "⚠️ *Внимание (salvi-prod)*: Дамп БД создан локально ($BACKUP_SIZE), но *rclone* не установлен на сервере!"
#fi
#
## 6. Ротация локальных бэкапов на сервере: удаление старше RETENTION_LOCAL_DAYS дней
#log "Ротация локальных бэкапов (удаление старше ${RETENTION_LOCAL_DAYS} дней)..."
#find "$BACKUP_DIR" -type f -name "${DB_NAME}_*.sql.gz" -mtime +"$RETENTION_LOCAL_DAYS" -delete 2>/dev/null || true
#
#log "=== Резервное копирование успешно завершено! ==="
#
#
#
#
##!/bin/bash
##параметры
#NOWDATE=$(date +%Y-%m-%d)
#LASTDATE=$(date +%Y-%m-%d --date='7 day ago')
#HOSTNAME=salvi.ua
#DESTDIR='/server-backup/mysql'
#EMAIL=monitoring@kdteam.su
#mysqldump='mysqldump --insert-ignore --skip-lock-tables --single-transaction --no-tablespaces'
#
##создаем временный каталог
#mkdir -p $DESTDIR/$NOWDATE
#
##делаем бэкап
#DUMP_FAILED=0
#for i in $(mysql -e 'show databases;' | grep -v performance_schema | grep -v information_schema | grep -v mysql | grep -v Database); do
#    $mysqldump $i > $DESTDIR/$NOWDATE/$i.sql
#    if [ $? -ne 0 ] || [ ! -s "$DESTDIR/$NOWDATE/$i.sql" ]; then
#        echo "mysqldump FAILED for database: $i on $HOSTNAME" | mail -s "$HOSTNAME: mysqldump ERROR for $i at $NOWDATE!" $EMAIL
#        DUMP_FAILED=1
#    fi
#done
#
##rotate backup
#rm -rf $DESTDIR/$LASTDATE
#
#### Size backup ###
#SIZEFOLDER="$(du -sh "$DESTDIR/$NOWDATE/")"
#SIZEFILES="$(du -s "$DESTDIR/$NOWDATE/"* | sort -nr | cut -f 2- | while read a; do du -hs $a; done)"
#
##проверка наличия директории бэкапа с отправкой на почту
#RESULT="$(ls $DESTDIR | awk '{print $1}' | grep -ic "$NOWDATE")"
#if [ "$RESULT" != 1 ]; then
#    echo "Oops! Backup is FAILED daily on $HOSTNAME :(" | mail -s "$HOSTNAME: Backup ERROR at $NOWDATE!" $EMAIL
#elif [ "$DUMP_FAILED" -eq 1 ]; then
#    echo "Backup dir created but some databases FAILED to dump on $HOSTNAME. Backup size - $SIZEFOLDER. File list: \n $SIZEFILES" | mail -s "$HOSTNAME: Backup PARTIAL at $NOWDATE!" $EMAIL
#else
#    echo "Backup is SUCCESSFULL daily $NOWDATE on $HOSTNAME, backup size - $SIZEFOLDER from $HOSTNAME. File list: \n $SIZEFILES" | mail -s "$HOSTNAME: Successfull daily backup at $NOWDATE" $EMAIL
#fi