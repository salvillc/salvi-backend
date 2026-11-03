<?php


namespace api\controllers\v1;


use api\controllers\RestController;
use api\helpers\Elastic;
use api\helpers\TypeWebsite;
use api\helpers\CategoryTree;
use api\schema\PaginationType;
use common\models\BudgetSpecification;
use common\models\Category;
use common\models\Company;
use common\models\Order;
use common\models\Product;
use common\models\Specification;
use common\models\Unit;
use common\models\ElasticSearchHistoryProsses;
use api\helpers\AppRequestHelper;
use api\models\forms\ElasticSearchHistoryProssesForm;
use common\models\PromoMenuItem;
use Yii;
use yii\db\Expression;

use api\services\CatalogFilterServiceMultiTable;
use yii\caching\TagDependency;


class SearchController extends RestController
{
    const CACHE_TIME = 260000;

    private $user;

    private $location_id = 0;

    private $categoryName = null;

    private $categoryCode = null;

    private $categoryUuid = null;

    private $categoryId = null;

    private $elasticClient = null;

    private $queryString = null;

    private $categoriesTree = null;

    public function actionSetSearchClue ()
    {
        $result = [];

        $this->elasticClient = (new Elastic())->getElasticClient();

        $this->user = Yii::$app->user->getIdentity();

        $queryStringCategoryName = Yii::$app->getRequest()->get('query', "");

        // ока не нужно , перенес логику в другое место
//        $category = Category::find()->where(['name' => $queryStringCategoryName])->one();
//
//        if ($category)
//        {
//            $this->categoryUuid = $category->uuid;
//
//            $this->categoryId = $category->id;
//        }
//
//
//
//
//        if(strlen($queryStringCategoryName) > 0)
//        {
//            $this->sendSearchHistory($queryString);
//        }

        return $this->successResponse($result);
    }

    public function actionGetSearchBaseHistoryElastic ()
    {
        $result = [];

        $AppRequestHelper = new AppRequestHelper();

        $this->elasticClient = (new Elastic())->getElasticClient();

        $pagination = $AppRequestHelper->getFromPost('pagination');

        $dateFrom = $AppRequestHelper->getFromPost('dateFrom');

        $dateTo = $AppRequestHelper->getFromPost('dateTo');

        $query = $AppRequestHelper->getFromPost('query');

        $checked = $AppRequestHelper->getFromPost('checked');

        if ($checked)
        {
            $existingQueries = ElasticSearchHistoryProsses::find()
                ->select('query')
                ->column();
        }

        $body = [
            'size' => 0, // документы не нужны, только агрегация
            'query' => [
                'bool' => [
                    'filter' => [
                        [
                            'range' => [
                                'timestamp' => [
                                    'gte' => $dateFrom,
                                    'lte' => $dateTo,
                                    'format' => 'strict_date_optional_time'
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'aggs' => [
                'by_query' => [
                    'terms' => [
                        'field' => 'query',
                        'size' => 1000,
                        'order' => [
                            '_count' => $pagination['sortOrder']
                        ]

                    ]
                ]
            ]
        ];

        if ( !empty($query))
        {
            $body['query']['bool']['filter'][] = [
                'wildcard' => [
                    'query' => [
                        'value' => '*' . mb_strtolower($query) . '*'
                    ]
                ]
            ];
        }

        if ($checked)
        {
            $body['query']['bool']['filter'][] = [
                'terms' => [
                    'query' => $existingQueries
                ]
            ];
        }

        $data = $this->elasticClient->search([
            'index' => "prod.salvi.ua.search_query",
            'body' => $body,
        ]);

        $itemsHits = $data['aggregations']['by_query']['buckets'];

        $countNeedSkip = $pagination['page'] * $pagination['rowsPerPage'] - $pagination['rowsPerPage'];

        $countNeedStop = $countNeedSkip + $pagination['rowsPerPage'];

        $result['items'] = [];

        $result['total'] = count($itemsHits);

        foreach ($itemsHits as $key => $hit)
        {
            if ($countNeedSkip > $key)
            {
                continue;
            }

            if ($countNeedStop > $key)
            {
                $result['items'][] = $hit;

            }
            else if ($countNeedStop < $key)
            {
                break;
            }
        }

        $listQueryList = [];

        foreach ($result['items'] as $item)
        {
            $listQueryList[] = $item['key'];
        }

        $existingQueries = ElasticSearchHistoryProsses::find()
            ->select('query')
            ->where(['query' => $listQueryList])
            ->column();

        $listQueryListExistingItems = array_intersect($listQueryList, $existingQueries);

        foreach ($result['items'] as &$item)
        {
            if (in_array($item['key'], $listQueryListExistingItems))
            {
                $item['exist'] = true;
            }
        }

        unset($item);

        return $this->successResponse($result);
    }

    public function actionGetSearchHistoryElastic ()
    {
        $result = [];

        $AppRequestHelper = new AppRequestHelper();

        $this->elasticClient = (new Elastic())->getElasticClient();

        $this->user = Yii::$app->user->getIdentity();

        $pagination = $AppRequestHelper->getFromPost('pagination');

        $dateFrom = $AppRequestHelper->getFromPost('dateFrom');

        $dateTo = $AppRequestHelper->getFromPost('dateTo');

        $countProduct = $AppRequestHelper->getFromPost('countProduct');

        $typeCountProduct = $AppRequestHelper->getFromPost('typeCountProduct');

        $query = $AppRequestHelper->getFromPost('query');

        $checked = $AppRequestHelper->getFromPost('checked');

        $email = $AppRequestHelper->getFromPost('email');

        $strictEmail = $AppRequestHelper->getFromPost('strictEmail');

        $isNeedCsv = $AppRequestHelper->getFromPost('needCsv');

        $existingQueries = [];

        if ( !is_null($checked) && $checked != 'false')
        {
            $existingQueries = ElasticSearchHistoryProsses::find()
                ->select('query')
                ->column();
        }

        $from = 0;

        if ($pagination['page'] > 1)
        {
            $from = $pagination['page'] * $pagination['rowsPerPage'] - $pagination['rowsPerPage'];
        }

        $body = [
            "fields" => [
                [
                    "field" => "timestamp",
                    "format" => "date_time"
                ]
            ],
            "stored_fields" => ["*"],
            "runtime_mappings" => [
                "count_products" => [
                    "type" => "double"
                ]
            ],
            "_source" => [
                "excludes" => []
            ],
//            'collapse' => [
//                'field' => 'email'
//            ],

            "sort" => [
                [
                    "timestamp" => [
                        "order" => $pagination['sortOrder']
                    ]
                ]
            ],
            "query" => [
                "bool" => [
                    "must" => [],
                    "filter" => [
                        [
                            "range" => [
                                "timestamp" => [
                                    "gte" => "$dateFrom.000Z",
                                    "lte" => "$dateTo.000Z",
                                    "format" => "strict_date_optional_time"
                                ]
                            ]
                        ]
                    ],
                    "should" => [],
                    "must_not" => []
                ]
            ]
        ];

        if ( !empty($query))
        {
            $body['query']['bool']['filter'][] = [
                'wildcard' => [
                    'query' => [
                        'value' => '*' . mb_strtolower($query) . '*'
                    ]
                ]
            ];
        }

        if ( !is_null($checked) && $checked != 'false')
        {
            $body['query']['bool']['filter'][] = [
                'terms' => [
                    'query' => $existingQueries
                ]
            ];
        }

        if ( !is_null($typeCountProduct) && !is_null($countProduct))
        {
            if ($typeCountProduct == "=")
            {
                $body['query']['bool']['filter'][] = [
                    'term' => [
                        'count_products' => $countProduct
                    ]
                ];

            }
            else if ($typeCountProduct == ">")
            {
                $body['query']['bool']['filter'][] = [
                    'range' => [
                        'count_products' => [
                            'gt' => $countProduct
                        ]
                    ]
                ];
            }
            else if ($typeCountProduct == "<")
            {
                $body['query']['bool']['filter'][] = [
                    'range' => [
                        'count_products' => [
                            'lt' => $countProduct
                        ]
                    ]
                ];
            }
        }

        if ( !empty($email))
        {

            if ( !is_null($strictEmail) && $strictEmail != 'false')
            {

                $body['query']['bool']['filter'][] = [
                    'term' => [
                        'email' => mb_strtolower($email),
                    ],
                ];

            }
            else
            {

                $body['query']['bool']['filter'][] = [
                    'wildcard' => [
                        'email' => [
                            'value' => '*' . mb_strtolower($email) . '*',
                            'case_insensitive' => true,
                        ],
                    ],
                ];

            }
        }

        $data = $this->elasticClient->search([
            'index' => "prod.salvi.ua.search_query",
            'from' => $from,
            'size' => $pagination['rowsPerPage'],
            'body' => $body,
        ]);

        $result['total'] = $data['hits']['total']['value'];

        $itemsHits = $data['hits']['hits'];

        $listQueryList = [];

        foreach ($itemsHits as $item)
        {
            $listQueryList[] = $item['_source']['query'];
        }

        $existingQueries = ElasticSearchHistoryProsses::find()
            ->select('query')
            ->where(['query' => $listQueryList])
            ->column();

        $listQueryListExistingItems = array_intersect($listQueryList, $existingQueries);

        foreach ($itemsHits as &$item)
        {
            if (in_array($item['_source']['query'], $listQueryListExistingItems))
            {
                $item['_source']['exist'] = true;
            }
        }

        unset($item);

        $result['items'] = $itemsHits;

        if ( !is_null($isNeedCsv) && $isNeedCsv != 'false')
        {
            $companyList = Company::find()->all();

            $companyListMap = [];

            foreach ($companyList as $company)
            {
                $companyListMap[$company['id']] = trim($company['full_name']);
            }

            $file = fopen('/var/www/salvi/www_api/api/web/files/searchHistoryElastic.csv', 'w');

            fputs($file, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

            $data = [];

            $fields = [
                'Пошуковий запит',
                'Компанія',
                'email',
                'Ім\'я',
                'Прізвище',
                'Номер телефону',
                'username',
                'Дата',
                'Час',
                'Кількість товарів',
                'ID категорії',
                'Назва категорії',
                'Позначка',

            ];

            $data[] = $fields;

            foreach ($itemsHits as $item)
            {
                $item = $item['_source'];

                $fields = [
                    'query' => $item['query'],
                    'company_name' => $companyListMap[$item['company_id']] ?? 'пусто',
                    'email' => $item['email'],
                    'first_name' => $item['first_name'],
                    'last_name' => $item['last_name'],
                    'phone_number' => $item['phone_number'],
                    'username' => $item['username'],
                    'date' => date("d.m.Y", strtotime($item['timestamp'])),
                    'time' => date("H:i:s", strtotime($item['timestamp'])),
                    'count_products' => $item['count_products'],
                    'category_id' => (isset($item['category_id'])) ? $item['category_id'] : '',
                    'category_name' => (isset($item['category_name'])) ? $item['category_name'] : '',
                    'exist' => (isset($item['exist']) ? 1 : 0),

                ];

                $data[] = $fields;
            }

            foreach ($data as $datum)
            {
                fputcsv($file, $datum, ';');
            }

            fclose($file);

            return $this->successResponse(['ulr' => 'https://api.salvi.ua/files/searchHistoryElastic.csv?time='.time(),'countRow' => count($data)]);
        }

        return $this->successResponse($result);
    }

    public function actionCreateElasticSearchHistoryProsses ()
    {
        $model = new ElasticSearchHistoryProssesForm();

        if ($model->load(Yii::$app->getRequest()->getBodyParams(), '') && $model->validate())
        {
            $result = $model->create();

            if ( !is_array($result))
            {
                return $this->successResponse();
            }
            else
            {
                return $this->failResponse($result);
            }
        }

        $model->validate();

        return $model;
    }

    public function actionResetSearchHistoryElasticProsess ()
    {
        $AppRequestHelper = new AppRequestHelper();

        $type = $AppRequestHelper->getFromPost('type');

        if ( !is_null($type))
        {
            $items = ElasticSearchHistoryProsses::find()
                ->select(['id', 'query'])
                ->where(['type' => $type])
                ->all();

            foreach ($items as $item)
            {
                $item->delete();
            }

            return $this->successResponse();
        }
        else
        {
            return $this->failResponse(['massage' => 'Пустий тип']);
        }

    }

    public function actionGetCategorySearch ()
    {
        $result = [];

        $AppRequestHelper = new AppRequestHelper();

        $Elastic = new Elastic();

        $this->elasticClient = $Elastic->getElasticClient();

        $this->user = Yii::$app->user->getIdentity();

        $locationId = $AppRequestHelper->getFromGet('location_id',0);

        $this->queryString = $AppRequestHelper->getFromGet('query', "");

        $result['urls'] = [];

        if (strlen($this->queryString) > 0)
        {
            $body = $Elastic->buildBodyProductSearch($this->queryString);

            try
            {
                $categoryListElastic = [];

                $data = $this->elasticClient->search([
                    'index' => TypeWebsite::getType() . ".salvi.ua.search",
                    'from' => 0,
                    'size' => 500,
                    'body' => $body,
                ]);

                foreach ($data['hits']['hits'] as $item)
                {
                    if ( !isset($categoryListElastic[$item['_source']['category_uuid']]))
                    {
                        $categoryListElastic[$item['_source']['category_uuid']]['count'] = 1;
                        $categoryListElastic[$item['_source']['category_uuid']]['products'][] = $item['_source']['product_id'];
                    }
                    else
                    {
                        ++$categoryListElastic[$item['_source']['category_uuid']]['count'];

                        if (count($categoryListElastic[$item['_source']['category_uuid']]['products']) < 4)
                        {
                            $categoryListElastic[$item['_source']['category_uuid']]['products'][] = $item['_source']['product_id'];
                        }
                    }
                }

                arsort($categoryListElastic);

                $tmpList = [];

                $i = 0;

                foreach ($categoryListElastic as $key => $count)
                {
                    if ($i > 9)
                    {
                        continue;
                    }

                    $tmpList[] = $key;

                    ++$i;
                }

                $this->categoriesTree = new CategoryTree(true,0,$locationId,0,false,true);

                $allCategories = [];

                Category::flattenCategories($this->categoriesTree->tree, $allCategories);

                $result['urls'] = [];

                $args = [
                    'location_id' => $locationId,
                    'specification_only' => $AppRequestHelper->getFromPost('specification_only'),
                ];

                $specifications = Specification::getListByParams($args['location_id'],$this->user->company_id);

                foreach ($allCategories as $category)
                {
                    if($category['level'] != 3)
                    {
                        continue;
                    }

                    if (in_array($category['uuid'], $tmpList))
                    {
                        $query = Product::find()->joinWith([
                            'images',
                        ]);

                        $query = CatalogFilterServiceMultiTable::applySpecificationFilter(
                            $query,
                            $specifications,
                            $args
                        );

                        $query = CatalogFilterServiceMultiTable::loadSpecificationRelation(
                            $query,
                            $specifications
                        );

                        $query->where(['in', 'product.id', $categoryListElastic[$category['uuid']]['products']]);

                        $query->andWhere(['!=', 'product.deleted', Product::STATUS_HIDDEN]);

                        $query->orderBy([
                            'product.deleted' => SORT_ASC,
                            new Expression(sprintf("FIELD(product.id, %s)", implode(",", $categoryListElastic[$category['uuid']]['products']))),
                        ]);

                        $resultDbQuery = $query->all();

                        $newProducts = $this->processPrepareProduct($resultDbQuery, $specifications);

                        if (count($newProducts) > 0)
                        {
                            $result['urls'][] = [
                                'path' => "$category[code]",
                                'name' => $category['name'],
                                'productList' => $newProducts,
                                'countItems' => $categoryListElastic[$category['uuid']]['count']
                            ];
                        }
                    }
                }
            }
            catch (\Exception $e)
            {
                return $this->failResponse(['error' => $e->getMessage()]);
            }
        }

        return $this->successResponse($result);
    }

    public function actionGetHistorySearch ()
    {
        $result = [];

        $this->elasticClient = (new Elastic())->getElasticClient();

        $this->user = Yii::$app->user->getIdentity();

        $AppRequestHelper = new AppRequestHelper();

        $queryString = $AppRequestHelper->getFromGet('query', "");

        $body = [
            "_source" => [
                "query",
                "email",
                "timestamp",
            ],
            "profile" => false,
            "aggs" => [
                "my-agg-name" => [
                    "terms" => [
                        "field" => "query",
                        "order" => [
                            "timestamp" => "desc"  // Сортировка по полю типа int по убыванию
                        ],

                    ],
                    "aggs" => [
                        "timestamp" => [
                            "max" => [
                                "field" => "timestamp"
                            ]
                        ]
                    ]
                ]
            ],
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            "wildcard" => [
                                "query" => [
                                    "value" => "*$queryString*"
                                ]
                            ]
                        ],
                        [
                            "term" => [
                                "email" => $this->user['email'],
                            ],
                        ]
                    ],
                ],
            ],

        ];

        try
        {
            $data = $this->elasticClient->search([
                'index' => $this->getNameSearchIndex(),
                'from' => 0,
                'size' => 10,
                'body' => $body,
            ]);

            if (isset($data['aggregations']) && isset($data['aggregations']['my-agg-name']) && isset($data['aggregations']['my-agg-name']['buckets']))
            {
                $result['list'] = $data['aggregations']['my-agg-name']['buckets'];

                $categories = Category::find()->where(['in', 'name', array_column($result['list'], 'key')])->all();

                foreach ($categories as $category)
                {
                    if ( !empty($category->full_path))
                    {
                        foreach ($result['list'] as &$item)
                        {
                            if ($item['key'] == $category->name)
                            {
                                $item['url'] = $category->full_path;
                            }
                        }
                    }
                }

                unset($item);

                return $this->successResponse($result);
            }
        }
        catch (\Exception $e)
        {
            return $this->failResponse(['error' => $e->getMessage()]);
        }
    }

    public function actionSearch ()
    {
        $Elastic = new Elastic();

        $this->elasticClient = $Elastic->getElasticClient();

        $this->user = Yii::$app->user->getIdentity();

        $AppRequestHelper = new AppRequestHelper();

        $this->queryString = $AppRequestHelper->getFromPost('query', "");

        $this->categoryCode = $AppRequestHelper->getFromPost('category');

        if ( !empty($this->categoryCode))
        {
            $category = Category::find()->where(['code' => $this->categoryCode])->one();

            if ($category)
            {
                $this->categoryUuid = $category->uuid;

                $this->categoryId = $category->id;

                $this->categoryName = $category->name;
            }
        }

        $is_search_empty = false;

        if ( !empty($this->queryString))
        {
            $body = $Elastic->buildBodyProductSearch($this->queryString);

            // Если пользователь выбрал категорию
            if ( !empty($this->categoryId))
            {
                $body['query']['bool']['must'][] = [
                    "term" => [
                        "category_id" => $this->categoryId,
                    ],
                ];
            }

            try
            {
                $data = $this->elasticClient->search([
                    'index' => TypeWebsite::getType() . ".salvi.ua.search",
                    'from' => 0,
                    'size' => 5000,
                    'body' => $body,
                ]);

                if (count($data['hits']['hits']) == 0)
                {
                    $is_search_empty = true;
                }
            }
            catch (\Exception $e)
            {
                return $this->failResponse(['error' => $e->getMessage()]);
            }
        }

        return $this->successResponse($this->getProducts(isset($data) && !empty($data) ? $data['hits']['hits'] : [], $is_search_empty));
    }


    public function getProducts (array $ids = [], $is_search_empty = false)
    {
        $catalogOptions = [];

        $this->user = Yii::$app->user->getIdentity();

        if (!Yii::$app->user->can('orderShowBtn') && !Yii::$app->user->can('catalogShowBtn') && !Yii::$app->user->can('viewCatalog'))
        {
            return $this->accessDeniedPagination();
        }

        $products = [];

        foreach ($ids as $id)
            $products[] = (int)$id['_source']['product_id'];

        $AppRequestHelper = new AppRequestHelper();

        $pagination = $AppRequestHelper->getFromPost('pagination');

        $args = [
            'page' => $pagination['page'],
            'perPage' => $pagination['rowsPerPage'],
            'order' => $AppRequestHelper->getFromPost('order', 'ASC'),
            'orderBy' => ($pagination['sortBy'] ?? 'sort'),
            'specification_only' => $AppRequestHelper->getFromPost('specification_only'),
            'location_id' => $AppRequestHelper->getFromPost('location_id',0),
            'category_code' => $this->categoryCode,
            'category_uuid' => $this->categoryUuid,
        ];


        $args = array_merge($args, PaginationType::arguments($args, array_keys(Product::getTableSchema()->columns), [
            Unit::class,
            Category::class,
        ]));

        $specifications = Specification::getListByParams($args['location_id'],$this->user->company_id);

        $query = $this->buildProductQuery(
            $specifications,
            $args,
            $this->categoryId
        );

        if ( !empty($products))
        {
            $query->andWhere(['product.id' => $products]);
        }

        if ($this->categoryId)
        {
            $productIdsQuery = clone $query;

            $productIdsQuery->select('product.id');

            $catalogFilter = new CatalogFilterServiceMultiTable($productIdsQuery);

            $filters = $catalogFilter->getFilters();

            $query = $catalogFilter->applyToProductQuery($query);

            $exists = Product::find()
                ->where(new Expression(
                    'EXISTS(
                        SELECT 1
                        FROM product_category pc
                        WHERE pc.category_id=:cid
                        AND pc.product_id=product.id)',
                    [':cid' => $this->categoryId]
                ));

            $exists = CatalogFilterServiceMultiTable::applySpecificationFilter(
                $exists,
                $specifications,
                ['specification_only' => 1 ]
            );

            $available = $exists->exists();

            $catalogOptions['specification_only'] = [
                'available' => $available,
                'enabled' => $args['specification_only'],
            ];
        }
        else
        {
            $filters = [];
        }

        $total = (clone $query)->count();

        if (!empty($products) && !isset($pagination['orderBy']))
        {
            $query->orderBy([
                'product.deleted' => SORT_ASC,
                new Expression(sprintf(
                    'FIELD(product.id,%s)',
                    implode(',', $products)
                ))
            ]);
        }
        else
        {
            $this->applyOrdering($query, $args);
        }

        $this->applyPagination($query, $args);

        $query = $this->applyWithProducts($query, $specifications);

        $result = $query->all();

        $newProducts = $this->processPrepareProduct($result, $specifications);

        if (strlen($this->queryString) > 2)
        {
            $isNeedSend = true;
            $count = $is_search_empty ? 0 : $total;

            if ( !$is_search_empty && $count == 0)
            {
                $isNeedSend = false;
            }

            if ($isNeedSend)
            {
                $this->sendSearchHistory($this->queryString, $count);
            }
        }

        return [
            'model' => $newProducts,
            'filters' => $filters,
            'total' => (int)$total,
            'totalPerPage' => (int)count($products),
            'is_search_empty' => $is_search_empty,
            'catalogOptions' => $catalogOptions,
        ];
    }

    public function actionGetSeasonGoods ()
    {
        $this->user = Yii::$app->user->getIdentity();

        $AppRequestHelper = new AppRequestHelper();

        $pagination = $AppRequestHelper->getFromPost('pagination');

        $this->categoryCode = $AppRequestHelper->getFromPost('category');

        if ( !empty($this->categoryCode))
        {
            $category = Category::find()->where(['code' => $this->categoryCode])->one();

            if ($category)
            {
                $this->categoryUuid = $category->uuid;

                $this->categoryId = $category->id;
            }
        }

        $args = [
            'page' => $pagination['page'],
            'perPage' => $pagination['rowsPerPage'],
            'order' => ($pagination['sort'] ?? 'ASC'),
            'orderBy' => ($pagination['orderBy'] ?? 'sort'),
            'specification_only' => $AppRequestHelper->getFromPost('specification_only'),
            'location_id' => $AppRequestHelper->getFromPost('location_id',0),
            'category_code' => $this->categoryCode,
            'category_uuid' => $this->categoryUuid,
        ];

        $args = array_merge($args, PaginationType::arguments($args, array_keys(Product::getTableSchema()->columns), [
            Unit::class,
            Category::class,
        ]));

        $specifications = Specification::getListByParams($args['location_id'],$this->user->company_id);

        $query = $this->buildProductQuery(
            $specifications,
            $args,
            $this->categoryId,
            [
                'product.is_season_goods' => 1
            ]
        );

        $this->applyOrdering($query, $args);

        $this->applyPagination($query, $args);

        $totalQuery = clone $query;

        $query = $this->applyWithProducts($query, $specifications);

        $hashSql = md5($query->createCommand()->getRawSql());

        $productsCache = Yii::$app->cache->get('GetSeasonGoods_' . $hashSql);

        $withoutCache = $this->withoutCache();

        if ( !$productsCache || $withoutCache)
        {
            $productsCache['productsList'] = $query->all();

            $productsCache['result'] = $this->processPrepareProduct(
                $productsCache['productsList'],
                $specifications
            );

            $productsCache['total'] = $totalQuery->count();

            Yii::$app->cache->set('GetSeasonGoods_' . $hashSql, $productsCache, self::CACHE_TIME,new TagDependency(['tags'=>['GetSeasonGoods']]));
        }

        $result = $productsCache['result'];

        $resultTmp = $productsCache['productsList'];

        $total = $productsCache['total'];

        $categoryList = [];

        foreach ($resultTmp as $product)
        {
            $category = $product->categories[0];

            if ( !isset($categoryList[$category->id]))
            {
                $categoryTmp = $category->toArray();

                $categoryTmp['category_url'] = $category->full_path;

                $categoryList[$category['id']] = ['category' => $categoryTmp, 'count' => 1];

            }
            else
            {
                $categoryList[$category->id]['count']++;
            }
        }

        usort($categoryList, function ($a, $b)
        {
            if ($a['count'] > $b['count'])
            {
                return -1;
            }
            else if ($a['count'] < $b['count'])
            {
                return 1;
            }

            return 0;
        });

        return [
            'model' => $result,
            'total' => (int)$total,
            'categoryList' => $categoryList,
        ];

    }

    public function actionGetPopular ()
    {
        $this->user = Yii::$app->user->getIdentity();

        $AppRequestHelper = new AppRequestHelper();

        $pagination = $AppRequestHelper->getFromPost('pagination');

        $this->categoryCode = $AppRequestHelper->getFromPost('category');

        if ( !empty($this->categoryCode))
        {
            $category = Category::find()->where(['code' => $this->categoryCode])->one();

            if ($category)
            {
                $this->categoryUuid = $category->uuid;

                $this->categoryId = $category->id;
            }
        }

        $args = [
            'page' => $pagination['page'],
            'perPage' => $pagination['rowsPerPage'],
            'order' => ($pagination['sort'] ?? 'ASC'),
            'orderBy' => ($pagination['orderBy'] ?? 'sort'),
            'specification_only' => $AppRequestHelper->getFromPost('specification_only'),
            'location_id' => $AppRequestHelper->getFromPost('location_id',0),
            'category_code' => $this->categoryCode,
            'category_uuid' => $this->categoryUuid,
        ];

        $specifications = Specification::getListByParams($args['location_id'],$this->user->company_id);

        $args = array_merge($args, PaginationType::arguments($args, array_keys(Product::getTableSchema()->columns), [
            Unit::class,
            Category::class,
        ]));

        $query = $this->buildProductQuery(
            $specifications,
            $args,
            $this->categoryId,
            [
                'product.is_popular' => 1
            ]
        );

        $this->applyOrdering($query, $args);

        $this->applyPagination($query, $args);

        $totalQuery = clone $query;

        $query = $this->applyWithProducts($query, $specifications);

        $hashSql = md5($query->createCommand()->getRawSql());

        $productsCache = Yii::$app->cache->get('CategoryPopular_' . $hashSql);

        $withoutCache = $this->withoutCache();

        if (!$productsCache || $withoutCache)
        {
            $productsCache['productsList'] = $query->all();

            $productsCache['result'] = $this->processPrepareProduct(
                $productsCache['productsList'],
                $specifications
            );

            $productsCache['total'] = $totalQuery->count();

            Yii::$app->cache->set('CategoryPopular_' . $hashSql, $productsCache, self::CACHE_TIME,new TagDependency(['tags'=>['CategoryPopular']]));
        }

        $result = $productsCache['result'];

        $resultTmp = $productsCache['productsList'];

        $total = $productsCache['total'];

        $categoryList = [];

        foreach ($resultTmp as $product)
        {
            $category = $product->categories[0];

            if (!isset($categoryList[$category->id]))
            {
                $categoryTmp = $category->toArray();

                $categoryTmp['category_url'] = $category->full_path;

                if (is_null($categoryTmp['sort']))
                {
                    $categoryTmp['sort'] = 999;
                }

                $categoryList[$categoryTmp['id']] = ['category' => $categoryTmp];
            }
        }

        usort($categoryList, function ($a, $b)
        {
            if (is_null($a['category']['sort']))
            {
                return 1;
            }

            if ($a['category']['sort'] > $b['category']['sort'])
            {
                return 1;
            }
            else if ($a['category']['sort'] < $b['category']['sort'])
            {
                return -1;
            }

            return 0;
        });

        $categoryList = array_values($categoryList);

        foreach ($categoryList as $key => $category)
        {
            if ($key >= 5)
            {
                unset($categoryList[$key]);
            }
        }

        return [
            'model' => $result,
            'total' => (int)$total,
            'categoryList' => $categoryList,
        ];

    }

    private function processPrepareProduct ($list, $specificationsByArgs): array
    {
        if (is_null($this->categoriesTree))
        {
            $this->categoriesTree = new CategoryTree(true,0,0,0,false,true);
        }

        $newProducts = [];

        foreach ($list as $key => $product)
        {

            $newProducts[$key] = $product->toArray();
            $newProducts[$key]['buy_price'] = number_format($product->buy_price, 2);
            $newProducts[$key]['images'] = $product->images;
            $newProducts[$key]['unit'] = $product->unit;
            $newProducts[$key]['price'] = $product->getPriceCatalog($specificationsByArgs);
            $newProducts[$key]['quantity'] = 1;
            $newProducts[$key]['strict_multiplicity'] = $product->strict_multiplicity;
            $newProducts[$key]['isSpecificationProduct'] = $product->getIsSpecificationProduct();

            $newProducts[$key]['props'] = array_map(function ($filter) {
                return [
                    'filter_code' => $filter->property->slug ?? null,
                    'filter_name' => $filter->property->name ?? null,
                    'filter_value_code' => $filter->value->slug ?? null,
                    'filter_value' => $filter->value->name ?? null,
                ];
            }, $product->productFilters);

            $newProducts[$key]['category'] = $product->categories[0];

            $newProducts[$key]['category_url'] = '/catalog' . $newProducts[$key]['category']->full_path;

            if ( !empty($newProducts[$key]['images']))
            {
                $images = [];

                foreach ($newProducts[$key]['images'] as $image)
                {
                    $path = Yii::$app->urlManager->createAbsoluteUrl(['/product/' . $image->name]);

                    $images[]['url'] = str_replace('sapi', 'api', $path);
                }

                $newProducts[$key]['images'] = $images;

                unset($images);
            }

            $photo = $product->image;

            if ($photo)
            {
                $path = Yii::$app->urlManager->createAbsoluteUrl(['/product/' . $photo->name]);

                $newProducts[$key]['img'] = str_replace('sapi', 'api', $path);
            }
            else
            {
                $newProducts[$key]['img'] = Yii::$app->urlManager->createAbsoluteUrl(['/images/default.jpg']);
            }
        }

        return $newProducts;
    }

    public function actionGet4BlockCategories ()
    {
        $this->user = Yii::$app->user->getIdentity();

        $AppRequestHelper = new AppRequestHelper();

        $args = [
            'page' => 1,
            'perPage' => 23,
            'order' => $AppRequestHelper->getFromPost('order'),
            'specification_only' => $AppRequestHelper->getFromPost('specification_only'),
            'location_id' => $AppRequestHelper->getFromPost('location_id',0),
        ];

        $args = array_merge($args, PaginationType::arguments($args, array_keys(Product::getTableSchema()->columns), [
            Unit::class,
            Category::class,
        ]));

        $fullCacheKey = 'Get4BlockCategoriesFull_' . md5(json_encode([
            'company_id' => $this->user->company_id,
            'location_id' => $args['location_id'],
            'order' => $args['order'],
            'specification_only' => $args['specification_only'],
        ]));

        $result = Yii::$app->cache->get($fullCacheKey);

        $withoutCache = $this->withoutCache();

        if (!$result || $withoutCache)
        {
            $categoriesList3Level = Category::find()
                ->alias('c')
                ->innerJoin('product_category pc', 'pc.category_uuid = c.uuid')
                ->where([
                    'c.delete' => 0,
                    'c.level' => 3,
                ])
                ->andWhere(['>', 'c.sort', 0])
                ->groupBy('c.id')
                ->orderBy(['c.sort' => SORT_ASC])
                ->limit(20)
                ->asArray()
                ->all();

            $categories3Level4 = [];

            $categories3Level8 = [];

            $specifications = Specification::getListByParams($args['location_id'],$this->user->company_id);

            foreach ($categoriesList3Level as $category)
            {
                if (count($categories3Level8) >= 8)
                {
                    break;
                }

                $query = $this->buildProductQuery(
                    $specifications,
                    $args,
                    $category['id']
                );

                $total = (clone $query)->count();

                $args['isOnlyTwoSort'] = true;

                $this->applyOrdering($query, $args);

                $this->applyPagination($query, $args);

                $query = $this->applyWithProducts($query, $specifications);

                $hashSql = md5($query->createCommand()->getRawSql());

                $newProducts = Yii::$app->cache->get('Get4BlockCategories_' . $hashSql);

                if (!$newProducts || $withoutCache)
                {
                    $resultQuery = $query->all();

                    $newProducts = $this->processPrepareProduct($resultQuery, $specifications);

                    Yii::$app->cache->set('Get4BlockCategories_' . $hashSql, $newProducts, self::CACHE_TIME,new TagDependency(['tags'=>['Get4BlockCategories']]));
                }

                $category['category_url'] = '/catalog' . $category['full_path'];

                if ($category['img_path'])
                {
                    $category['img_path'] = Yii::$app->urlManager->createAbsoluteUrl(['/category/' . $category['img_path']]);
                }

                if (count($newProducts) > 0)
                {
                    if (count($categories3Level4) < 4)
                    {
                        $categories3Level4[] = ['category' => $category, 'products' => $newProducts, 'total' => $total];
                    }

                    $categories3Level8[] = $category;
                }
            }

            $result = [
                'categories' => $categories3Level4,
                'categories3Levels' => $categories3Level8,
            ];

            Yii::$app->cache->set($fullCacheKey, $result, self::CACHE_TIME, new TagDependency(['tags' => ['Get4BlockCategories']]));
        }

        return $this->successResponse($result);

    }

    public function actionGetCategoryPage ()
    {
        $this->user = Yii::$app->user->getIdentity();

        $AppRequestHelper = new AppRequestHelper();

        $currentCategoryCode = $AppRequestHelper->getFromPost('category');

        $result = [];

        $breadCrumbs = [
            [
                'name' => 'Головна',
                'url' => '/catalog'
            ]
        ];

        $currentCategory = null;

        $categoryListNext = [];

        $pagination = $AppRequestHelper->getFromPost('pagination');

        $args = [
            'page' => $pagination['page'],
            'perPage' => $pagination['rowsPerPage'],
            'order' => ($pagination['sort'] ?? 'ASC'),
            'orderBy' => ($pagination['orderBy'] ?? 'sort'),
            'specification_only' => $AppRequestHelper->getFromPost('specification_only'),
            'location_id' => $AppRequestHelper->getFromPost('location_id',0),
        ];

        $this->categoriesTree = new CategoryTree(true,0,$args['location_id'],0,false,true);

        // Внедряем пользовательские промо-ссылки в дерево категорий
        PromoMenuItem::injectIntoTree($this->categoriesTree->tree);

        Category::findCategory(
            $this->categoriesTree->tree,
            $currentCategoryCode,
            $breadCrumbs,
            $currentCategory,
            $categoryListNext
        );

        $specifications = Specification::getListByParams($args['location_id'],$this->user->company_id);

        $args = array_merge($args, PaginationType::arguments($args, array_keys(Product::getTableSchema()->columns), [
            Unit::class,
            Category::class,
        ]));

        if ( !empty($categoryListNext))
        {
            $standardCategoryIds = [];
            foreach ($categoryListNext as $cat) {
                if (empty($cat['is_custom']) && !empty($cat['id']) && is_numeric($cat['id'])) {
                    $standardCategoryIds[] = (int)$cat['id'];
                }
            }

            if (!empty($standardCategoryIds)) {
                $query = $this->buildProductQuery(
                    $specifications,
                    $args,
                    $standardCategoryIds
                );

                $this->applyOrdering($query, $args);

                $this->applyPagination($query, $args);

                $query = $this->applyWithProducts($query, $specifications);
            } else {
                $query = null;
            }

            $newCategoryList = [];

            foreach ($categoryListNext as $category)
            {
                if (!empty($category['is_custom'])) {
                    $category['products'] = [];
                    $newCategoryList[] = $category;
                    continue;
                }

                if (!$query) {
                    continue;
                }

                $categoryQuery = clone $query;

                $productsQuery = $categoryQuery
                    ->andWhere([
                        'pc.category_id' => $category['id']
                    ])
                    ->offset($args['perPage'] * $args['page'])
                    ->limit($args['perPage']);

                $hashSql = md5($productsQuery->createCommand()->getRawSql());

                $products = Yii::$app->cache->get('GetCategoryPage_' . $hashSql);

                $withoutCache = $this->withoutCache();

                if ( !$products || $withoutCache)
                {
                    $products = $productsQuery->all();

                    if (empty($products))
                    {
                        continue;
                    }

                    $products = $this->processPrepareProduct($products, $specifications);

                    Yii::$app->cache->set('GetCategoryPage_' . $hashSql, $products, self::CACHE_TIME,new TagDependency(['tags'=>['GetCategoryPage']]));
                }

                $category['products'] = $products;

                $newCategoryList[] = $category;
            }

            $result['breadCrumbs'] = $breadCrumbs;
            $result['categoryListNext'] = $newCategoryList;

        }
        else
        {
            $filtersParam = $AppRequestHelper->getFromPost('filters', []);

            $priceFrom = (float)$AppRequestHelper->getFromPost('price_from', 0);

            $priceTo = (float)$AppRequestHelper->getFromPost('price_to', 0);

            $hasActiveFilters = ( !empty($filtersParam) && is_array($filtersParam)) || $priceFrom > 0 || $priceTo > 0;

            $noFiltersCacheKey = 'GetCategoryPageNoFilters_' . md5(json_encode([
                'category_id' => $currentCategory['id'],
                'company_id' => $this->user->company_id,
                'location_id' => $args['location_id'],
                'order' => $args['order'],
                'orderBy' => $args['orderBy'],
                'page' => $args['page'],
                'perPage' => $args['perPage'],
                'specification_only' => $args['specification_only'],
            ]));

            $cached = $hasActiveFilters ? null : Yii::$app->cache->get($noFiltersCacheKey);

            $withoutCache = $this->withoutCache();

            if ($hasActiveFilters || !$cached || $withoutCache)
            {
                $catalogOptions = [];

                $exists = Product::find()
                    ->where(new Expression(
                        'EXISTS(
                            SELECT 1
                            FROM product_category pc
                            WHERE pc.category_id=:cid
                            AND pc.product_id=product.id)',
                        [':cid' =>  $currentCategory['id']]
                    ));

                $exists = CatalogFilterServiceMultiTable::applySpecificationFilter(
                    $exists,
                    $specifications,
                    ['specification_only' => 1]
                );

                $available = $exists->exists();

                $catalogOptions['specification_only'] = [
                    'available' => $available,
                    'enabled' => (!is_null($args['specification_only'])) ? $args['specification_only'] : false,
                ];


                // выборка самих товаров
                $query = $this->buildProductQuery(
                    $specifications,
                    $args,
                    $currentCategory['id']
                );

                $productIdsQuery = clone $query;

                $productIdsQuery->select('product.id');

                $catalogFilter = new CatalogFilterServiceMultiTable($productIdsQuery);

                $cached['filters'] = $catalogFilter->getFilters();

                $query = $catalogFilter->applyToProductQuery($query);

                $cached['total'] = (clone $query)->count();

                $this->applyOrdering($query, $args);

                $this->applyPagination($query, $args);

                $query = $this->applyWithProducts($query, $specifications);

                $products = $query->all();

                $cached['products'] = $this->processPrepareProduct(
                    $products,
                    $specifications
                );

                $cached['catalogOptions'] = $catalogOptions;

                if (!$hasActiveFilters)
                {
                    Yii::$app->cache->set($noFiltersCacheKey, $cached, self::CACHE_TIME, new TagDependency(['tags' => ['GetCategoryPage']]));
                }
            }

            $result['filters'] = $cached['filters'];

            $result['total'] = $cached['total'];

            $result['products'] = $cached['products'];

            $result['catalogOptions'] = $cached['catalogOptions'];

            $result['categoryListNext'] = [];
        }

        return $this->successResponse($result);
    }

    public function actionGetProductByCategoryOnCategoryPage ()
    {
        $this->user = Yii::$app->user->getIdentity();

        $AppRequestHelper = new AppRequestHelper();

        $pagination = $AppRequestHelper->getFromPost('pagination');

        $args = [
            'page' => $pagination['page'],
            'perPage' => $pagination['rowsPerPage'],
            'order' => ($pagination['sort'] ?? 'ASC'),
            'orderBy' => ($pagination['orderBy'] ?? 'sort'),
            'specification_only' => $AppRequestHelper->getFromPost('specification_only'),
            'location_id' => $AppRequestHelper->getFromPost('location_id',0),
            'categoryCode' => $AppRequestHelper->getFromPost('category'),
        ];

        $this->categoriesTree = new CategoryTree(true,0,$args['location_id'],0,false,true);

        $args = array_merge($args, PaginationType::arguments($args, array_keys(Product::getTableSchema()->columns), [
            Unit::class,
            Category::class,
        ]));

        $specifications = Specification::getListByParams($args['location_id'],$this->user->company_id);

        $category = Category::find()->where(['code' => $args['categoryCode']])->one();

        $query = $this->buildProductQuery(
            $specifications,
            $args,
            $category['id']
        );

        $this->applyOrdering($query, $args);

        $this->applyPagination($query, $args);

        $query = $this->applyWithProducts($query, $specifications);

        $hashSql = md5($query->createCommand()->getRawSql());

        $newProducts = Yii::$app->cache->get('GetProductByCategoryOnCategoryPage_' . $hashSql);

        $withoutCache = $this->withoutCache();

        if (!$newProducts || $withoutCache)
        {
            $products = $query->all();

            $newProducts = $this->processPrepareProduct(
                $products,
                $specifications
            );

            Yii::$app->cache->set('GetProductByCategoryOnCategoryPage_' . $hashSql, $newProducts, self::CACHE_TIME,new TagDependency(['tags'=>['GetProductByCategoryOnCategoryPage']]));
        }

        $result['products'] = $newProducts;

        return $this->successResponse($result);
    }

    private function buildProductQuery (array $specifications, array $args, $categoryId = null, array $where = [])
    {
        $query = Product::find();

        if ($categoryId)
        {
            if (is_array($categoryId))
            {
                $query->innerJoin(
                    'product_category pc',
                    'pc.product_id = product.id'
                );

                $query->andWhere(['pc.category_id' => $categoryId]);
            }
            else
            {
                $query->where(new Expression(
                    'EXISTS(
                SELECT 1
                FROM product_category pc
                WHERE pc.category_id=:cid
                AND pc.product_id=product.id
            )',
                    [':cid' => $categoryId]
                ));
            }

        }

        $query = CatalogFilterServiceMultiTable::applySpecificationFilter(
            $query,
            $specifications,
            $args
        );

        $query->andWhere(['!=', 'product.deleted', Product::STATUS_HIDDEN]);

        $query->andWhere(['product.is_buffer' => 0]);

        if ($where)
        {
            $query->andWhere($where);
        }

        return $query;
    }

    private function applyOrdering ($query, array $arguments): void
    {
        $sort = [
            'product.deleted' => SORT_ASC,
            "product.$arguments[orderBy]" => $arguments['order'] == 'DESC' ? SORT_DESC : SORT_ASC,
            'product.sort' => SORT_ASC
        ];

        if (isset($arguments['isOnlyTwoSort']))
        {
            $i = 0;

            foreach ($sort as $key => $value)
            {
                if ($i == 1)
                {
                    unset($sort[$key]);
                }

                ++$i;
            }
        }

        $query->orderBy($sort);
    }

    private function applyPagination ($query, array $arguments): void
    {
        $query->offset($arguments['perPage'] * $arguments['page'])->limit($arguments['perPage']);
    }

    private function applyWithProducts ($query, array $specifications)
    {
       // Yii::$app->cache->flush();
        $listWith = [
            'unit',
            'images',
            'productFilters.property',
            'productFilters.value',
            'categories',
        ];

        $listJoinWith = [

        ];

        $query->with($listWith);

        //$query->joinWith($listJoinWith);



        $query = CatalogFilterServiceMultiTable::loadSpecificationRelation($query, $specifications);

        return $query;
    }


    private function sendSearchHistory ($query = '', $countProducts = 0)
    {
        if (empty($query))
        {
            return false;
        }
        $params = [
            'index' => $this->getNameSearchIndex(),
        ];

        $response = $this->elasticClient->indices()->exists($params);

        if ( !$response)
        {
            $this->createSearchIndex();
        }

        $fields = [
            'query' => $query,
            'company_id' => $this->user['company_id'],
            'currency_id' => $this->user['currency_id'],
            'email' => $this->user['email'],
            'first_name' => $this->user['first_name'],
            'last_name' => $this->user['last_name'],
            'phone_number' => $this->user['phone_number'],
            'username' => $this->user['username'],
            'timestamp' => date('c'),
            'count_products' => floatval($countProducts),
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
        ];

        $params = [
            'index' => $this->getNameSearchIndex(),
            'body' => $fields,

        ];

        return $this->elasticClient->index($params);
    }

    private function getNameSearchIndex ()
    {
        return TypeWebsite::getType() . ".salvi.ua.search_query";
    }

    private function createSearchIndex ()
    {
        $params = [
            'index' => $this->getNameSearchIndex(),
            'body' => [
                'settings' => [
                    'number_of_shards' => 2,
                    'number_of_replicas' => 0,
                    'analysis' => [
                        'analyzer' => [
                            'rebuilt_russian' => [
                                'tokenizer' => 'my_tokenizer',
                                'filter' => [
                                    "lowercase",
                                ],
                            ],
                        ],
                        'normalizer' => [
                            'my_normalizer' => [
                                'type' => 'custom',
                                'char_filter' => [],
                                'filter' => ["lowercase", "asciifolding"],
                            ],
                        ],
                        'tokenizer' => [
                            'my_tokenizer' => [
                                'type' => 'standard',
                                'max_token_length' => 4,
                            ],
                        ],
                    ],
                ],
                'mappings' => [
                    'properties' => [
                        'query' => [
                            'type' => 'keyword',
                        ],
                        'company_id' => [
                            'type' => 'integer',
                        ],
                        'currency_id' => [
                            'type' => 'integer',
                        ],
                        'email' => [
                            'type' => 'keyword',
                        ],
                        'first_name' => [
                            'type' => 'keyword',
                        ],
                        'last_name' => [
                            'type' => 'keyword',
                        ],
                        'phone_number' => [
                            'type' => 'integer',
                        ],
                        'count_products' => [
                            'type' => 'float',
                        ],
                        'username' => [
                            'type' => 'keyword',
                        ],
                        'timestamp' => [
                            'type' => 'date',
                        ],
                        'category_id' => [
                            'type' => 'integer',
                        ],
                        'category_name' => [
                            'type' => 'keyword',
                        ],
                    ],
                ],

            ],
        ];

        $this->elasticClient->indices()->create($params);
    }

    private function withoutCache ()
    {
        $file = Yii::getAlias('@runtime/logs/updateProducts.log');

        $withoutCache = false;

        if(file_exists($file))
        {
            $fp = fopen($file, 'r');

            $timeTreeImportStart = fread($fp, filesize($file));

            if(time() - $timeTreeImportStart < 300)
            {
                $withoutCache = true;
            }

            if(!$withoutCache)
            {
                unlink($file);
            }
        }

        return $withoutCache;
    }
}