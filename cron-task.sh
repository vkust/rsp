#!/bin/bash

# Определяем пути
PLUGIN_DIR="$(dirname "$(readlink -f "$0")")"
PHP_PATH="/usr/bin/php"
SCRIPT_PATH="$PLUGIN_DIR/cron.php"

# Проверяем существование файлов
if [ ! -f "$SCRIPT_PATH" ]; then
    echo "Error: Script not found at $SCRIPT_PATH"
    exit 1
fi

if [ ! -f "$PHP_PATH" ]; then
    PHP_PATH=$(which php)
    if [ -z "$PHP_PATH" ]; then
        echo "Error: PHP not found"
        exit 1
    fi
fi

# Логируем запуск для отладки
echo "[$(date)] Running $1 notification" >> "$PLUGIN_DIR/logs/cron.log"

# Проверяем тип уведомления
case "$1" in
    "next_lesson"|"next_day")
        $PHP_PATH "$SCRIPT_PATH" "$1"
        EXIT_CODE=$?
        echo "[$(date)] Finished with code $EXIT_CODE" >> "$PLUGIN_DIR/logs/cron.log"
        exit $EXIT_CODE
        ;;
    *)
        echo "Usage: $0 {next_lesson|next_day}"
        exit 1
        ;;
esac