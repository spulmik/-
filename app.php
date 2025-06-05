<?php

log_debug("Входящий запрос: " . print_r($_REQUEST, true));

header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', 1);

function log_debug($message) {
    $log = date('[Y-m-d H:i:s]') . " " . $message . "\n";
    file_put_contents('debug.log', $log, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['AUTH_ID'], $_REQUEST['DOMAIN'])) {
    log_debug("Начало установки приложения");

    $auth = htmlspecialchars($_REQUEST['AUTH_ID']);
    $domain = htmlspecialchars($_REQUEST['DOMAIN']);

    $params = [
        'CODE' => 'get_comment',
        'HANDLER' => 'https://co99624.tw1.ru/app.php',
        'AUTH_USER_ID' => 1,
        'USE_SUBSCRIPTION' => 'N',
        'NAME' => ['ru' => 'Получить комментарий сделки'],
        'DESCRIPTION' => ['ru' => 'Переносит последний комментарий из таймлайна в поле сделки'],
        'PROPERTIES' => [
            'deal_id' => [
                'Name' => ['ru' => 'ID сделки'],
                'Type' => 'int',
                'Required' => 'Y',
            ],
            'customer_field_code' => [
                'Name' => ['ru' => 'Код пользовательского поля для покупателя'],
                'Type' => 'string',
                'Required' => 'N',
            ]
        ],
        'RETURN_PROPERTIES' => [
            'comment' => ['Name' => ['ru' => 'Комментарий'], 'Type' => 'string'],
            'deal_updated' => ['Name' => ['ru' => 'Сделка обновлена'], 'Type' => 'bool'],
            'error' => ['Name' => ['ru' => 'Ошибка'], 'Type' => 'string']
        ]
    ];

    $url = "https://$domain/rest/bizproc.activity.add.json?auth=$auth";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    log_debug("Регистрация действия ($httpCode): $result");
    if ($error) log_debug("CURL Error: $error");

    echo 'Действие зарегистрировано. Ответ: ' . $result;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['properties']['deal_id'], $_REQUEST['auth']['access_token'])) {
    log_debug("Начало выполнения действия");

    $dealIdRaw = $_REQUEST['properties']['deal_id'];
    $dealId = intval(str_replace('D_', '', $dealIdRaw));
    $auth = $_REQUEST['auth']['access_token'];
    $clientEndpoint = $_REQUEST['auth']['client_endpoint'];
    $domain = parse_url($clientEndpoint, PHP_URL_HOST);
    $customerFieldCode = $_REQUEST['properties']['customer_field_code'] ?? 'UF_CRM_1749117234';

    $comment = '';
    $dealUpdated = false;
    $error = '';

    $url = "https://$domain/rest/crm.timeline.comment.list.json";
    $queryParams = http_build_query([
        'auth' => $auth,
        'filter[ENTITY_ID]' => $dealId,
        'filter[ENTITY_TYPE]' => 'deal',
        'order[CREATED]' => 'DESC',
        'limit' => 1
    ]);

    $fullUrl = "$url?$queryParams";
    log_debug("Запрос комментария: $fullUrl");

    $ch = curl_init($fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    log_debug("Ответ таймлайна ($httpCode): $response");
    if ($curlError) log_debug("CURL Error: $curlError");

    if ($httpCode !== 200) {
        $error = "Ошибка при получении комментария: $httpCode";
    } else {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['result'][0]['COMMENT'])) {
            $comment = $data['result'][0]['COMMENT'];
            log_debug("Комментарий найден: " . substr($comment, 0, 50) . "...");
        } else {
            $error = "Комментарий не найден или неверный формат ответа";
            log_debug($error . ": " . json_last_error_msg());
        }
    }

    if (!empty($comment)) {
        preg_match('/Покупатель: (.+?)(\\n|$)/u', $comment, $buyerMatch);
        preg_match('/Комментарий: (.+?)(\\n|$)/u', $comment, $commentMatch);
        $buyer = $buyerMatch[1] ?? '';
        $commentOnly = $commentMatch[1] ?? '';
        // Получаем текущие поля сделки
$dealGetUrl = "https://$domain/rest/crm.deal.get.json";
$dealGetData = http_build_query([
    'auth' => $auth,
    'id' => $dealId
]);

$ch = curl_init("$dealGetUrl?$dealGetData");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$dealInfoResult = curl_exec($ch);
curl_close($ch);

log_debug("Ответ crm.deal.get: $dealInfoResult");
$dealInfo = json_decode($dealInfoResult, true);
$currentComments = $dealInfo['result']['COMMENTS'] ?? '';

if (strpos($currentComments, $commentOnly) !== false) {
    log_debug("Комментарий уже есть в поле COMMENTS. Прерываем выполнение, чтобы избежать цикла.");
    
    header('Content-Type: application/json');
    echo json_encode([
        'comment' => $comment,
        'deal_updated' => false,
        'error' => 'Комментарий уже обновлён'
    ]);
    exit;
}

        $fieldsToUpdate = [];
        if (!empty($commentOnly)) $fieldsToUpdate['COMMENTS'] = $commentOnly;
        if (!empty($buyer)) $fieldsToUpdate[$customerFieldCode] = $buyer;

        $updateUrl = "https://$domain/rest/crm.deal.update.json";
        $updateData = http_build_query([
            'auth' => $auth,
            'id' => $dealId,
            'fields' => $fieldsToUpdate
        ]);

        $ch = curl_init($updateUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $updateData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
        ]);

        $updateResult = curl_exec($ch);
        $updateHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $updateError = curl_error($ch);
        curl_close($ch);

        log_debug("Ответ обновления ($updateHttpCode): $updateResult");
        if ($updateError) log_debug("CURL Error: $updateError");

        if ($updateHttpCode === 200) {
            $data = json_decode($updateResult, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['result'])) {
                $dealUpdated = true;
                log_debug("Сделка успешно обновлена");
            } else {
                $error = "Неверный JSON в ответе: " . json_last_error_msg();
            }
        } else {
            $error = "HTTP ошибка: $updateHttpCode";
            if (strpos($updateResult, '<!DOCTYPE html>') === 0) {
                file_put_contents('error.html', $updateResult);
                log_debug("HTML ответ сохранен в error.html");
            }
        }
    } else {
        $error = $error ?: 'Комментарий не найден';
    }

    header('Content-Type: application/json');
    $response = [
        'comment' => $comment ?: 'Комментарий не найден',
        'deal_updated' => $dealUpdated,
        'error' => $error
    ];

    log_debug("Финальный ответ: " . json_encode($response));
    echo json_encode($response);
    exit;
}
