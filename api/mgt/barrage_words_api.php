<?php
header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');

// open.rcwulian.cn/api/mgt/barrage_words_api.php
// 无密钥版本

define('BARRAGE_WORDS_FILE', '/www/wwwroot/open.rcwulian.cn/single/res/barrage_banned_words.json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function out($code, $msg, $extra = []) {
    echo json_encode(array_merge([
        'code' => $code,
        'msg'  => $msg,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function readInput() {
    $input = $_REQUEST;

    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $input = array_merge($input, $json);
        }
    }

    return $input;
}

function normalizeWord($word) {
    $word = html_entity_decode((string)$word, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $word = trim(strip_tags($word));
    $word = preg_replace('/[\r\n\t]+/u', '', $word);
    return trim($word);
}

function normalizeWords($words) {
    $result = [];

    if (!is_array($words)) {
        return [];
    }

    foreach ($words as $item) {
        if (is_array($item)) {
            $word = normalizeWord($item['word'] ?? '');
        } else {
            $word = normalizeWord($item);
        }

        if ($word !== '') {
            $result[] = $word;
        }
    }

    return array_values(array_unique($result));
}

function loadWords() {
    $file = BARRAGE_WORDS_FILE;

    if (!is_file($file)) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, "[]");
    }

    $raw = file_get_contents($file);
    if ($raw === false) {
        out(500, '读取词库失败');
    }

    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $json = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
        out(500, '词库 JSON 格式错误');
    }

    // 兼容两种格式：
    // 1. ["违禁词1", "违禁词2"]
    // 2. {"words": ["违禁词1", "违禁词2"]}
    $list = isset($json['words']) && is_array($json['words']) ? $json['words'] : $json;

    return normalizeWords($list);
}

function saveWords($words) {
    $file = BARRAGE_WORDS_FILE;
    $dir = dirname($file);

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $words = normalizeWords($words);

    $fp = fopen($file, 'c+');
    if (!$fp) {
        out(500, '打开词库文件失败');
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        out(500, '锁定词库文件失败');
    }

    $json = json_encode($words, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    return $words;
}

function validateWordOrOut($word) {
    if ($word === '') {
        out(422, '违禁词不能为空');
    }

    if (mb_strlen($word, 'UTF-8') > 50) {
        out(422, '单个违禁词不能超过50个字符');
    }
}

$input = readInput();
$action = $input['action'] ?? 'list';

switch ($action) {
    case 'list':
        $words = loadWords();

        out(200, '查询成功', [
            'data' => [
                'count' => count($words),
                'words' => $words,
            ],
        ]);
        break;

    case 'add':
        $word = normalizeWord($input['word'] ?? '');
        validateWordOrOut($word);

        $words = loadWords();

        if (in_array($word, $words, true)) {
            out(409, '该违禁词已存在', [
                'data' => [
                    'count' => count($words),
                    'words' => $words,
                ],
            ]);
        }

        $words[] = $word;
        $words = saveWords($words);

        out(200, '添加成功', [
            'data' => [
                'count' => count($words),
                'words' => $words,
            ],
        ]);
        break;

    case 'batch_add':
        $rawWords = (string)($input['words'] ?? '');

        $parts = preg_split('/[\r\n,，、|]+/u', $rawWords);
        $parts = normalizeWords($parts);

        if (empty($parts)) {
            out(422, '批量内容不能为空');
        }

        foreach ($parts as $w) {
            validateWordOrOut($w);
        }

        $words = loadWords();
        $beforeCount = count($words);

        foreach ($parts as $w) {
            if (!in_array($w, $words, true)) {
                $words[] = $w;
            }
        }

        $words = saveWords($words);
        $addedCount = count($words) - $beforeCount;

        out(200, '批量添加成功', [
            'data' => [
                'added_count' => $addedCount,
                'count' => count($words),
                'words' => $words,
            ],
        ]);
        break;

    case 'update':
        $oldWord = normalizeWord($input['old_word'] ?? '');
        $newWord = normalizeWord($input['new_word'] ?? '');

        validateWordOrOut($oldWord);
        validateWordOrOut($newWord);

        $words = loadWords();

        $index = array_search($oldWord, $words, true);
        if ($index === false) {
            out(404, '原违禁词不存在');
        }

        if ($oldWord !== $newWord && in_array($newWord, $words, true)) {
            out(409, '新的违禁词已存在');
        }

        $words[$index] = $newWord;
        $words = saveWords($words);

        out(200, '修改成功', [
            'data' => [
                'count' => count($words),
                'words' => $words,
            ],
        ]);
        break;

    case 'delete':
        $word = normalizeWord($input['word'] ?? '');
        validateWordOrOut($word);

        $words = loadWords();

        if (!in_array($word, $words, true)) {
            out(404, '违禁词不存在');
        }

        $words = array_values(array_filter($words, function ($v) use ($word) {
            return $v !== $word;
        }));

        $words = saveWords($words);

        out(200, '删除成功', [
            'data' => [
                'count' => count($words),
                'words' => $words,
            ],
        ]);
        break;

    default:
        out(400, '无效操作');
        break;
}