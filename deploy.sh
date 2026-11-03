#!/usr/bin/env bash
# ==============================================================================
# SALVI API - Production Deployment Script
# ==============================================================================
set -e

PROJECT_DIR="/var/www/salvi/www_api"
NGINX_DIR="/etc/nginx/conf.d"
BACKUP_DIR="/var/www/salvi/auto-deployment-backup"
BRANCH="${DEPLOY_BRANCH:-main}"

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1"
}

log "=== Начинаем деплой SALVI API (Бэкенд) ==="

# Функция аварийного выхода и очистки: гарантирует возврат прав и сброс состояния
cleanup() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        log "ОШИБКА: Деплой завершился со сбоем (код $exit_code)."
    fi

    # Аварийная страховка: если на сервере вдруг осталась старая заглушка Nginx
    if [ -f "$NGINX_DIR/site_update.conf" ]; then
        log "Обнаружена активная заглушка Nginx, снимаем..."
        mv "$NGINX_DIR/ssl_salvi.ua" "$NGINX_DIR/ssl_salvi.ua.conf" 2>/dev/null || true
        mv "$NGINX_DIR/site_update.conf" "$NGINX_DIR/site_update" 2>/dev/null || true
        systemctl restart nginx 2>/dev/null || true
    fi
    rm -f "$PROJECT_DIR/deploy" 2>/dev/null || true

    # Гарантированно восстанавливаем права salvi:salvi
    log "Восстанавливаем права доступа salvi:salvi..."
    chown -R salvi:salvi "$PROJECT_DIR" 2>/dev/null || true
    chmod -R 775 "$PROJECT_DIR/api/runtime" "$PROJECT_DIR/console/runtime" "$PROJECT_DIR/common/uploads" 2>/dev/null || true

    if [ $exit_code -eq 0 ]; then
        log "=== Деплой SALVI API успешно завершен! ==="
    else
        log "=== Деплой завершился с ошибкой! ==="
    fi
}
trap cleanup EXIT

cd "$PROJECT_DIR"

# 1. Создаем резервную копию проекта
log "1. Создаем резервную копию проекта..."
mkdir -p "$BACKUP_DIR"
BACKUP_FILE="$BACKUP_DIR/salvi_api_$(date +%F_%H-%M-%S).tar.gz"

# Список исключений для бэкапа:
# ВАЖНО: шаблоны без слешей (например, '1c_b2b.xml', 'vendor') GNU tar сопоставляет
# по имени файла/папки на ЛЮБОЙ глубине вложенности.
TAR_EXCLUDES=(
    --exclude='1c_b2b.xml'
    --exclude='catalog_status.xml'
    --exclude='cron.lock'
    --exclude='orders*'
    --exclude='vendor'
    --exclude='node_modules'
    --exclude='.git'
    --exclude='runtime'
    --exclude='nodejs'
    --exclude='*.tar.gz'
    --exclude='*.log'
    --exclude='api/web/files'
    --exclude='api/web/landings/logos'
    --exclude='common/uploads'
)

# Используем многопоточный pigz (2 ядра), если установлен, иначе обычный gzip
if command -v pigz >/dev/null 2>&1; then
    COMPRESSOR="pigz -p 2"
    tar -cpf - "${TAR_EXCLUDES[@]}" -C "$PROJECT_DIR" . 2>/dev/null | pigz -p 2 > "$BACKUP_FILE"
else
    COMPRESSOR="gzip"
    tar -cpf - "${TAR_EXCLUDES[@]}" -C "$PROJECT_DIR" . 2>/dev/null | gzip > "$BACKUP_FILE"
fi

BACKUP_SIZE=$(du -h "$BACKUP_FILE" 2>/dev/null | cut -f1)
log "Бэкап сохранен ($COMPRESSOR, размер: ${BACKUP_SIZE:-0}): $BACKUP_FILE"

# Удаляем старые бэкапы бэкенда, оставляя 2 последних
if [ -s "$BACKUP_FILE" ]; then
    log "Удаляем старые бэкапы salvi_api, оставляя 2 последних..."
    ls -t "$BACKUP_DIR"/salvi_api_*.tar.gz 2>/dev/null | tail -n +3 | xargs rm -f 2>/dev/null || true
fi

# 2. Подтягиваем свежий код из Git
log "2. Обновляем кодовую базу Git (ветка: $BRANCH)..."

# Страховка composer.lock: сохраняем локальный файл, если в нем уже зафиксирован elasticsearch
HAS_ELASTIC_LOCK=0
if [ -f "$PROJECT_DIR/composer.lock" ] && grep -q "elasticsearch/elasticsearch" "$PROJECT_DIR/composer.lock" 2>/dev/null; then
    HAS_ELASTIC_LOCK=1
    cp -f "$PROJECT_DIR/composer.lock" /tmp/composer.lock.salvi.bak 2>/dev/null || true
fi

git fetch --all
git reset --hard "origin/$BRANCH"
git pull origin "$BRANCH"

# Если в origin ветке composer.lock еще старый, а локально уже был установлен elasticsearch — восстанавливаем
if [ "$HAS_ELASTIC_LOCK" -eq 1 ] && ! grep -q "elasticsearch/elasticsearch" "$PROJECT_DIR/composer.lock" 2>/dev/null; then
    log "Восстанавливаем локальный composer.lock с поддержкой elasticsearch..."
    cp -f /tmp/composer.lock.salvi.bak "$PROJECT_DIR/composer.lock" 2>/dev/null || true
fi

# Очистка мусорных файлов IDE
find . -type d \( -name '.idea' -o -name '.vscode' \) -exec rm -rf {} + 2>/dev/null || true

# 3. Обновляем зависимости Composer
log "3. Устанавливаем зависимости Composer..."
export COMPOSER_PROCESS_TIMEOUT=1800
export COMPOSER_HTTP_TIMEOUT=60
export COMPOSER_ALLOW_SUPERUSER=1

composer install --optimize-autoloader --no-interaction

# Авто-лечение: гарантированная проверка наличия критических библиотек (Elasticsearch)
if [ ! -d "$PROJECT_DIR/vendor/elasticsearch/elasticsearch" ] || [ ! -f "$PROJECT_DIR/vendor/autoload.php" ]; then
    log "ВНИМАНИЕ: elasticsearch/elasticsearch отсутствует в vendor! Выполняем автоматическую установку..."
    composer require elasticsearch/elasticsearch:"~7.0" --no-interaction --update-no-dev || true
fi

# Проверяем и создаем необходимые директории для runtime и файлов загрузки
mkdir -p "$PROJECT_DIR/api/runtime" \
         "$PROJECT_DIR/console/runtime" \
         "$PROJECT_DIR/common/uploads/landings" \
         "$PROJECT_DIR/api/web/landings/logos" \
         "$PROJECT_DIR/api/web/files" 2>/dev/null || true
chown -R salvi:salvi "$PROJECT_DIR/api/runtime" "$PROJECT_DIR/console/runtime" "$PROJECT_DIR/common/uploads" "$PROJECT_DIR/api/web/landings" 2>/dev/null || true
chmod -R 775 "$PROJECT_DIR/api/runtime" "$PROJECT_DIR/console/runtime" "$PROJECT_DIR/common/uploads" "$PROJECT_DIR/api/web/landings" 2>/dev/null || true

# 4. Применяем миграции БД
log "4. Применяем миграции базы данных Yii2..."
php yii migrate --interactive=0

# 5. Сбрасываем кэш и перезапускаем сервисы
log "5. Сбрасываем кэш, перезапускаем PHP-FPM и воркер очередей..."
php yii cache/flush-all 2>/dev/null || true
systemctl reload php-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
systemctl restart yii-queue@1 2>/dev/null || true

# 6. Проверка здоровья API после деплоя
log "6. Проверяем доступность API..."
sleep 1
if command -v curl >/dev/null 2>&1; then
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -k "http://127.0.0.1/" -H "Host: sapi.salvi.ua" 2>/dev/null || echo "000")
    log "Локальный ответ API (sapi.salvi.ua): HTTP $HTTP_CODE"
fi
