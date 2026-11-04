#!/bin/sh
#
# setup.sh — мастер установки автономного приложения «Расписание занятий».
#
# Shell-обёртка над PHP-ядром мастера: проверяет наличие и версию PHP,
# обязательные расширения и доступность драйверов БД, затем запускает
# интерактивную установку (setup.php) либо низкоуровневые команды.
#
# Использование:
#   ./setup.sh              — интерактивная установка/перенастройка
#   ./setup.sh --yes        — неинтерактивно (параметры по умолчанию,
#                             пароль администратора генерируется автоматически)
#   ./setup.sh --help       — справка
#   ./setup.sh hash "пароль"    — только сгенерировать хеш пароля
#   ./setup.sh deactivate       — снять задачи планировщика
#   ./setup.sh uninstall        — удалить таблицы приложения
#
set -e

# --- Каталог проекта (работаем из любого места) ---------------------------
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cd "$SCRIPT_DIR"

# --- Цветовой вывод --------------------------------------------------------
if [ -t 1 ]; then
    C_RED=$(printf '\033[31m'); C_GREEN=$(printf '\033[32m')
    C_YELLOW=$(printf '\033[33m'); C_BLUE=$(printf '\033[1;34m')
    C_OFF=$(printf '\033[0m')
else
    C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''; C_OFF=''
fi

ok()   { echo "  ${C_GREEN}[OK]${C_OFF} $1"; }
bad()  { echo "  ${C_RED}[!!]${C_OFF} $1"; }
warn() { echo "  ${C_YELLOW}[!!]${C_OFF} $1"; }
step() { echo ""; echo "${C_BLUE}$1${C_OFF}"; }

usage() {
    cat <<EOF
Мастер установки «Расписание занятий» (автономная версия, без WordPress)

Использование:
  ./setup.sh                 интерактивная установка/перенастройка
  ./setup.sh --yes | -y      неинтерактивно: все параметры по умолчанию
                             (пароль администратора генерируется автоматически)
  ./setup.sh --help | -h     эта справка

Дополнительные команды:
  ./setup.sh hash "пароль"       сгенерировать bcrypt-хеш пароля
  ./setup.sh deactivate          снять задачи планировщика
  ./setup.sh uninstall           удалить таблицы приложения (БД-часть)

Что делает мастер (Шаг 0 проверяется здесь, шаги 1-4 — в setup.php):
  0. Проверяет наличие исполняемого PHP >= 8.0 и расширений pdo/curl/xml/mbstring/json.
  1. Проверяет PHP-окружение и драйверы баз данных (pdo_sqlite / pdo_mysql).
  2. Создаёт/обновляет config.php по вашим ответам (существующий можно забэкапить),
     генерирует хеш пароля администратора и криптостойкий секрет сессий.
  3. Инициализирует базу данных (sqlite или mysql) и регистрирует задачи планировщика.
  4. Проверяет целостность файлов приложения и печатает итог с готовыми командами
     (dev-сервер, crontab, Telegram webhook).
EOF
}

# --- Поиск интерпретатора PHP ---------------------------------------------
find_php() {
    for cand in php php8.4 php8.3 php8.2 php8.1 php8.0; do
        if command -v "$cand" >/dev/null 2>&1; then
            PHP_BIN=$cand
            return 0
        fi
    done
    return 1
}

require_php() {
    if ! find_php; then
        bad "PHP не найден в PATH."
        echo ""
        echo "Установите PHP версии 8.0 или новее, например:"
        echo "  Debian/Ubuntu: sudo apt install php8.2-cli php8.2-sqlite3 php8.2-curl php8.2-xml php8.2-mbstring"
        echo "  Fedora/RHEL:   sudo dnf install php php-pdo php-sqlite3 php-curl php-xml php-mbstring"
        exit 1
    fi

    if ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' >/dev/null 2>&1; then
        ver=$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo "?")
        bad "Обнаружен PHP ${ver} (${PHP_BIN}), требуется PHP >= 8.0. Обновите интерпретатор."
        exit 1
    fi
    ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION . " (" . PHP_BINARY . ")";')"
}

# --- Шаг 0. Быстрая проверка окружения в shell ------------------------------
check_env() {
    step "Шаг 0. Проверка окружения (shell)"
    require_php

    missing=""
    for ext in pdo curl xml mbstring json; do
        if "$PHP_BIN" -r "exit(extension_loaded('${ext}') ? 0 : 1);" >/dev/null 2>&1; then
            ok "Расширение php-${ext}"
        else
            bad "Отсутствует обязательное расширение: ${ext} (установите пакет php-${ext})"
            missing="${missing} ${ext}"
        fi
    done

    has_drv=0
    if "$PHP_BIN" -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);" >/dev/null 2>&1; then
        ok "Драйвер pdo_sqlite (SQLite)"; has_drv=1
    fi
    if "$PHP_BIN" -r "exit(extension_loaded('pdo_mysql') ? 0 : 1);" >/dev/null 2>&1; then
        ok "Драйвер pdo_mysql (MySQL/MariaDB)"; has_drv=1
    fi
    if [ "$has_drv" -eq 0 ]; then
        bad "Нет ни pdo_sqlite, ни pdo_mysql — установите хотя бы один (рекомендуется php-sqlite3)."
        missing="${missing} pdo_sqlite|pdo_mysql"
    fi

    for dir in data logs; do
        if [ ! -d "$dir" ]; then
            mkdir -p "$dir" 2>/dev/null || true
        fi
        if [ -d "$dir" ] && [ ! -w "$dir" ]; then
            warn "Каталог ${dir}/ недоступен для записи — проверьте права."
        fi
    done

    if [ -f config.php ]; then
        info_cfg="Обнаружен существующий config.php — мастер предложит сохранить его значения или пересоздать файл."
    else
        info_cfg="config.php отсутствует — он будет создан мастером."
    fi
    echo "  ${C_BLUE}-->${C_OFF} ${info_cfg}"

    if [ -n "$missing" ]; then
        echo ""
        echo "${C_RED}${C_BOLD}Окружение не готово.${C_OFF} Установите недостающие компоненты:$missing"
        echo "и повторите запуск: ./setup.sh"
        exit 1
    fi
}

# --- Разбор аргументов -------------------------------------------------------
MODE="install"
PASS_ARGS=""

case "${1:-}" in
    --help|-h)
        usage
        exit 0
        ;;
    --yes|-y)
        MODE="install-auto"
        ;;
    hash)
        MODE="hash"
        ;;
    deactivate)
        MODE="deactivate"
        ;;
    uninstall)
        MODE="uninstall"
        ;;
    "")
        MODE="install"
        ;;
    *)
        bad "Неизвестный параметр: $1"
        usage
        exit 1
        ;;
esac

require_php

case "$MODE" in
    hash)
        if [ -z "${2:-}" ]; then
            printf 'Пароль для хеширования: '
            read -r PW
        else
            PW=$2
        fi
        [ -n "$PW" ] || { bad "Пустой пароль."; exit 1; }
        "$PHP_BIN" install.php --hash-password "$PW"
        ;;
    deactivate)
        check_env
        "$PHP_BIN" install.php --deactivate
        ;;
    uninstall)
        check_env
        "$PHP_BIN" install.php --uninstall
        ;;
    install|install-auto)
        # Полная проверка шага 0 выполняется и внутри setup.php (шаг 1) —
        # здесь она даёт быстрый фидбек до старта интерактива.
        check_env
        step "Переход к мастеру настройки (setup.php)..."
        if [ "$MODE" = "install-auto" ]; then
            exec "$PHP_BIN" setup.php --yes
        else
            exec "$PHP_BIN" setup.php
        fi
        ;;
esac
