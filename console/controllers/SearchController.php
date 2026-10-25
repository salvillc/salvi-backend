<?php

namespace console\controllers;

use app\models\ProductPhoto;
use api\helpers\Elastic;
use api\helpers\TypeWebsite;
use yii\console\Controller;
use common\models\Product;
use Elasticsearch\ClientBuilder;
use api\helpers\UserEmailHelper;


class SearchController extends Controller
{

    public $elasticClient;

    private $indexName = '';

    public $elasticLog = null;

//Еластик стоит локально на серваке
    public function actionUpdateTable($arg = '')
    {
        echo date('d-m-Y H:i:s') . PHP_EOL;

        try
        {
            $this->setElastic();

            $this->elasticLog->toLog(Elastic::MESSAGE_START, ['Обновление таблицы']);

            (new \yii\db\Query())->createCommand()->truncateTable('elastic_search_source')->execute();

            (new \yii\db\Query())->createCommand()
                ->insert('elastic_search_source',
                    (new \yii\db\Query())->addSelect([
                        'p.uuid as uuid',
                        'p.name as name',
                        'p.description as description',
                        'p.unit_id as unit_id',
                        'p.unit_uuid as unit_uuid',
                        'p.brand_id as brand_id',
                        'p.deleted as deleted',
                        'p.uktzed as uktzed',
                        'p.sort as sort',
                        'pb.name as brand_name',
                        'pc.category_id as category_id',
                        'pc.category_uuid as category_uuid',
                        'c.name as category_name',
//                'pa.attribute_id as attribute_id',
//                'pa.value_id as value_id',
//                'a.name as attribute_name',
//                'av.name as attribute_value',
                        'pu.full_name as full_name',
                        'pu.short_name as short_name',
                        'pp.name as product_image',
                        'p.buy_price as price',
                        'p.id as product_id',
                        'u.name as unit_name',
                        'p.synonym as product_synonym',
                        'p.sku as sku'
                    ])
                        ->from('product p')
                        ->leftJoin('product_brand pb','p.brand_id = pb.id')
                        ->leftJoin('product_photo pp','p.uuid = pp.product_uuid')
                        ->leftJoin('product_category pc','p.id = pc.product_id')
                        ->leftJoin('category c','c.id = pc.category_id AND c.uuid = pc.category_uuid')
//                ->leftJoin('product_attribute pa','pa.product_uuid = p.uuid')
//                ->leftJoin('attribute a','pa.attribute_id = a.id')
//                ->leftJoin('attribute_value av','pa.value_id = av.id')
                        ->leftJoin('product_uktzed pu','pu.id = p.uktzed')
                        ->leftJoin('unit u','u.id = p.unit_id')
                        ->where(['=', 'c.delete', 0])
                        ->andWhere(['!=', 'p.deleted', Product::STATUS_HIDDEN])
                )

                ->execute();

            $this->elasticLog->toLog(Elastic::MESSAGE_PROCESS, ['Обновили таблицу']);

            if(!empty($arg))
            {
                $this->elasticLog->toLog(Elastic::MESSAGE_PROCESS, ['Запускаем индексотор']);

                $this->actionSearch();
            }

            $this->elasticLog->toLog(Elastic::MESSAGE_FINISH, ['Отработал индексотор']);
        }
        catch (\Exception $e)
        {
            if(TypeWebsite::getType() == 'prod')
            {
                UserEmailHelper::defaultMessage(
                    [
                        'view' => 'scriptFinished',
                        'user' => ['email' => 'nazarenko@salvigroup.com.ua'],
                        'text' => $e->getMessage()
                    ]
                );
            }
        }
    }


    public function actionSearch()
    {
        $this->setElastic();

        $this->elasticLog->toLog(Elastic::MESSAGE_START, ['Начианем индексацию']);

        $this->indexName = TypeWebsite::getType().".salvi.ua.search";

        $this->elasticClient = ClientBuilder::create()
            ->setHosts([
                Elastic::HOST.':'.Elastic::PORT,
            ])
            ->setBasicAuthentication(
                Elastic::LOGIN,
                Elastic::PASSWORD
            )
            ->build();

        $this->elasticLog->toLog(Elastic::MESSAGE_PROCESS, ['удаляем индекс']);

        $this->deleteIndex();

        $this->elasticLog->toLog(Elastic::MESSAGE_PROCESS, ['удалили индекс']);

        $this->createIndex();

        $this->elasticLog->toLog(Elastic::MESSAGE_PROCESS, ['создали индекс']);
        
        try {

            // пихаем данные
            $count = (new \yii\db\Query())
                ->from('elastic_search_source')
                ->count();

            $allPageCount = ceil($count / 1000);

            $offSet = 0;

            $this->elasticLog->toLog(Elastic::MESSAGE_PROCESS,['отправяем данные для индексации']);

            for ($i = 1; $i <= $allPageCount; ++$i)
            {
                $elements = (new \yii\db\Query())
                    ->select(['*'])
                    ->from('elastic_search_source')
                    ->offset($offSet)
                    ->limit(1000)
                    ->createCommand()
                    ->queryAll();

                $offSet += 1000;

                foreach ($elements as $element)
                {
                    $this->sendData($element);
                }
            }
        }
        catch (\Exception $exception)
        {
            $this->elasticLog->toLog(Elastic::MESSAGE_ERROR,[$exception->getMessage()]);
        }

	    echo "\r\nIndex has been added\r\n";

        $this->elasticLog->toLog(Elastic::MESSAGE_FINISH,[]);
    }

    private function setElastic()
    {
        if(is_null($this->elasticLog))
        {
            $elasticIndexName = "salvi_ua_".TypeWebsite::getType()."_indexator";

            $this->elasticLog = new Elastic();

            $this->elasticLog->setIndex($elasticIndexName);
        }
    }

    private function deleteIndex()
    {
        try {
            $this->elasticClient->indices()->delete([
                'index' => $this->indexName
            ]);

            echo "\r\nIndex has been deleted!\r\n";
        } catch (\Exception $exception) {
            ;
        }
    }

    private function createIndex() {
        try {
//            $logFile = "/var/www/salvi/www_api/indexElasticSettings.txt";
//            $params = file_get_contents($logFile);
//
//            $params = json_decode($params, true);

            $synonyms = [];

//            $listDb = Product::find()
//                ->where(['deleted' => 0])
//                ->andWhere(['is_buffer' => 0])
//                ->all();
//
//            $t = 0;
//
//            foreach ($listDb as $product)
//            {
//                $synonyms = array_merge($synonyms,explode(",", $product->synonym));
//
//                ++$t;
//            }

//            $synonyms = array_unique($synonyms);

//            $params['body']['settings']['analysis']['filter']['synonym_filter']['synonyms'] = $synonyms;

            $params = [
                'index' => $this->indexName,
                'body' => [
                    'settings' => [
                        'number_of_shards' => 3,
                        'number_of_replicas' => 1,
                        'analysis' => [
                            'filter' => [
                                'edge_ngram_filter' => [
                                    'type' => 'edge_ngram',
                                    'min_gram' => 2,
                                    'max_gram' => 20,
                                ],
                                'my_stopwords' => [
                                    'type' => 'stop',
                                    'stopwords' => ["для", "і", "и", "с", "на", "по", "от", "в", "_russian_"]
                                ],
                                'uk_stop' => [
                                    'type' => 'stop',
                                    'stopwords' => ["а", "але", "та", "і", "в", "на", "по", "з", "що"]
                                ],
                                'synonym' => [
                                    'type' => 'synonym',
                                    'lenient' => true,
                                    'synonyms' => [
                                        "наушник => навушники",
                                        "уши => навушники",
                                    ]
                                ],
                            ],
                            'analyzer' => [
                                'rebuilt_russian' => [
                                    'tokenizer' => 'standard',
                                    'filter' => ['lowercase', 'my_stopwords', 'synonym']
                                ],
                                'rebuilt_ukrainian' => [
                                    'tokenizer' => 'standard',
                                    'filter' => ['lowercase', 'uk_stop']
                                ],
                                'autocomplete' => [
                                    'tokenizer' => 'standard',
                                    'filter' => ['lowercase', 'edge_ngram_filter']
                                ],
                                'autocomplete_search' => [
                                    'tokenizer' => 'standard',
                                    'filter' => ['lowercase']
                                ]
                            ]
                        ]
                    ],
                    'mappings' => [
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'uuid' => ['type' => 'keyword'],
                            'sku' => ['type' => 'keyword', 'boost' => 3],
                            'name' => [
                                'type' => 'text',
                                'analyzer' => 'rebuilt_russian',
                                'search_analyzer' => 'rebuilt_russian',
                                'fields' => [
                                    'raw' => ['type' => 'keyword'],
                                    'autocomplete' => [
                                        'type' => 'text',
                                        'analyzer' => 'autocomplete',
                                        'search_analyzer' => 'autocomplete_search'
                                    ],
                                    'ukrainian' => [
                                        'type' => 'text',
                                        'analyzer' => 'rebuilt_ukrainian',
                                        'search_analyzer' => 'rebuilt_ukrainian',
                                    ],
                                ],
                                'boost' => 3
                            ],
                            'description' => [
                                'type' => 'text',
                                'fields' => [
                                    'raw' => ['type' => 'keyword'],
                                    'russian' => ['type' => 'text', 'analyzer' => 'russian'],
                                    'rebuilt_russian' => ['type' => 'text', 'analyzer' => 'rebuilt_russian'],
                                    'ukrainian' => ['type' => 'text', 'analyzer' => 'rebuilt_ukrainian'],
                                ],
                                'boost' => 1
                            ],
                            'product_synonym' => [
                                'type' => 'text',
                                'analyzer' => 'rebuilt_russian',
                                'search_analyzer' => 'rebuilt_russian',
                                'fields' => [
                                    'raw' => ['type' => 'keyword'],
                                    'autocomplete' => [
                                        'type' => 'text',
                                        'analyzer' => 'autocomplete',
                                        'search_analyzer' => 'autocomplete_search'
                                    ],
                                    'ukrainian' => [
                                        'type' => 'text',
                                        'analyzer' => 'rebuilt_ukrainian',
                                        'search_analyzer' => 'rebuilt_ukrainian',
                                    ],
                                ],
                                'boost' => 3
                            ],
                            'unit_id' => ['type' => 'integer'],
                            'unit_uuid' => ['type' => 'keyword'],
                            'deleted' => ['type' => 'integer'],
                            'brand_id' => ['type' => 'integer'],
                            'brand_name' => ['type' => 'keyword', 'boost' => 1],
                            'category_id' => ['type' => 'integer'],
                            'category_uuid' => ['type' => 'keyword'],
                            'category_name' => ['type' => 'keyword'],
                            'attribute_id' => ['type' => 'integer'],
                            'attribute_value' => ['type' => 'keyword'],
                            'full_name' => ['type' => 'keyword'],
                            'short_name' => ['type' => 'keyword'],
                            'sort' => ['type' => 'integer'],
                        ]
                    ]
                ]
            ];

            $this->elasticClient->indices()->create($params);

            echo "\r\nIndex has been added\r\n";

        } catch (\Exception $exception) {
            echo "Create index: {$exception->getMessage()}\r\n";
        }
    }

    private function sendData($item)
    {
            $params = [
                'index' => $this->indexName,
                'body' => $item
            ];

            try {
                $response = $this->elasticClient->index($params);

            } catch (\Exception $e) {
                print_r($e->getMessage() . PHP_EOL);
                exit;
            }
    }

    /**
     *
     * # term/deleted 0-2 для управления активными/отключенными товарами
     * # term/category_id для поиска внутри категории
     * GET dev.salvi.ua.search/_search
     * {
     * "query": {
     * "bool": {
     * "must": [
     * {
     * "multi_match": {
     * "query": "BM.8350-01",
     * "fields": [
     * "brand_name.keyword",
     * "name",
     * "sku"
     * ]
     * }
     * },
     * {
     * "term": {
     * "deleted": "2"
     * }
     * },
     * {
     * "term": {
     * "category_id": "12"
     * }
     * }
     * ]
     * }
     * }
     * }
     */


}