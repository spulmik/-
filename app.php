<?php
// Открывающий тег PHP. Начало выполнения скрипта.

// === ЛОГИ ===
/**
 * Функция для записи отладочных сообщений в лог-файл
 * @param string $message - Сообщение для записи в лог
 */
function log_debug($message) {
    // Форматирование строки лога: [ГГГГ-ММ-ДД ЧЧ:ММ:СС] сообщение
    $log = date('[Y-m-d H:i:s]') . " " . $message . "\n";
    // Запись в файл 'debug.log' с флагом FILE_APPEND (добавление в конец файла)
    file_put_contents('debug.log', $log, FILE_APPEND);
}

// === HEADERS И НАСТРОЙКИ БЕЗОПАСНОСТИ ===
// Разрешаем кросс-доменные запросы для всех источников ('*')
header('Access-Control-Allow-Origin: *');
// Включаем отображение ВСЕХ ошибок PHP
error_reporting(E_ALL);
// Разрешаем вывод ошибок на экран (в продакшене должно быть 0)
ini_set('display_errors', 1);

// === БЛОК УСТАНОВКИ ПРИЛОЖЕНИЯ ===
// Проверка условий для установки:
// 1. Метод запроса - POST
// 2. Наличие обязательных параметров AUTH_ID и DOMAIN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['AUTH_ID'], $_REQUEST['DOMAIN'])) {
    log_debug("Начало установки приложения");

    // Санитизация входных данных (защита от XSS)
    $auth = htmlspecialchars($_REQUEST['AUTH_ID']);  // Токен авторизации Bitrix24
    $domain = htmlspecialchars($_REQUEST['DOMAIN']); // Домен портала Bitrix24

    // Параметры для регистрации бизнес-процесса
    $params = [
        'CODE' => 'get_comment', // Уникальный код действия
        'HANDLER' => 'https://co99624.tw1.ru/app.php', // URL обработчика
        'AUTH_USER_ID' => 1, // ID пользователя от имени которого выполняется действие
        'USE_SUBSCRIPTION' => 'N', // Отключение подписок на события
        // Локализованные названия
        'NAME' => ['ru' => 'Получить комментарий сделки'],
        'DESCRIPTION' => ['ru' => 'Переносит последний комментарий из таймлайна в поле "Комментарий" сделки'],
        // Входные параметры действия
        'PROPERTIES' => [
            'deal_id' => [  // Параметр "ID сделки"
                'Name' => ['ru' => 'ID сделки'],
                'Type' => 'int', // Тип данных - целое число
                'Required' => 'Y', // Обязательный параметр
            ]
        ],
        // Выходные параметры действия
        'RETURN_PROPERTIES' => [
            'comment' => ['Name' => ['ru' => 'Комментарий'], 'Type' => 'string'],
            'deal_updated' => ['Name' => ['ru' => 'Сделка обновлена'], 'Type' => 'bool'],
            'error' => ['Name' => ['ru' => 'Ошибка'], 'Type' => 'string'],
        ]
    ];

    // Формирование URL для REST API Bitrix24
    $url = "https://$domain/rest/bizproc.activity.add.json?auth=$auth";

    // Инициализация cURL-запроса
    $ch = curl_init($url);
    // Настройки cURL:
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, // Возвращать результат вместо вывода
        CURLOPT_POST => true,           // Использовать POST-метод
        CURLOPT_POSTFIELDS => http_build_query($params), // Данные запроса
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'], // Content-Type
    ]);

    // Выполнение запроса
    $result = curl_exec($ch);
    // Получение HTTP-статуса ответа
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // Проверка на ошибки cURL
    $error = curl_error($ch);
    // Закрытие соединения
    curl_close($ch);

    // Логирование результата
    log_debug("Регистрация действия ($httpCode): $result");
    if ($error) log_debug("CURL Error: $error");

    // Отправка ответа клиенту
    echo 'Действие зарегистрировано. Ответ: ' . $result;
    exit; // Прекращение выполнения скрипта
}

// === БЛОК ВЫПОЛНЕНИЯ ОСНОВНОЙ ЛОГИКИ ===
// Условия для выполнения действия:
// 1. POST-запрос
// 2. Наличие deal_id в параметрах
// 3. Наличие access_token в авторизационных данных
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['properties']['deal_id'], $_REQUEST['auth']['access_token'])) {
    log_debug("Начало выполнения действия");

    // Получение ID сделки (формат может быть "D_123" или "123")
    $dealIdRaw = $_REQUEST['properties']['deal_id'];
    // Нормализация ID: удаление префикса "D_" и преобразование в число
    $dealId = intval(str_replace('D_', '', $dealIdRaw));
    // Токен доступа OAuth
    $auth = $_REQUEST['auth']['access_token'];
    // Конечная точка API (URL портала)
    $clientEndpoint = $_REQUEST['auth']['client_endpoint'];
    // Извлечение домена из URL
    $domain = parse_url($clientEndpoint, PHP_URL_HOST);

    // Инициализация переменных результата
    $comment = '';         // Текст комментария
    $dealUpdated = false;  // Флаг обновления сделки
    $error = '';           // Сообщение об ошибке

    // === ПОЛУЧЕНИЕ КОММЕНТАРИЯ ИЗ ТАЙМЛАЙНА ===
    $url = "https://$domain/rest/crm.timeline.comment.list.json";
    // Параметры запроса:
    $queryParams = http_build_query([
        'auth' => $auth,
        'filter[ENTITY_ID]' => $dealId,   // Фильтр по ID сделки
        'filter[ENTITY_TYPE]' => 'deal',  // Тип сущности - сделка
        'order[CREATED]' => 'DESC',       // Сортировка по дате (последний первый)
        'limit' => 1                      // Ограничение - 1 комментарий
    ]);
    $fullUrl = "$url?$queryParams"; // Формирование полного URL

    log_debug("Запрос комментария: $fullUrl");

    // Выполнение GET-запроса через cURL
    $ch = curl_init($fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    log_debug("Ответ таймлайна ($httpCode): $response");
    if ($curlError) log_debug("CURL Error: $curlError");

    // Обработка ответа API
    if ($httpCode !== 200) {
        $error = "Ошибка при получении комментария: $httpCode";
    } else {
        // Декодирование JSON-ответа
        $data = json_decode($response, true);
        // Проверка успешности декодирования и наличия комментария
        if (json_last_error() === JSON_ERROR_NONE && isset($data['result'][0]['COMMENT'])) {
            $comment = $data['result'][0]['COMMENT'];
            // Логирование части комментария (первые 50 символов)
            log_debug("Комментарий найден: " . substr($comment, 0, 50) . "...");
        } else {
            $error = "Комментарий не найден или неверный формат ответа";
            log_debug($error . ": " . json_last_error_msg());
        }
    }

    // === ПАРСИНГ И ОБНОВЛЕНИЕ СДЕЛКИ ===
    if (!empty($comment)) {
        /**
         * Функция для извлечения данных из структурированного комментария
         * @param string $text - Текст комментария
         * @return array [Покупатель, Комментарий]
         */
        function parseCommentFields($text) {
            $buyer = '';
            $commentOnly = '';

            // Регулярное выражение для извлечения покупателя
            if (preg_match('/Покупатель:\s*(.+?)\s*(Комментарий:|Адрес:|$)/u', $text, $matches)) {
                $buyer = trim($matches[1]);
            }

            // Регулярное выражение для извлечения основного комментария
            if (preg_match('/Комментарий:\s*(.+?)\s*(Адрес:|Покупатель:|$)/us', $text, $matches)) {
                $commentOnly = trim($matches[1]);
            }

            return [$buyer, $commentOnly];
        }

        // Извлечение данных из комментария
        list($buyer, $commentOnly) = parseCommentFields($comment);

        log_debug("Покупатель: $buyer");
        log_debug("Комментарий: $commentOnly");

        // Получение текущих данных сделки
        $dealGetUrl = "https://$domain/rest/crm.deal.get.json";
        $dealGetParams = http_build_query(['auth' => $auth, 'id' => $dealId]);
        $dealResponse = file_get_contents("$dealGetUrl?$dealGetParams");
        $dealData = json_decode($dealResponse, true);

        // Текущие значения полей
        $currentComment = $dealData['result']['COMMENTS'] ?? '';
        $currentBuyer = $dealData['result']['UF_CRM_1749117234'] ?? ''; // ID пользовательского поля

        // Проверка необходимости обновления
        if (trim($currentComment) === trim($commentOnly) && trim($currentBuyer) === trim($buyer)) {
            log_debug("Данные уже записаны, обновление не требуется");
            // Формирование ответа без обновления
            echo json_encode([
                'comment' => $comment,
                'deal_updated' => false,
                'error' => 'Комментарий и покупатель уже были добавлены ранее'
            ]);
            exit;
        }

        // Подготовка данных для обновления
        $fieldsToUpdate = [];
        if (!empty($commentOnly)) $fieldsToUpdate['COMMENTS'] = $commentOnly;
        if (!empty($buyer)) $fieldsToUpdate['UF_CRM_1749117234'] = $buyer;

        // REST-запрос на обновление сделки
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

        // Обработка результата обновления
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
            // Сохранение HTML-ошибок в файл
            if (strpos($updateResult, '<!DOCTYPE html>') === 0) {
                file_put_contents('error.html', $updateResult);
                log_debug("HTML ответ сохранен в error.html");
            }
        }
    } else {
        $error = $error ?: 'Комментарий не найден';
    }

    // === ФОРМИРОВАНИЕ ОТВЕТА ===
    header('Content-Type: application/json'); // Установка заголовка JSON
    $response = [
        'comment' => $comment ?: 'Комментарий не найден',
        'deal_updated' => $dealUpdated,
        'error' => $error
    ];

    log_debug("Финальный ответ: " . json_encode($response));
    echo json_encode($response); // Отправка JSON-ответа
    exit; // Завершение скрипта
}
