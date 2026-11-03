<?php
use kartik\mpdf\Pdf;

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php'
);

$config = [
    'id' => 'app-api',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'api\controllers',
    'bootstrap' => [
        'log',
    ],
    'language' => 'uk-UA',
    'modules' => [
    ],
    'components' => [
        'elasticsearch' => [
            'class' => 'yii\elasticsearch\Connection',
            'nodes' => [
                ['http_address' => '127.0.0.1:9200'],
                //настройте несколько хостов, если у вас есть кластер
            ],
            // установите autodetectCluster = false, чтобы не определять адреса узлов в кластере автоматически
            // 'autodetectCluster' => false,
            'dslVersion' => 7, // по умолчанию - 5
        ],
        'request' => [
            'cookieValidationKey' => 'PmnF_A-lknRdfJtw05CvzQjbbaf1IQkU',
            'class' => '\yii\web\Request',
            'enableCookieValidation' => false,
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],
        'user' => [
            'class' => 'common\components\User',
            'identityClass' => 'common\models\User',
            'enableSession' => false,
            'loginUrl' => null,
        ],
        'session' => [
            'name' => 'advanced-api',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['error'],
                    'logVars' => [],
                ],
                // Таргет для отправки критических ошибок и ошибок БД в Telegram
                [
                    'class' => 'common\components\TelegramTarget',
                    'levels' => ['error'],
                    'logVars' => [], // Не прикреплять $_COOKIE/$_SERVER для чистоты сообщений
                ],
            ],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'promo/submit-order' => 'v1/landing/submit-order',
                ['pattern' => 'promo/<slug:[\w-]+>/test', 'route' => 'v1/landing/view', 'verb' => 'GET'],
                ['pattern' => 'promo/<slug:[\w-]+>', 'route' => 'v1/landing/view', 'verb' => 'GET'],
                ['pattern' => 'promo', 'route' => 'v1/landing/view', 'verb' => 'GET', 'defaults' => ['slug' => 'novorichni-nabory']],
            ],
        ],
        'recaptchaV3' => [
            'class' => 'Baha2Odeh\RecaptchaV3\RecaptchaV3',
            'site_key' => '6Ldd4qQUAAAAAL9vK7L39pfxArYvjg4aodbU6ufk',
            'secret_key' => '6Ldd4qQUAAAAAGHmIYRaPsll97FWYQx1UNd0VOM9',
        ],
        // setup Krajee Pdf component
        'pdf' => [
            'class' => Pdf::classname(),
            'format' => Pdf::FORMAT_A4,
            'orientation' => Pdf::ORIENT_PORTRAIT,
            'destination' => Pdf::DEST_BROWSER,
            // refer settings section for all configuration options
        ]
    ],
    'params' => $params,
];

// Подключаем модуль отладки только если он физически установлен
if (class_exists('yii\debug\Module')) {
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
        'allowedIPs' => ['127.0.0.1', '185.43.224.114', '95.217.42.171', '144.76.41.253', '95.216.224.157'],
        'panels' => [
            'queue' => \yii\queue\debug\Panel::class,
        ],
    ];
}

$config = \yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../../common/config/main-local.php',
    $config
);

return $config;
