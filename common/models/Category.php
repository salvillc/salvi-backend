<?php


namespace common\models;

use Throwable;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\caching\TagDependency;

/**
 * This is the model class for table "category".
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int $is_view
 * @property string $parent_uuid
 * @property int $parent_id
 * @property ActiveQuery|CatalogLimitation $limitation
 * @property int $has_child
 * @property int $markup
 * @property int $type
 * @property int $delete
 * @property int $sort
 * @property string $color
 * @property string $img_path
 * @property string $full_path
 * @property int $level
 */
class Category extends ActiveRecord
{
    const TYPE_PRODUCT = 0;

    const TYPE_SERVICE = 1;

    const TYPE_WORK = 2;

    /**
     * {@inheritdoc}
     */
    public static function tableName ()
    {
        return 'category';
    }

    /**
     * {@inheritdoc}
     */
    public function rules ()
    {
        return [
            [['is_view', 'has_child', 'type', 'delete'], 'integer'],
            [['uuid', 'name', 'parent_uuid'], 'string', 'max' => 255],
            [['parent_id', 'markup', 'img_path'], 'safe']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels ()
    {
        return [
            'id' => 'ID',
            'uuid' => 'Uuid',
            'name' => 'Name',
            'is_view' => 'Is View',
            'parent_uuid' => 'Parent Uuid',
            'parent_id' => 'Parent ID',
            'img_path' => 'img Path',
            'full_path' => 'Category Path',
        ];
    }

    /**
     * @return ActiveQuery | CatalogLimitation
     * @throws Throwable
     */
    public function getLimitation ()
    {
        /** @var  $user User */
        $user = Yii::$app->user->getIdentity();

        return $this->hasOne(CatalogLimitation::class, ['category_uuid' => 'uuid'])
            ->andWhere(['catalog_limitation.company_id' => $user->company_id]);
    }

    public static function buildTree (array $elements, $parentId = 0)
    {
        $branch = [];

        foreach ($elements as $element)
        {
            if ($element['parent_id'] == $parentId)
            {
                $children = self::buildTree($elements, $element['id']);
                if ($children)
                {
                    $element['children'] = $children;
                }
                $branch[] = $element;
            }
        }

        return $branch;
    }

    /**
     * @return array
     */
    public static function types ()
    {
        return [
            self::TYPE_PRODUCT => [
                'id' => self::TYPE_PRODUCT,
                'name' => 'Товар'
            ],
            self::TYPE_SERVICE => [
                'id' => self::TYPE_SERVICE,
                'name' => 'Услуга'
            ],
            self::TYPE_WORK => [
                'id' => self::TYPE_WORK,
                'name' => 'Работа'
            ]
        ];
    }


    public static function findCategory (array $categories, ?string $code, array &$breadCrumbs, ?array &$currentCategory, array &$categoryListNext): bool
    {
        if (empty($code)) {
            return false;
        }

        foreach ($categories as $category)
        {
            $breadCrumbs[] = [
                'name' => $category['name'],
                'url' => '/catalog/' . $category['code']
            ];

            if ($category['code'] === $code)
            {
                $currentCategory = $category;
                $categoryListNext = $category['children'] ?? [];

                return true;
            }

            if ( !empty($category['children']))
            {
                if (self::findCategory(
                    $category['children'],
                    $code,
                    $breadCrumbs,
                    $currentCategory,
                    $categoryListNext
                ))
                {
                    return true;
                }
            }

            array_pop($breadCrumbs);
        }

        return false;
    }

    public static function flattenCategories(array $categories, array &$result = []): array
    {
        foreach ($categories as $category)
        {
            $children = $category['children'] ?? [];

            unset($category['children']);

            $result[$category['uuid']] = $category;

            if ($children)
            {
                self::flattenCategories($children, $result);
            }
        }

        return $result;
    }

    /**
     * @param bool $runValidation
     * @param null $attributeNames
     * @return bool
     */
    public function save($runValidation = true, $attributeNames = null)
    {
        $isNeedClearCache = false;

        if($this->img_path != $this->getOldAttribute('img_path'))
        {
            $isNeedClearCache = true;
        }

        $result = parent::save($runValidation, $attributeNames);

        if($isNeedClearCache)
        {
            TagDependency::invalidate(
                Yii::$app->cache,
                'CategoryTreeAllCategories'
            );
        }

        return $result;
    }

}
