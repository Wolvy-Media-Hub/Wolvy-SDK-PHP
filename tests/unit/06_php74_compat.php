<?php
/**
 * A guard for the PHP 7.4 floor that runs on any PHP version: every file of src/, config/ and
 * tests/ is tokenised and searched for syntax and functions newer than 7.4. The CI matrix runs
 * the suite on real 7.4 too; this catches the common slips locally, where only a newer PHP may
 * be installed.
 */

T::section('PHP 7.4 compatibility (static scan)');

T::run('scan', function () {
    $root = dirname(__DIR__, 2);
    $files = [];
    foreach (['src', 'config', 'tests'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);

    // PHP 8.0+ functions (and 8.1+, 8.2+...). Not exhaustive: the ones a modern habit reaches for.
    $newer = ['str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list', 'get_debug_type', 'fdiv',
        'preg_last_error_msg', 'get_resource_id', 'enum_exists', 'array_find', 'array_any', 'array_all',
        'mb_str_pad', 'json_validate', 'array_find_key', 'iterator_apply_callback', 'str_increment'];
    $newerTypes = ['mixed', 'never', 'static', 'false', 'null', 'true'];

    $problems = [];
    foreach ($files as $path) {
        $relative = substr($path, strlen($root) + 1);
        $code = (string) file_get_contents($path);
        $tokens = array_values(array_filter(token_get_all($code), function ($t) {
            return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
        }));
        $count = count($tokens);
        $text = function ($t) {
            return is_array($t) ? $t[1] : $t;
        };
        $line = function ($i) use ($tokens) {
            for (; $i >= 0; $i--) {
                if (is_array($tokens[$i])) {
                    return $tokens[$i][2];
                }
            }
            return 0;
        };

        foreach ([['T_MATCH', 'match expression'], ['T_NULLSAFE_OBJECT_OPERATOR', 'nullsafe ?->'], ['T_ENUM', 'enum'], ['T_READONLY', 'readonly']] as [$constant, $what]) {
            if (!defined($constant)) {
                continue;
            }
            foreach ($tokens as $i => $t) {
                if (is_array($t) && $t[0] === constant($constant)) {
                    $problems[] = "{$relative}:{$t[2]} {$what}";
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $prev = $i > 0 ? $tokens[$i - 1] : null;
            $next = $i + 1 < $count ? $tokens[$i + 1] : null;

            // Attributes: only #[\ReturnTypeWillChange], alone on its line (a comment on 7.4).
            if (defined('T_ATTRIBUTE') && is_array($t) && $t[0] === T_ATTRIBUTE) {
                $lineText = explode("\n", $code)[$t[2] - 1];
                if (trim($lineText) !== '#[\ReturnTypeWillChange]') {
                    $problems[] = "{$relative}:{$t[2]} attribute other than a lone #[\\ReturnTypeWillChange]";
                }
            }

            // Calls to functions newer than 7.4.
            if (is_array($t) && $t[0] === T_STRING && $next === '(' && in_array(strtolower($t[1]), $newer, true)) {
                $before = is_array($prev) ? $prev[0] : $prev;
                if (!in_array($before, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true) && !($prev === '\\' && $i > 1 && is_array($tokens[$i - 2]) && $tokens[$i - 2][0] === T_STRING)) {
                    $problems[] = "{$relative}:{$t[2]} {$t[1]}() is PHP 8+";
                }
            }

            // $object::class
            if (is_array($t) && $t[0] === T_VARIABLE && $next !== null && is_array($next) && $next[0] === T_DOUBLE_COLON
                && isset($tokens[$i + 2]) && is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_CLASS) {
                $problems[] = "{$relative}:{$t[2]} \$var::class is PHP 8+";
            }

            // throw as an expression
            if (is_array($t) && $t[0] === T_THROW && $prev !== null) {
                $before = is_array($prev) ? $prev[0] : $prev;
                if (in_array($before, [T_COALESCE, T_DOUBLE_ARROW, '?', '=', T_BOOLEAN_OR, T_BOOLEAN_AND, ','], true)) {
                    $problems[] = "{$relative}:{$t[2]} throw used as an expression";
                }
            }

            // catch (Type) without a variable
            if (is_array($t) && $t[0] === T_CATCH) {
                $hasVariable = false;
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ')'; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                        $hasVariable = true;
                    }
                }
                if (!$hasVariable) {
                    $problems[] = "{$relative}:{$t[2]} catch without a variable";
                }
            }

            // Named arguments: "(" or "," then an identifier then a lone ":".
            if (is_array($t) && $t[0] === T_STRING && $next === ':' && ($prev === '(' || $prev === ',')) {
                $problems[] = "{$relative}:{$t[2]} named argument {$t[1]}:";
            }

            // First-class callable syntax foo(...)
            if ($t === '(' && is_array($next) && $next[0] === T_ELLIPSIS && isset($tokens[$i + 2]) && $tokens[$i + 2] === ')') {
                $problems[] = "{$relative}:{$line($i)} first-class callable (...)";
            }

            // Function declarations: parameter list and return type.
            if (is_array($t) && $t[0] === T_FUNCTION) {
                $j = $i + 1;
                while ($j < $count && $tokens[$j] !== '(') {
                    $j++;
                }
                $isConstructor = false;
                for ($k = $i + 1; $k < $j; $k++) {
                    if (is_array($tokens[$k]) && strtolower($tokens[$k][1]) === '__construct') {
                        $isConstructor = true;
                    }
                }
                $depth = 0;
                $params = [[]];
                for ($j = $j + 1; $j < $count; $j++) {
                    $tk = $tokens[$j];
                    if ($tk === '(' || $tk === '[' || $tk === '{') {
                        $depth++;
                    } elseif ($tk === ')' || $tk === ']' || $tk === '}') {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;
                    } elseif ($tk === ',' && $depth === 0) {
                        $params[] = [];
                        continue;
                    }
                    $params[count($params) - 1][] = $tk;
                }
                if (count($params) > 1 && $params[count($params) - 1] === []) {
                    $problems[] = "{$relative}:{$line($j)} trailing comma in a parameter list";
                }
                foreach ($params as $param) {
                    $signature = [];
                    foreach ($param as $tk) {
                        if ($tk === '=') {
                            $default = array_slice($param, count($signature) + 1);
                            foreach ($default as $d) {
                                if (is_array($d) && $d[0] === T_NEW) {
                                    $problems[] = "{$relative}:{$line($j)} new in a parameter default";
                                }
                            }
                            break;
                        }
                        $signature[] = $tk;
                    }
                    foreach ($signature as $tk) {
                        if ($tk === '|') {
                            $problems[] = "{$relative}:{$line($j)} union type in a parameter";
                        }
                        if (is_array($tk) && in_array($tk[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true) && $isConstructor) {
                            $problems[] = "{$relative}:{$line($j)} constructor property promotion";
                        }
                        if (is_array($tk) && $tk[0] === T_STRING && in_array(strtolower($tk[1]), ['mixed'], true)) {
                            $problems[] = "{$relative}:{$line($j)} mixed parameter type";
                        }
                    }
                }
                // Return type: tokens after ")" up to "{" or ";".
                if (isset($tokens[$j + 1]) && $tokens[$j + 1] === ':') {
                    for ($k = $j + 2; $k < $count && $tokens[$k] !== '{' && $tokens[$k] !== ';'; $k++) {
                        $tk = $tokens[$k];
                        if ($tk === '|') {
                            $problems[] = "{$relative}:{$line($k)} union return type";
                        }
                        if (in_array(strtolower($text($tk)), $newerTypes, true)) {
                            $problems[] = "{$relative}:{$line($k)} return type " . $text($tk) . ' is PHP 8+';
                        }
                    }
                }
            }
        }
    }

    T::ok(count($files) > 50, 'scanned ' . count($files) . ' files');
    T::ok($problems === [], 'no syntax or function newer than PHP 7.4', implode(PHP_EOL . '        ', $problems));
});
