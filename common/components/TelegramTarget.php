<?php

namespace common\components;

use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target;

class TelegramTarget extends Target
{
    /** @var string Токен Telegram бота */
    public $botToken;

    /** @var string|int ID чата в Telegram */
    public $chatId;

    /** @var string Название окружения (PRODUCTION / STAGE / LOCAL) */
    public $environment = 'PRODUCTION';

    /** @var int Таймаут запроса к Telegram API в секундах */
    public $timeOut = 2;

    /** @var array Исключения, которые не нужно слать в Telegram (все клиентские ошибки 4xx) */
    public $except = [
        'yii\web\HttpException:4*',
    ];

    public function init()
    {
        parent::init();

        // По умолчанию отслеживаем только ошибки (LEVEL_ERROR = 1)
        if (empty($this->getLevels())) {
            $this->setLevels(['error']);
        }

        // Если параметры не переданы в конфиге компонента, берем из params
        if (empty($this->botToken) && isset(Yii::$app->params['telegramBotToken'])) {
            $this->botToken = Yii::$app->params['telegramBotToken'];
        }
        if (empty($this->chatId) && isset(Yii::$app->params['telegramChatId'])) {
            $this->chatId = Yii::$app->params['telegramChatId'];
        }
        if (isset(Yii::$app->params['telegramEnvironment'])) {
            $this->environment = Yii::$app->params['telegramEnvironment'];
        }
    }

    /**
     * Экспорт накопленных сообщений в Telegram
     */
    public function export()
    {
        if (empty($this->botToken) || empty($this->chatId)) {
            return;
        }

        foreach ($this->messages as $message) {
            // Жесткая страховка: ни в коем случае не слать trace, info или profile
            if ($message[1] !== Logger::LEVEL_ERROR) {
                continue;
            }

            // Не отправлять в Telegram пустые ошибки (например, случайный вызов Yii::error([]))
            if (empty($message[0]) && !is_numeric($message[0])) {
                continue;
            }

            // Дополнительная страховка: пропуск 4xx HTTP-исключений, если объект исключения передан напрямую
            if ($message[0] instanceof \yii\web\HttpException && $message[0]->statusCode < 500) {
                continue;
            }

            $text = $this->formatMessageForTelegram($message);
            $this->sendMessage($text);
        }
    }

    /**
     * Форматирование сообщения в красивый HTML
     */
    protected function formatMessageForTelegram($message)
    {
        list($text, $level, $category, $timestamp) = $message;

        $levelName = Logger::getLevelName($level);
        $date = date('Y-m-d H:i:s', (int)$timestamp);

        // Иконка в зависимости от окружения
        $isProd = strtoupper($this->environment) === 'PRODUCTION';
        $envIcon = $isProd ? '🔴' : '🟡';

        $errorDetails = '';
        if ($text instanceof \Throwable || $text instanceof \Exception) {
            $errorDetails .= "<b>Type:</b> " . htmlspecialchars(get_class($text)) . "\n";
            $errorDetails .= "<b>Message:</b> " . htmlspecialchars($text->getMessage()) . "\n";
            $errorDetails .= "<b>File:</b> " . htmlspecialchars($text->getFile()) . ":" . $text->getLine() . "\n";
            $errorDetails .= "<b>Code:</b> " . $text->getCode() . "\n";
        } elseif (is_string($text)) {
            $errorDetails .= "<b>Message:</b> " . htmlspecialchars($text) . "\n";
        } else {
            $errorDetails .= "<b>Data:</b> " . htmlspecialchars(VarDumper::export($text)) . "\n";
        }

        // Информация о запросе (если web)
        $requestInfo = '';
        if (Yii::$app instanceof \yii\web\Application) {
            $url = Yii::$app->request->getAbsoluteUrl();
            $method = Yii::$app->request->getMethod();
            $ip = Yii::$app->request->getUserIP();
            $requestInfo .= "<b>URL:</b> <code>{$method} {$url}</code>\n";
            $requestInfo .= "<b>IP:</b> <code>{$ip}</code>\n";

            if (!Yii::$app->user->isGuest) {
                $userId = Yii::$app->user->id;
                $requestInfo .= "<b>User ID:</b> {$userId}\n";
            }
        } elseif (Yii::$app instanceof \yii\console\Application) {
            $requestInfo .= "<b>Context:</b> Console Command\n";
        }

        $formatted = "{$envIcon} <b>[{$this->environment}] {$levelName}</b>\n";
        $formatted .= "<b>Date:</b> {$date}\n";
        $formatted .= "<b>Category:</b> <code>{$category}</code>\n";
        if (!empty($requestInfo)) {
            $formatted .= $requestInfo;
        }
        $formatted .= "------------------------------\n";
        $formatted .= $errorDetails;

        // Лимит Telegram — 4096 символов на сообщение
        if (mb_strlen($formatted, 'UTF-8') > 4000) {
            $formatted = mb_substr($formatted, 0, 3950, 'UTF-8') . "\n\n<i>... [Сообщение обрезано]</i>";
        }

        return $formatted;
    }

    /**
     * Отправка запроса в Telegram Bot API
     */
    protected function sendMessage($text)
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $data = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeOut);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $response = curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            // Игнорируем сбои сети, чтобы логирование в Telegram не роняло приложение
        }
    }
}
