<?php
/**
 * Совместимый с WPwpdb слой доступа к базе данных поверх PDO.
 * Поддерживает SQLite и MySQL, поддерживает %d/%s/%f в prepare(),
 * методы get_results/get_row/get_var/query/insert/update/delete и псевдо-свойства.
 */

if (!defined('APP_ROOT')) {
    exit;
}

class App_Database
{
    /** @var PDO */
    public $pdo;
    public $prefix = '';
    public $last_error = '';
    public $last_result = [];
    public $insert_id = 0;
    public $rows_affected = 0;
    public $num_rows = 0;

    private $type;
    private static $instance = null;
    private $pending_after_ddl = [];

    public function __construct($config = null)
    {
        $config = $config ?: App_Config::get('db');
        $this->type = $config['type'] ?? 'sqlite';
        // В WordPress $wpdb->prefix обычно 'wp_' и таблицы называются
        // "{$wpdb->prefix}schedule_users". Здесь префикс по умолчанию пуст,
        // чтобы имена таблиц остались schedule_users и т.д. (как в config).
        $this->prefix = $config['prefix'] ?? '';

        try {
            if ($this->type === 'mysql') {
                $m = $config['mysql'];
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $m['host'], $m['port'] ?? 3306, $m['name'], $m['charset'] ?? 'utf8mb4'
                );
                $this->pdo = new PDO($dsn, $m['user'], $m['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                ]);
            } else {
                $path = $config['sqlite']['path'];
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $this->pdo = new PDO('sqlite:' . $path, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                ]);
                $this->pdo->exec('PRAGMA journal_mode = WAL');
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            }
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }

        self::$instance = $this;
        // Глобал для совместимости с кодом вида global $wpdb;
        $GLOBALS['wpdb'] = $this;
    }

    public static function instance()
    {
        if (self::$instance === null) {
            new self();
        }
        return self::$instance;
    }

    public function is_sqlite()
    {
        return $this->type === 'sqlite';
    }

    public function is_mysql()
    {
        return $this->type === 'mysql';
    }

    /**
     * Аналог $wpdb->get_charset_collate().
     */
    public function get_charset_collate()
    {
        return $this->is_mysql() ? 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : '';
    }

    /**
     * Транслятор MySQL DML → SQLite. Заменяет SHOW COLUMNS / SHOW TABLES на
     * аналоги через sqlite_master, а MySQL-специфичные date-функции
     * (CURDATE(), NOW(), DATE_SUB(NOW(), INTERVAL n UNIT), DATE(col)) —
     * на эквиваленты SQLite. На MySQL запросы проходят без изменений.
     */
    /**
     * Аналог $wpdb->replace() — INSERT OR REPLACE (SQLite) / REPLACE INTO (MySQL).
     * Работает только при наличии UNIQUE/PK ограничения на ключевых колонках.
     */
    public function replace($table, $data, $format = null)
    {
        $columns = array_keys($data);
        $placeholders = [];
        $values = [];
        foreach ($data as $i => $key) {
            $placeholders[] = $this->get_format_for($format, $i, $key);
            $values[] = $key;
        }
        $verb = $this->is_sqlite() ? 'INSERT OR REPLACE INTO' : 'REPLACE INTO';
        $sql = $verb . ' `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')';
        $sql = $this->prepare($sql, ...$values);
        return $this->query($sql);
    }

    private function translate_sql($query)
    {
        if (!$this->is_sqlite()) {
            return $query;
        }

        // DDL-совместимость: MySQL-синтаксис индексов внутри CREATE TABLE → SQLite
        if (preg_match('/^\s*CREATE\s+TABLE/i', $query)) {
            $table = '';
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([A-Za-z0-9_]+)[`"]?/i', $query, $tm)) {
                $table = $tm[1];
            }
            // bigint(20)/tinyint(1) и т.п. → базовый тип
            $query = preg_replace('/\b(bigint|int|smallint|tinyint|mediumint)\(\d+\)/i', '$1', $query);
            // Убираем строки KEY / UNIQUE KEY / PRIMARY KEY(...) с запятыми
            $extra = [];
            $query = preg_replace_callback(
                '/,\s*((?:UNIQUE\s+)?(?:KEY|INDEX)\s+[`\w]+\s*\([^)]*\)|PRIMARY\s+KEY\s*\([^)]*\))/i',
                function ($m) use (&$extra, $table) {
                    $s = trim($m[1]);
                    if (preg_match('/^(UNIQUE\s+)?(?:KEY|INDEX)\s+`?(\w+)`?\s*\(([^)]*)\)/i', $s, $im)) {
                        $u = stripos($im[1], 'UNIQUE') !== false ? 'UNIQUE ' : '';
                        $cols = trim($im[3], " `");
                        $extra[] = "CREATE {$u}INDEX IF NOT EXISTS {$table}_{$im[2]} ON {$table} ({$cols})";
                    }
                    return ''; // PRIMARY KEY inline уже объявлен на колонке — просто вырезаем
                },
                $query
            );
            $query = rtrim(trim($query), ';');
            $this->pending_after_ddl = array_merge($this->pending_after_ddl ?? [], $extra);
        }

        // ALTER TABLE ... ADD COLUMN ... [AFTER ...] — SQLite не поддерживает AFTER,
        // вырезаем его; enum(...) приводим к TEXT с CHECK.
        if (preg_match('/^\s*ALTER\s+TABLE\s+[`"\']?([A-Za-z0-9_]+)[`"\']?\s+ADD\s+(?:COLUMN\s+)?[`"\']?(\w+)[`"\']?\s+(.*)$/is', $query, $am)) {
            $def = $am[3];
            $def = preg_replace('/\bAFTER\s+[`\w"]+\s*$/i', '', $def);
            $def = $this->normalize_column_def_sqlite($def);
            return "ALTER TABLE `{$am[1]}` ADD COLUMN `{$am[2]}` {$def}";
        }
        // ALTER TABLE ... MODIFY col ... — в SQLite изменение типа колонки невозможно;
        // тип в SQLite динамический, поэтому запрос выполняется как no-op (успех без действий).
        if (preg_match('/^\s*ALTER\s+TABLE\s+[`"\']?[A-Za-z0-9_]+[`"\']?\s+MODIFY\b/i', $query)) {
            $this->last_error    = '';
            $this->rows_affected = 0;
            return ''; // пустой запрос: query() пропустит выполнение через PDO
        }

        // SHOW TABLES LIKE 'name'
        if (preg_match("/^\\s*SHOW\\s+TABLES\\s+LIKE\\s+'([^']+)'/i", $query, $m)) {
            $t = str_replace("'", "''", $m[1]);
            return "SELECT name FROM sqlite_master WHERE type='table' AND name='$t'";
        }

        // SHOW COLUMNS FROM `tbl` [WHERE Field = 'x'] [LIKE 'y']
        if (preg_match('/^\\s*SHOW\\s+COLUMNS\\s+FROM\\s+[`\"]?([A-Za-z0-9_]+)[`\"]?/i', $query, $m)) {
            $table = $m[1];
            $where = '';
            if (preg_match("/\\bLIKE\\s+'([^']+)'/i", $query, $lm)) {
                $where = " WHERE name='" . str_replace("'", "''", $lm[1]) . "'";
            } elseif (preg_match("/Field\\s*=\\s*'([^']+)'/i", $query, $wm)) {
                $where = " WHERE name='" . str_replace("'", "''", $wm[1]) . "'";
            }
            $rows = $this->get_columns($table);
            if ($where !== '') {
                $col = preg_replace('/[^A-Za-z0-9_]/', '', substr($where, strlen(" WHERE name='")));
                $rows = array_values(array_filter($rows, function ($r) use ($col) {
                    return $r->Field === $col;
                }));
            }
            $this->last_result   = $rows;
            $this->num_rows      = count($rows);
            $this->last_error    = '';
            return null; // сигнал: результат уже подготовлен
        }

        // Date/time функции MySQL → SQLite
        $query = preg_replace('/\bCURDATE\s*\(\s*\)/i', "date('now','localtime')", $query);
        $query = preg_replace('/\bNOW\s*\(\s*\)/i', "datetime('now','localtime')", $query);
        $units = ['MICROSECOND' => 'microseconds', 'SECOND' => 'seconds', 'MINUTE' => 'minutes',
                  'HOUR' => 'hours', 'DAY' => 'days', 'WEEK' => 'weeks', 'MONTH' => 'months', 'YEAR' => 'years'];
        $u = implode('|', array_keys($units));
        $map = array_change_key_case($units, CASE_LOWER);
        // Интервал: число (или параметр %d/%s, уже подставленный) в кавычках или без них.
        $interval = "\s*INTERVAL\s+['\"]?(-?[\d%a-z_]+)['\"]?\s+($u)\s*";
        $inner    = "(?:CURDATE\\s*\\(\\s*\\)|NOW\\s*\\(\\s*\\)|datetime\\('now','localtime'\\)|date\\('now','localtime'\\)|"
                  . "datetime\\('now','localtime',[^)]*\\)|date\\([^()]*\\))";
        // Сначала заменяем вложенные вызовы на каноническую форму SQLite,
        // затем сами DATE_SUB/DATE_ADD с этой канонической формой.
        for ($pass = 0; $pass < 3; $pass++) {
            $query = preg_replace_callback(
                "/\\bDATE_SUB\\s*\\(\\s*{$inner},{$interval}\\)/i",
                function ($mm) use ($map) {
                    $n = $mm[1];
                    if (is_numeric($n)) {
                        return "datetime('now','localtime','-$n " . $map[strtolower($mm[2])] . "')";
                    }
                    return "datetime('now','localtime','-'||" . $n . "||' " . $map[strtolower($mm[2])] . "')";
                },
                $query
            );
            $query = preg_replace_callback(
                "/\\bDATE_ADD\\s*\\(\\s*{$inner},{$interval}\\)/i",
                function ($mm) use ($map) {
                    $n = $mm[1];
                    if (is_numeric($n)) {
                        return "datetime('now','localtime',\"+$n " . $map[strtolower($mm[2])] . "\")";
                    }
                    return "datetime('now','localtime','+'||" . $n . "||' " . $map[strtolower($mm[2])] . "')";
                },
                $query
            );
        }
        $query = preg_replace_callback(
            "/\bDATE\s*\(\s*([A-Za-z0-9_.`]+)\s*\)/i",
            function ($mm) {
                return "date({$mm[1]})";
            },
            $query
        );
        return $query;
    }

    /**
     * Нормализация определения колонки MySQL → SQLite: enum('a','b') → TEXT.
     */
    private function normalize_column_def_sqlite($def)
    {
        $def = trim($def);
        $def = preg_replace("/\benum\s*\([^)]*\)/i", 'TEXT', $def);
        return $def;
    }

    /**
     * Транслятор MySQL DDL → SQLite. Позволяет переиспользовать SQL-скрипты
     * плагина (CREATE TABLE ... KEY ..., UNIQUE KEY ...) на SQLite без правок:
     * ключевые конструкции KEY/UNIQUE KEY выносятся в отдельные CREATE INDEX,
     * суффиксы ENGINE=/CHARSET= отбрасываются.
     */
    private function translate_mysql_ddl($query)
    {
        if (!preg_match('/^\s*CREATE\s+TABLE/i', $query)) {
            return $query;
        }
        if (!preg_match('/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?[`"]?([A-Za-z0-9_]+)[`"]?\s*\(/i', $query, $m)) {
            return $query;
        }
        $ifne  = trim($m[1]) !== '' ? 'IF NOT EXISTS ' : '';
        $table = $m[2];

        // Открывающая скобка — та, что из общего совпадения паттерна ($m[0]
        // заканчивается на '(' сразу после имени таблицы).
        $open = (int) strrpos($m[0], '(');
        if ($open <= 0 || $query[$open] !== '(') {
            return $query;
        }

        // Закрывающая скобка — парная к открывающей (с учётом вложенности),
        // а не последняя в строке: после тела таблицы могут находиться
        // суффиксы вида ENGINE=InnoDB DEFAULT CHARSET=utf8.
        $depth = 0;
        $close = false;
        for ($i = $open, $len = strlen($query); $i < $len; $i++) {
            if ($query[$i] === '(') {
                $depth++;
            } elseif ($query[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }
        if ($close === false) {
            return $query;
        }
        $body = substr($query, $open + 1, $close - $open - 1);

        // Разбиваем тело таблицы по запятым верхнего уровня
        $parts = [];
        $depth = 0;
        $buf   = '';
        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') { $depth++; }
            elseif ($ch === ')') { $depth--; }
            if ($ch === ',' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
            } else {
                $buf .= $ch;
            }
        }
        if (trim($buf) !== '') {
            $parts[] = $buf;
        }

        $cols    = [];
        $indexes = [];
        foreach ($parts as $p) {
            $t = trim($p);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^UNIQUE\s+(?:KEY|INDEX)\s+[`"]?([A-Za-z0-9_]+)[`"]?\s*\(([^)]*)\)/i', $t, $mm)) {
                $indexes[] = ['unique' => true,  'name' => $mm[1], 'cols' => $mm[2]];
            } elseif (preg_match('/^(?:KEY|INDEX)\s+[`"]?([A-Za-z0-9_]+)[`"]?\s*\(([^)]*)\)/i', $t, $mm)) {
                $indexes[] = ['unique' => false, 'name' => $mm[1], 'cols' => $mm[2]];
            } elseif (preg_match('/^PRIMARY\s+KEY/i', $t)) {
                $cols[] = $t; // SQLite понимает PRIMARY KEY (col) в теле таблицы
            } else {
                $cols[] = $t;
            }
        }

        $sql = "CREATE TABLE {$ifne}`{$table}` (" . implode(', ', $cols) . ");";
        foreach ($indexes as $idx) {
            $colsN = preg_replace('/[`"\s]/', '', $idx['cols']);
            $u     = $idx['unique'] ? 'UNIQUE ' : '';
            $iname = $table . '_' . $idx['name'];
            // Каждая команда обязана заканчиваться ';' — иначе SQLite PDO::exec
            // не сможет отделить одну инструкцию от другой.
            $sql  .= "\nCREATE {$u}INDEX IF NOT EXISTS `{$iname}` ON `{$table}` ({$colsN});";
        }
        return $sql;
    }

    /**
     * Экранирование для LIKE (аналог $wpdb->esc_like).
     */
    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    /**
     * Аналог $wpdb->prepare с плейсхолдерами %d, %s, %f (включая %% escaping).
     */
    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $index = 0;
        $result = preg_replace_callback('/%([sdFf]|%)/', function ($matches) use (&$args, &$index) {
            if ($matches[1] === '%') {
                return '%';
            }
            if (!array_key_exists($index, $args)) {
                return $matches[0];
            }
            $value = $args[$index++];
            switch ($matches[1]) {
                case 'd':
                    return (string) (int) $value;
                case 'f':
                case 'F':
                    return (string) (float) $value;
                case 's':
                default:
                    if ($value === null) {
                        return 'NULL';
                    }
                    return $this->pdo->quote((string) $value);
            }
        }, $query);

        return $result;
    }

    /**
     * Выполнить запрос. Возвращает количество затронутых строк для изменяющих
     * запросов или массив объектов для SELECT.
     */
    public function query($query, ...$args)
    {
        if ($args) {
            $query = $this->prepare($query, ...$args);
        }
        if ($this->type === 'sqlite') {
            $translated = $this->translate_sql($query);
            if ($translated === null) {
                // SHOW COLUMNS: результат уже лежит в last_result
                return $this->last_result;
            }
            $query = $translated;
            if (trim($query) === '') {
                // no-op (например, MySQL-специфичный ALTER ... MODIFY для SQLite)
                $this->last_error    = '';
                $this->rows_affected = 0;
                return 0;
            }
            $query = $this->translate_mysql_ddl($query);
        }
        try {
            // Многостatement-строки (CREATE TABLE + CREATE INDEX из DDL-транслятора)
            // выполняем через PDO::exec: он умеет запускать несколько запросов
            // подряд, в отличие от PDO::query. Разбиение по ';' не используем,
            // т.к. точка с запятой может встречаться внутри строковых литералов.
            if ($this->is_sqlite() && preg_match('/;\s*(CREATE|ALTER|DROP|INSERT|UPDATE|DELETE)\b/i', $query)) {
                $n = $this->pdo->exec($query);
                $this->last_error    = '';
                $this->rows_affected = max(0, (int) $n);
                $this->insert_id     = (int) $this->pdo->lastInsertId();
                return $this->rows_affected;
            }
            $stmt = $this->pdo->query($query);
            $this->last_error = '';
            if ($stmt === false) {
                $err = $this->pdo->errorInfo();
                $this->last_error = $err[2] ?? 'Unknown error';
                return false;
            }
            $is_select = preg_match('/^\s*(SELECT|SHOW|PRAGMA|DESCRIBE|EXPLAIN)\b/i', $query);
            if ($is_select) {
                $this->last_result = $stmt->fetchAll(PDO::FETCH_OBJ);
                $this->num_rows = count($this->last_result);
                return $this->last_result;
            }
            $this->rows_affected = $stmt->rowCount();
            $this->insert_id = (int) $this->pdo->lastInsertId();
            return $this->rows_affected;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            error_log('DB query error: ' . $e->getMessage() . ' SQL: ' . $query);
            return false;
        }
    }

    public function get_results($query = null, $output = 'OBJECT')
    {
        if ($query === null) {
            $rows = $this->last_result;
        } else {
            $rows = $this->query($query);
            if ($rows === false) {
                return null;
            }
        }
        if ($output === ARRAY_A || $output === 'ARRAY_A') {
            return array_map(function ($r) {
                return (array) $r;
            }, $rows);
        }
        if ($output === ARRAY_N || $output === 'ARRAY_N') {
            return array_map(function ($r) {
                return array_values((array) $r);
            }, $rows);
        }
        return $rows;
    }

    public function get_row($query = null, $output = 'OBJECT', $y = 0)
    {
        $rows = $this->get_results($query, $output);
        if (!$rows) {
            return null;
        }
        return $rows[$y] ?? null;
    }

    public function get_var($query = null, $x = 0, $y = 0)
    {
        if ($query !== null) {
            $this->query($query);
        }
        $rows = $this->last_result;
        if (!isset($rows[$y])) {
            return null;
        }
        $arr = (array) $rows[$y];
        $values = array_values($arr);
        return $values[$x] ?? null;
    }

    public function get_col($query = null, $x = 0)
    {
        if ($query !== null) {
            $this->query($query);
        }
        $out = [];
        foreach ($this->last_result as $row) {
            $values = array_values((array) $row);
            $out[] = $values[$x] ?? null;
        }
        return $out;
    }

    /**
     * Аналог $wpdb->insert($table, $data, $format).
     */
    public function insert($table, $data, $format = null)
    {
        $columns = array_keys($data);
        $placeholders = [];
        $values = [];
        foreach ($data as $i => $key) {
            $fmt = $this->get_format_for($format, $i, $key);
            $placeholders[] = $fmt;
            $values[] = $key;
        }
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')';
        $sql = $this->prepare($sql, ...$values);
        return $this->query($sql);
    }

    /**
     * Аналог $wpdb->update($table, $data, $where, $format, $where_format).
     */
    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $set = [];
        $values = [];
        $i = 0;
        foreach ($data as $key => $val) {
            $set[] = '`' . $key . '` = ' . $this->get_format_for($format, $i, $key);
            $values[] = $val;
            $i++;
        }
        $conditions = [];
        $j = 0;
        foreach ($where as $key => $val) {
            $conditions[] = '`' . $key . '` = ' . $this->get_format_for($where_format, $j, $key);
            $values[] = $val;
            $j++;
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $conditions);
        $sql = $this->prepare($sql, ...$values);
        return $this->query($sql);
    }

    /**
     * Аналог $wpdb->delete($table, $where, $where_format).
     */
    public function delete($table, $where, $where_format = null)
    {
        $conditions = [];
        $values = [];
        $j = 0;
        foreach ($where as $key => $val) {
            $conditions[] = '`' . $key . '` = ' . $this->get_format_for($where_format, $j, $key);
            $values[] = $val;
            $j++;
        }
        $sql = 'DELETE FROM `' . $table . '` WHERE ' . implode(' AND ', $conditions);
        $sql = $this->prepare($sql, ...$values);
        return $this->query($sql);
    }

    private function get_format_for($format, $i, $key)
    {
        $fmt = null;
        if (is_array($format)) {
            $fmt = $format[$i] ?? null;
        } elseif (is_string($format)) {
            $fmt = $format;
        }
        if ($fmt === null) {
            // Автоопределение по имени поля (эвристика как в WP)
            if (strpos($key, 'id') !== false || strpos($key, 'chat_id') !== false) {
                $fmt = '%d';
            } else {
                $fmt = '%s';
            }
        }
        return $fmt;
    }

    /**
     * Проверка существования таблицы.
     */
    public function table_exists($table)
    {
        if ($this->is_mysql()) {
            $res = $this->get_var("SHOW TABLES LIKE '$table'");
            return $res === $table;
        }
        $res = $this->get_var("SELECT name FROM sqlite_master WHERE type='table' AND name='" . str_replace("'", "''", $table) . "'");
        return !empty($res);
    }

    /**
     * Получить список колонок таблицы (универсально).
     * Возвращает массив объектов {Field, Type, Null, Key, Default, Extra}.
     */
    public function get_columns($table)
    {
        if ($this->is_mysql()) {
            return $this->get_results("SHOW COLUMNS FROM `$table`");
        }
        $rows = $this->query("PRAGMA table_info(`$table`)");
        $out = [];
        foreach ($rows as $r) {
            $out[] = (object) [
                'Field'   => $r->name,
                'Type'    => $r->type,
                'Null'    => $r->notnull ? 'NO' : 'YES',
                'Key'     => $r->pk ? 'PRI' : '',
                'Default' => $r->dflt_value,
                'Extra'   => '',
            ];
        }
        return $out;
    }

    /**
     * Универсальный dbDelta-подобный метод: выполняет набор CREATE/ALTER запросов.
     */
    public function run_schema(array $statements)
    {
        foreach ($statements as $sql) {
            $sql = trim($sql);
            if ($sql === '') {
                continue;
            }
            $this->query($sql);
        }
    }
}

// Константы вывода результатов, совместимые с WordPress
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT_K')) {
    define('OBJECT_K', 'OBJECT_K');
}
