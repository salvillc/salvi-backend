<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 22.07.2019
 * Time: 16:21
 */


namespace api\helpers;

use common\models\BudgetSpecification;
use common\models\CatalogLimitation;
use common\models\Category;
use common\models\CategoryExclude;
use common\models\Company;
use common\models\Order;
use common\models\Product;
use common\models\ProductCategory;
use common\models\Specification;
use common\models\SpecificationProduct;
use common\models\User;
use Yii;
use yii\db\ActiveQuery;
use yii\helpers\ArrayHelper;
use yii\caching\TagDependency;


/**
 * Class CategoryTree
 * @package api\helpers
 */
class CategoryTree
{
    private $cat_tree = null;/* tree data structure */
    private $nodes = [];/* tree node array */
    private $records = null;/* data from database*/
    public $tree;

    public $selection = [];

    public $specification = false;

    /**
     * @param bool $default
     * @param $id
     * @param $location_id
     * @param $order_id
     * @param bool $add_product
     * @param bool $withCache
     */
    public function __construct (bool $default = true, $id = 0, $location_id = 0, $order_id = 0, bool $add_product = false, bool $withCache = false)
    {
        if ( !$add_product && Yii::$app->user->can('catalogShowBtn') || $add_product && Yii::$app->user->can('orderShowBtn'))
        {
            $this->specification = 0;
        }
        else
        {
            $this->specification = 1;
        }
        if ($default)
        {
            //            $this->initTree($location_id);
            $this->initTreeNew($location_id, $order_id,$withCache);
        }
        else
        {
            $this->initAllTree($id);
        }
    }

    public function initTree ($location_id)
    {
        if ($this->records != null)
        {
            return true;
        }
        /** @var  $user User */
        $user = Yii::$app->user->getIdentity();
        $cache = Yii::$app->cache;
        $key = 'categoryTree_catalog_' . $user->company_id . '_' . $location_id;
        if ($cache->exists($key))
        {
            $this->tree = $cache->get($key);

            return true;
        }
        else
        {
            $company = Company::find()->where(['id' => $user->company_id])->one();
            if ($company)
            {
                $limitationQuery = CatalogLimitation::find()->where(['company_id' => $user->company_id]);
                if ($this->specification)
                {
                    $limitationQuery->andWhere(['specification' => 1]);
                }
                else
                {
                    $limitationQuery->andWhere(['specification' => 0]);
                }
                $limitationQuery->orderBy('specification ASC');
                $limitation = $limitationQuery->all();
                $query = Category::find()->select([
                    'category.uuid',
                    'category.parent_uuid',
                    'category.name',
                    'category.parent_id',
                    'category.id',
                    'catalog_limitation.total_product',

                ])->andWhere(['category.delete' => 0]);
                if ($this->specification)
                {
                    $query->joinWith([
                        'limitation' => function (ActiveQuery $query)
                        {
                            return $query->andWhere(['=', 'catalog_limitation.specification', 1]);
                        },
                    ]);
                }
                else
                {
                    $query->joinWith([
                        'limitation' => function (ActiveQuery $query)
                        {
                            return $query->andWhere(['=', 'catalog_limitation.specification', 0]);
                        },
                    ]);
                }

                $query->andWhere(['in', 'uuid', ArrayHelper::getColumn($limitation, 'category_uuid')]);
                $query->orderBy('category.id ASC');

                $this->records = $query->asArray()->all();

                if ( !$this->specification)
                {
                    $limitation = CatalogLimitation::find()->where(['company_id' => $user->company_id])
                        ->andWhere(['specification' => 1])->all();
                    $query = Category::find()->joinWith([
                        'limitation' => function (ActiveQuery $query)
                        {
                            return $query->andWhere(['=', 'catalog_limitation.specification', 1]);
                        },
                    ])->select([
                        'category.uuid',
                        'category.parent_uuid',
                        'category.name',
                        'category.parent_id',
                        'category.id',
                        'catalog_limitation.total_product',
                    ]);
                    $query->andWhere(['category.delete' => 0]);
                    $query->andWhere(['in', 'category.uuid', ArrayHelper::getColumn($limitation, 'category_uuid')]);
                    $query->orderBy('category.id ASC');
                    $secondResult = $query->asArray()->all();
                    if ($secondResult)
                    {
                        $secondResult = ArrayHelper::index($secondResult, 'uuid');
                        $firstResult = ArrayHelper::index($this->records, 'uuid');
                        $this->records = array_merge($secondResult, $firstResult);
                    }
                }
                foreach ($this->records as $k => $record)
                {
                    if ( !empty($record['parent_uuid']) && is_null($record['parent_id']))
                    {
                        unset($this->records[$k]);
                    }
                }
                $this->tree = $this->buildTree($this->records);
                $cache->set($key, $this->tree, 3600 * 24);

                return true;
            }
            else
            {
                $this->tree = [];

                return true;
            }
        }
    }

    public function initAllTree ($id)
    {
        $this->records = null;
        $query = Category::find()->leftJoin(CatalogLimitation::tableName(), 'catalog_limitation.category_uuid = category.uuid AND
                    catalog_limitation.company_id = ' . $id . ' AND catalog_limitation.specification = 0')->select([
            'category.uuid',
            'category.parent_uuid',
            'category.name',
            'category.parent_id',
            'category.id',
            'catalog_limitation.markup',
            'category.markup as default_markup',
        ]);
        $query->andWhere(['category.delete' => 0]);
        $query->orderBy('category.sort ASC');

        $logFile = "/var/www/salvi/www_api/log.txt";
        file_put_contents($logFile, print_r($query->createCommand()->getRawSql(), true) . PHP_EOL);

        $this->records = $query->asArray()->all();
        foreach ($this->records as $k => $record)
        {
            if ( !empty($record['parent_uuid']) && is_null($record['parent_id']))
            {
                unset($this->records[$k]);
            }
        }
        $this->tree = $this->buildTree($this->records);
        $selected = CategoryExclude::find()->where(['company_id' => $id])->asArray()->all();
        if ($selected)
        {
            $this->selection = $selected;
        }

        return true;
    }

    public function initTreeNew ($location_id = 0, $order_id = 0,$withCache = false)
    {
        $user = Yii::$app->user->getIdentity();

        $specifications = Specification::getListByParams($location_id,$user->company_id,$order_id);

        $categories = [];

        if ($specifications)
        {
            $specIds = ArrayHelper::getColumn($specifications, 'id');

            $categories = ProductCategory::find()
                ->select('category_id')
                ->distinct()
                ->innerJoin(
                    'product',
                    'product.id=product_category.product_id'
                )
                ->innerJoin(
                    'specification_product',
                    'specification_product.product_id=product.id'
                )
                ->where([
                    'specification_product.specification_id' => $specIds,
                    'product.deleted' => 0
                ])
                ->column();

            if (empty($categories))
            {
                $this->tree = [];

                return true;
            }
        }



        $allCategoriesQuery = Category::find()
            ->where([
                'category.delete' => 0
            ]);

        if (empty($specifications))
        {
            $allCategoriesQuery->andWhere([
                'category.additional_category' => 0
            ]);
        }
        else if (empty($categories))
        {
            $allCategoriesQuery->andWhere([
                'id' => 0
            ]);
        }
        else
        {
            if(!Yii::$app->user->can('orderShowBtn') && !Yii::$app->user->can('catalogShowBtn') && Yii::$app->user->can('viewCatalog'))
            {
                $allCategoriesQuery->andWhere([
                    'in',
                    'id',
                    $categories
                ]);

            }
            else
            {
                $allCategoriesQuery->andWhere([
                    'OR',
                    [
                        'in',
                        'id',
                        $categories
                    ],
                    [
                        'category.additional_category' => 0
                    ]
                ]);
            }
        }


        $allCategoriesQuery = $allCategoriesQuery
            ->orderBy([
                'category.sort' => SORT_ASC
            ])
            ->asArray();

        $hashSql = md5($allCategoriesQuery->createCommand()->getRawSql());

        $tree = Yii::$app->cache->get('CategoryTreeAllCategories_'.$hashSql);

        $file = Yii::getAlias('@runtime/logs/updateCategories.log');

        $withoutCache = false;

        try {
            if (file_exists($file)) {
                $fp = @fopen($file, 'r');

                $timeTreeImportStart = ($fp && @filesize($file) > 0) ? fread($fp, filesize($file)) : 0;

                if ($fp) {
                    fclose($fp);
                }

                if (time() - $timeTreeImportStart < 420) {
                    $withoutCache = true;
                }

                if (!$withoutCache) {
                    if (file_exists($file)) {
                        @unlink($file);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Игнорируем любые сбои файловых операций, чтобы не прерывать отдачу категорий
        }

        if($withCache)
        {
            if (!$tree || $withoutCache)
            {
                $allCategories = $allCategoriesQuery->all();

                $tree = $allCategories ? $this->buildTree($allCategories) : [];

                Yii::$app->cache->set('CategoryTreeAllCategories_'.$hashSql, $tree, 7200,new TagDependency(['tags'=>['CategoryTreeAllCategories']]));
            }
        }
        else
        {
            $allCategories = $allCategoriesQuery->all();

            $tree = $allCategories ? $this->buildTree($allCategories) : [];
        }

        $this->tree = $tree;

        return true;
    }

    public function buildTree (array $elements, $parentId = 0)
    {
        $branch = [];

        foreach ($elements as $element)
        {
            if (isset($element['img_path']))
            {
                $element['img_path'] = Yii::$app->urlManager->createAbsoluteUrl(['/category/' . $element['img_path']]);
            }

            if ($element['parent_id'] == $parentId)
            {
                $children = $this->buildTree($elements, $element['id']);
                if ($children)
                {
                    $element['children'] = $children;
                }
                $branch[] = $element;
            }
        }

        return $branch;
    }

}
