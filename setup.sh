#!/bin/sh
#
# setup.sh — универсальный установщик автономного приложения «Расписание занятий».
#
# Поддерживает два сценария запуска:
#   1. Из уже клонированного репозитория:
#        ./setup.sh
#   2. Одной командой с удалённого сервера (рекомендуется):
#        sh <(wget -qO- https://raw.githubusercontent.com/vkust/rsp/main/setup.sh)
#        sh <(curl -fsSL https://raw.githubusercontent.com/vkust/rsp/main/setup.sh)
#
# Во втором случае скрипт сам скачает последнюю версию репозитория, установит
# PHP и зависимости, если их нет, создаст конфигурацию, базу данных и запустит
# встроенный веб-сервер как systemd-сервис (или через nohup, если systemd недоступен).
#
set -e

REPO_URL="https://github.com/vkust/rsp.git"
BRANCH="main"
PROJECT_DIR="rsp"
DEFAULT_PORT="8080"

# --- Цветовой вывод --------------------------------------------------------
if [ -t 1 ]; then
    C_RED=$(printf '\033[31m'); C_GREEN=$(printf '\033[32m')
    C_YELLOW=$(printf '\033[33m'); C_BLUE=$(printf '\033[1;34m')
    C_CYAN=$(printf '\033[36m'); C_OFF=$(printf '\033[0m')
else
    C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''; C_CYAN=''; C_OFF=''
fi

ok()   { echo "  ${C_GREEN}[OK]${C_OFF} $1"; }
bad()  { echo "  ${C_RED}[!!]${C_OFF} $1"; }
warn() { echo "  ${C_YELLOW}[!!]${C_OFF} $1"; }
step() { echo ""; echo "${C_BLUE}$1${C_OFF}"; }
info() { echo "  ${C_CYAN}-->${C_OFF} $1"; }

# --- Определение режима запуска --------------------------------------------
# Если $0 указывает на pipe/fd — скрипт выполняется удалённо через wget/curl.
REMOTE_MODE=0
case "$0" in
    /dev/fd/*|/proc/*/fd/*)
        REMOTE_MODE=1
        ;;
esac

if [ "$REMOTE_MODE" = "1" ]; then
    step "Удалённая установка из репозитория ${REPO_URL}"

    if [ -d "$PROJECT_DIR" ]; then
        warn "Каталог ${PROJECT_DIR}/ уже существует."
        if [ -t 0 ]; then
            printf "  Перезаписать? [y/N]: "
            read -r ans
            case "$ans" in
                y|Y|yes|Yes|YES|д|Д|да|Да)
                    rm -rf "$PROJECT_DIR"
                    ;;
                *)
                    echo "Отмена."
                    exit 0
                    ;;
            esac
        else
            info "Используется существующий каталог (неинтерактивный режим)."
        fi
    fi

    if [ ! -d "$PROJECT_DIR" ]; then
        info "Скачивание репозитория..."
        if command -v git >/dev/null 2>&1; then
            git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$PROJECT_DIR"
        else
            if command -v wget >/dev/null 2>&1; then
                wget -qO "rsp-${BRANCH}.zip" "https://github.com/vkust/rsp/archive/refs/heads/${BRANCH}.zip"
            elif command -v curl >/dev/null 2>&1; then
                curl -fsSL -o "rsp-${BRANCH}.zip" "https://github.com/vkust/rsp/archive/refs/heads/${BRANCH}.zip"
            else
                bad "Для скачивания репозитория нужен git, wget или curl."
                exit 1
            fi
            command -v unzip >/dev/null 2>&1 || { bad "Нужен unzip для распаковки архива."; exit 1; }
            unzip -q "rsp-${BRANCH}.zip"
            mv "rsp-${BRANCH}" "$PROJECT_DIR"
            rm -f "rsp-${BRANCH}.zip"
        fi
        ok "Репозиторий скачан в ./${PROJECT_DIR}/"
    fi

    cd "$PROJECT_DIR"
else
    cd "$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
fi

# --- Справка (не требует PHP) ----------------------------------------------
if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    cat <<EOF
Мастер установки «Расписание занятий» (автономная версия, без WordPress)

Использование:
  ./setup.sh                           интерактивная установка из репозитория
  ./setup.sh --yes | -y                неинтерактивно: параметры по умолчанию
  sh <(wget -qO- https://raw.githubusercontent.com/vkust/rsp/main/setup.sh)
  sh <(curl -fsSL https://raw.githubusercontent.com/vkust/rsp/main/setup.sh)

Переменные окружения:
  SCHEDULE_PORT    порт веб-сервера (по умолчанию: ${DEFAULT_PORT})
  SCHEDULE_BIND    адрес привязки (по умолчанию: 0.0.0.0)
  SCHEDULE_USER    пользователь для systemd-сервиса (по умолчанию: root)

EOF
    exit 0
fi

# --- Определение пакетного менеджера ----------------------------------------
DETECTED_PM=""
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1; then
        SUDO="sudo"
    fi
fi

if command -v apt-get >/dev/null 2>&1; then
    DETECTED_PM="apt"
elif command -v dnf >/dev/null 2>&1; then
    DETECTED_PM="dnf"
elif command -v yum >/dev/null 2>&1; then
    DETECTED_PM="yum"
elif command -v apk >/dev/null 2>&1; then
    DETECTED_PM="apk"
fi

# --- Поиск PHP -------------------------------------------------------------
find_php() {
    for cand in php php8.4 php8.3 php8.2 php8.1 php8.0; do
        if command -v "$cand" >/dev/null 2>&1; then
            PHP_BIN=$cand
            return 0
        fi
    done
    return 1
}

# --- Автоустановка PHP и зависимостей --------------------------------------
install_php() {
    if [ -z "$DETECTED_PM" ]; then
        bad "Не удалось определить пакетный менеджер. Установите PHP >= 8.0 вручную:"
        bad "  Debian/Ubuntu: sudo apt install php-cli php-sqlite3 php-curl php-xml php-mbstring"
        bad "  Fedora/RHEL:   sudo dnf install php php-pdo php-sqlite3 php-curl php-xml php-mbstring"
        exit 1
    fi

    step "Установка PHP и зависимостей через ${DETECTED_PM}"
    case "$DETECTED_PM" in
        apt)
            $SUDO apt-get update
            $SUDO apt-get install -y php-cli php-sqlite3 php-curl php-xml php-mbstring php-json git unzip
            ;;
        dnf)
            $SUDO dnf install -y php-cli php-pdo php-sqlite3 php-curl php-xml php-mbstring php-json git unzip
            ;;
        yum)
            $SUDO yum install -y php-cli php-pdo php-sqlite3 php-curl php-xml php-mbstring php-json git unzip
            ;;
        apk)
            $SUDO apk add php82 php82-pdo php82-pdo_sqlite php82-curl php82-xml php82-mbstring php82-json php82-openssl php82-zip git unzip curl
            # Alpine не создаёт симлинк php по умолчанию
            if [ ! -e /usr/bin/php ] && [ -e /usr/bin/php82 ]; then
                $SUDO ln -sf /usr/bin/php82 /usr/bin/php
            fi
            ;;
    esac
}

require_php() {
    if ! find_php; then
        warn "PHP не найден. Попытка автоматической установки..."
        install_php
        if ! find_php; then
            bad "PHP не удалось установить. Установите PHP >= 8.0 вручную."
            exit 1
        fi
    fi

    if ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' >/dev/null 2>&1; then
        ver=$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo "?")
        bad "Обнаружен PHP ${ver} (${PHP_BIN}), требуется PHP >= 8.0."
        if [ -n "$DETECTED_PM" ]; then
            warn "Попытка обновить PHP..."
            install_php
        else
            exit 1
        fi
    fi
    ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION . " (" . PHP_BINARY . ")";')"
}

# --- Проверка расширений ---------------------------------------------------
check_extensions() {
    step "Проверка PHP-расширений"
    missing=""
    for ext in pdo curl xml mbstring json; do
        if "$PHP_BIN" -r "exit(extension_loaded('${ext}') ? 0 : 1);" >/dev/null 2>&1; then
            ok "Расширение php-${ext}"
        else
            bad "Отсутствует расширение: ${ext}"
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
        bad "Нет ни pdo_sqlite, ни pdo_mysql."
        missing="${missing} pdo_sqlite"
    fi

    if [ -n "$missing" ] && [ -n "$DETECTED_PM" ]; then
        warn "Попытка доустановить недостающие расширения..."
        install_php
        missing=""
        for ext in pdo curl xml mbstring json; do
            "$PHP_BIN" -r "exit(extension_loaded('${ext}') ? 0 : 1);" >/dev/null 2>&1 || missing="${missing} ${ext}"
        done
        if [ "$has_drv" -eq 0 ]; then
            "$PHP_BIN" -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);" >/dev/null 2>&1 || missing="${missing} pdo_sqlite"
        fi
    fi

    if [ -n "$missing" ]; then
        bad "Окружение не готово. Установите недостающие пакеты:${missing}"
        exit 1
    fi
}

# --- Запуск PHP-мастера ----------------------------------------------------
run_setup_php() {
    step "Настройка приложения (setup.php)"
    if [ -t 0 ]; then
        "$PHP_BIN" setup.php
    else
        info "Неинтерактивный режим — используются параметры по умолчанию."
        "$PHP_BIN" setup.php --yes
    fi
}

# --- Чтение порта из config.php --------------------------------------------
config_port() {
    if [ -f config.php ]; then
        p=$("$PHP_BIN" -r '$c = include "config.php"; echo isset($c["server"]["port"]) ? (int) $c["server"]["port"] : 0;' 2>/dev/null || true)
        case "$p" in
            ''|*[!0-9]*) ;;
            *)
                if [ "$p" -ge 1 ] && [ "$p" -le 65535 ]; then
                    echo "$p"
                    return 0
                fi
                ;;
        esac
    fi
    echo "$DEFAULT_PORT"
}

# --- Запуск веб-сервера ----------------------------------------------------
start_server() {
    step "Запуск веб-сервера"

    port="${SCHEDULE_PORT:-$(config_port)}"
    bind="${SCHEDULE_BIND:-0.0.0.0}"
    app_root=$(pwd)

    # Проверяем, не занят ли порт
    if command -v ss >/dev/null 2>&1 && ss -tln | grep -q ":${port} "; then
        warn "Порт ${port} уже занят."
    elif command -v netstat >/dev/null 2>&1 && netstat -tln 2>/dev/null | grep -q ":${port} "; then
        warn "Порт ${port} уже занят."
    fi

    # Предпочитаем systemd
    if command -v systemctl >/dev/null 2>&1 && [ -d /etc/systemd/system ]; then
        service_file="/etc/systemd/system/rsp-schedule.service"
        info "Создаю systemd-сервис rsp-schedule.service..."
        $SUDO tee "$service_file" >/dev/null <<EOF
[Unit]
Description=Расписание занятий — автономное приложение
After=network.target

[Service]
Type=simple
User=${SCHEDULE_USER:-root}
WorkingDirectory=${app_root}
ExecStart=${PHP_BIN} -S ${bind}:${port} -t public public/index.php
Restart=always
RestartSec=5
Environment=SCHEDULE_SITE_URL=http://localhost:${port}

[Install]
WantedBy=multi-user.target
EOF
        $SUDO systemctl daemon-reload
        $SUDO systemctl enable --now rsp-schedule.service
        ok "Сервис rsp-schedule запущен на http://${bind}:${port}"
        info "Управление: sudo systemctl {start|stop|restart|status} rsp-schedule"
    else
        warn "systemd недоступен — запускаю сервер через nohup."
        nohup "$PHP_BIN" -S "${bind}:${port}" -t public public/index.php > logs/server.log 2>&1 &
        ok "Сервер запущен на http://${bind}:${port} (PID $!)"
        info "Лог: ${app_root}/logs/server.log"
    fi
}

# --- Итог ------------------------------------------------------------------
print_summary() {
    step "Установка завершена"
    port="${SCHEDULE_PORT:-$(config_port)}"
    bind="${SCHEDULE_BIND:-0.0.0.0}"
    echo "  ${C_GREEN}•${C_OFF} Приложение:     http://${bind}:${port}"
    echo "  ${C_GREEN}•${C_OFF} Админка:        http://${bind}:${port}/admin"
    echo "  ${C_GREEN}•${C_OFF} Каталог:        $(pwd)"
    echo ""
    echo "  ${C_YELLOW}Telegram-бот:${C_OFF}"
    echo "    1. Создайте бота у @BotFather и получите токен."
    echo "    2. Откройте http://${bind}:${port}/admin?page=schedule-telegram-settings"
    echo "    3. Введите токен и сохраните настройки."
    echo ""
    echo "  ${C_YELLOW}CRON (для уведомлений):${C_OFF}"
    echo "    * * * * * php $(pwd)/cron.php next_lesson >> $(pwd)/logs/cron.log 2>&1"
    echo "    0 20 * * *  php $(pwd)/cron.php next_day    >> $(pwd)/logs/cron.log 2>&1"
}

# --- Главный поток ---------------------------------------------------------
require_php
check_extensions
run_setup_php
start_server
print_summary
