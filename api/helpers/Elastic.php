<?php

namespace api\helpers;

use Elasticsearch\ClientBuilder;

class Elastic
{
    /**
     * Объект еластика
     * @see https://www.elastic.co/guide/en/elasticsearch/client/php-api/current/index.html
     * @var \Elasticsearch\Client|null
     */
    private $elastic = null;

    /**
     * Индекс
     * @var string
     */
    private $index;

    const HOST = '116.203.191.217';
    const PORT = '9200';

    const LOGIN = 'elastic';
    const PASSWORD = 'J2OJ1SAngsuOEgHTMvey';

    const MESSAGE_START = "START";
    const MESSAGE_PROCESS = "PROCESS";
    const MESSAGE_FINISH = "FINISH";
    const MESSAGE_ERROR = "ERROR";

    /**
     * Инициализация подключения к Elasticsearch
     * @param array $attributes
     * @throws \Exception
     */
    public function __construct(array $attributes = [])
    {
        /**
         * Возможность подключения к разным еластикам, ELASTIC_STORAGE_HOST - локальный | ELASTIC_HOST - еластик логов
         */
        $elasticHost = null;
        if (array_key_exists('mode', $attributes))
        {
//            if ($attributes['mode'] == 'local') {
//                $elasticHost = env('ELASTIC_STORAGE_HOST') . ':' . env('ELASTIC_STORAGE_PORT');
//            } else {
//                $elasticHost = env('ELASTIC_HOST') . ':' . env('ELASTIC_PORT');
//            }
        }
        else
        {
            $elasticHost = self::HOST . ':' . self::PORT;
        }
        if ($elasticHost == null)
        {
            throw new \Exception('Не удалось подключится к еластику, подключение - ' . $elasticHost);
        }

        if (!class_exists('Elasticsearch\ClientBuilder')) {
            \Yii::error('Elasticsearch\ClientBuilder не знайдено. Пакет elasticsearch/elasticsearch відсутній у vendor.', 'elasticsearch');
            return;
        }

        try
        {
            $this->elastic = ClientBuilder::create()->setHosts(array($elasticHost))
                ->setBasicAuthentication(self::LOGIN, self::PASSWORD)
                ->build();
        }
        catch (\Throwable $exception)
        {
            \Yii::error('Помилка ініціалізації Elasticsearch: ' . $exception->getMessage(), 'elasticsearch');
            return;
        }
    }

    public function getElasticClient (): ?\Elasticsearch\Client
    {
        return $this->elastic;
    }

    /**
     * Устанавливает индекс
     * @param string $index
     */
    public function setIndex(string $index)
    {
        $this->index = $index;
    }

    /**
     * Возвращает индекс
     * @return string
     */
    public function getIndex(): string
    {
        return $this->index;
    }

    /**
     * сохраняет логи работы команды
     * выводит в консоль, сохраняет в эластик и в лог ларавела
     * @param string $message текстовое сообщение(лог + консоль)
     * @param array $arLogData дополнительная отладочная информация (эластик)
     * @throws \Exception
     */
    public function toLog(string $message, array $arLogData)
    {
        $message .= ": " . $this->udate('H:i:s');
        $arLogData['message'] = $message;
        $this->toElastic(
            $arLogData
        );
    }

    /**
     * Записывает отладочную информацию в эластик
     * @param array $arLogData массив отладочной информации свободной структуры
     * @return array
     * @throws \Exception
     */
    private function toElastic(array $arLogData)
    {
        if ($this->index == null) {
            throw new \Exception('Не указано свойство index. Укажите с помощью метода setIndex');
        }
        $arEntryParams = array(
            'timestamp' => date('c')
        );
        foreach ($arLogData as $k => $l) {
            $arEntryParams[$k] = $l;
        }
        $params = [
            'index' => $this->index,
            'type' => '_doc',
            'body' => $arEntryParams
        ];
//        if (env('APP_ENV') == 'production') {
            return $this->elastic->index($params);
//        }
    }

    /**
     * метод логгирования времени
     * @param $format
     * @param null $utimestamp
     * @return false|string
     */
    private function udate($format, $utimestamp = null)
    {
        if (is_null($utimestamp)) {
            $utimestamp = microtime(true);
        }
        $timestamp = floor($utimestamp);
        $milliseconds = round(($utimestamp - $timestamp) * 1000000);
        return date(preg_replace('`(?<!\\\\)u`', $milliseconds, $format), $timestamp);
    }

    public function buildBodyProductSearch ($queryString): array
    {
        $result = [
            "_source" => ["id", "name", "sku", 'product_id', 'category_uuid'],
            "query" => [
                "bool" => [
                    "must" => [
                        [
                            "multi_match" => [
                                "query" => $queryString,
                                "fields" => [
                                    "name.rebuilt_russian^5",
                                    "name.ukrainian^5",
                                    "sku^10",
                                    "product_synonym^6",
                                    "product_synonym.autocomplete^4",
                                ],
                            ]
                        ]
                    ],
                    "should" => [
                        // 🎯 точное совпадение фразы (самый высокий приоритет)
                        [
                            "match_phrase" => [
                                "name.rebuilt_russian" => [
                                    "query" => $queryString,
                                    "boost" => 15
                                ]
                            ]
                        ],
                        [
                            "match_phrase" => [
                                "name.ukrainian" => [
                                    "query" => $queryString,
                                    "boost" => 15
                                ]
                            ]
                        ],

                        // 🔎 автодополнение (name)
                        [
                            "match" => [
                                "name.autocomplete" => [
                                    "query" => $queryString,
                                    "boost" => 5
                                ]
                            ]
                        ],

                        // 📝 поиск с опечатками (name)
                        [
                            "multi_match" => [
                                "query" => $queryString,
                                "fields" => [
                                    "name.rebuilt_russian^3",
                                    "name.ukrainian^3"
                                ],
                                "fuzziness" => "AUTO",
                                "prefix_length" => 2,
                                "boost" => 2
                            ]
                        ],

                        // 🎯 точное совпадение фразы в синонимах
                        [
                            "match_phrase" => [
                                "product_synonym" => [
                                    "query" => $queryString,
                                    "boost" => 8
                                ]
                            ]
                        ],

                        // 📝 поиск по синонимам (fuzzy)
                        [
                            "multi_match" => [
                                "query" => $queryString,
                                "fields" => ["product_synonym^4"],
                                "fuzziness" => "AUTO",
                                "prefix_length" => 2,
                                "boost" => 2
                            ]
                        ],

                        // 🔎 автодополнение по синонимам
                        [
                            "match" => [
                                "product_synonym.autocomplete" => [
                                    "query" => $queryString,
                                    "boost" => 4
                                ]
                            ]
                        ]
                    ],
                    "filter" => [
                        ["term" => ["deleted" => 0]]
                    ]
                ]
            ],
            "aggs" => [
                "category" => [
                    "terms" => ["field" => "category_name"],
                    "aggs" => [
                        "ids" => [
                            "terms" => ["field" => "id"]
                        ]
                    ]
                ],
                "brand" => [
                    "terms" => ["field" => "brand_name"],
                    "aggs" => [
                        "ids" => [
                            "terms" => ["field" => "id"]
                        ]
                    ]
                ]
            ]
        ];

        if (strstr($queryString, ' ') !== false)
        {
            $result['query']['bool']['must'][0]['multi_match']['minimum_should_match'] = "80%";
        }

        return $result;
    }

}
