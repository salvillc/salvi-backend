<?php

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 *
 *
 * @property int $id
 * @property int|null $landing_page_id
 * @property string $title
 * @property string $url
 * @property int $level 2 = 2-й рівень (розділ / заголовок колонки), 3 = 3-й рівень (пункт у розділі)
 * @property int $parent_id
 * @property string|null $parent_uuid
 * @property int $sort
 * @property int $highlight
 * @property int $target_blank
 * @property int $active
 * @property int|null $created_at
 * @property int|null $updated_at
 *
 * @property Category|null $parentCategory
 * @property LandingPage|null $landingPage
 */
class PromoMenuItem extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'promo_menu_items';
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['title', 'url', 'level', 'parent_id'], 'required', 'message' => 'Поле обов\'язкове для заповнення'],
            [['level'], 'in', 'range' => [2, 3], 'message' => 'Рівень може бути тільки 2 або 3'],
            [['landing_page_id', 'parent_id', 'sort', 'highlight', 'target_blank', 'active'], 'integer'],
            [['sort'], 'default', 'value' => 0],
            [['highlight'], 'default', 'value' => 0],
            [['target_blank'], 'default', 'value' => 1],
            [['active'], 'default', 'value' => 1],
            [['title', 'parent_uuid'], 'string', 'max' => 255],
            [['url'], 'string', 'max' => 500],
            [['title', 'url'], 'trim'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'landing_page_id' => 'Лендінг',
            'title' => 'Назва пункту меню',
            'url' => 'Посилання (URL)',
            'level' => 'Рівень меню',
            'parent_id' => 'Батьківська категорія',
            'parent_uuid' => 'UUID батьківської категорії',
            'sort' => 'Порядковий номер',
            'highlight' => 'Виділити червоним',
            'target_blank' => 'Відкривати у новій вкладці',
            'active' => 'Активний',
            'created_at' => 'Створено',
            'updated_at' => 'Оновлено',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getParentCategory()
    {
        return $this->hasOne(Category::class, ['id' => 'parent_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getLandingPage()
    {
        return $this->hasOne(LandingPage::class, ['id' => 'landing_page_id']);
    }

    /**
     * Форматирование для админ-панели
     * @return array
     */
    public function toAdminArray()
    {
        $parentName = '—';
        $parentPath = '—';

        if ($this->parentCategory) {
            $parentName = $this->parentCategory->name;
            if ($this->level === 3 && $this->parentCategory->parent_id) {
                $topParent = Category::findOne($this->parentCategory->parent_id);
                $parentPath = ($topParent ? $topParent->name . ' → ' : '') . $parentName;
            } else {
                $parentPath = $parentName;
            }
        }

        return [
            'id' => $this->id,
            'landing_page_id' => $this->landing_page_id,
            'title' => $this->title,
            'url' => $this->url,
            'level' => (int)$this->level,
            'parent_id' => (int)$this->parent_id,
            'parent_uuid' => $this->parent_uuid,
            'parent_name' => $parentName,
            'parent_path' => $parentPath,
            'sort' => (int)$this->sort,
            'highlight' => (bool)$this->highlight,
            'target_blank' => (bool)$this->target_blank,
            'active' => (bool)$this->active,
            'created_at' => $this->created_at ? date('Y-m-d H:i', $this->created_at) : null,
            'updated_at' => $this->updated_at ? date('Y-m-d H:i', $this->updated_at) : null,
        ];
    }

    /**
     * Внедрение активных промо-пунктов в дерево категорий
     * @param array $tree Массив категорий (дерево)
     */
    public static function injectIntoTree(array &$tree)
    {
        try {
            $items = self::find()
                ->where(['active' => 1])
                ->orderBy(['sort' => SORT_ASC, 'id' => SORT_ASC])
                ->all();
        } catch (\Throwable $e) {
            // Если таблица ещё не создана миграцией - безопасно выходим, не ломая каталог
            return;
        }

        if (empty($items) || empty($tree)) {
            return;
        }

        $level2Items = [];
        $level3Items = [];

        foreach ($items as $item) {
            if ($item->level == 2) {
                $level2Items[] = $item;
            } elseif ($item->level == 3) {
                $level3Items[] = $item;
            }
        }

        // 1. Внедрение элементов 2-го уровня (колонки / разделы внутри категорий 1-го уровня)
        if (!empty($level2Items)) {
            foreach ($level2Items as $item) {
                $customNode = self::createCustomNode($item);

                foreach ($tree as &$topCat) {
                    if (
                        (isset($topCat['id']) && $topCat['id'] == $item->parent_id)
                        || (!empty($item->parent_uuid) && !empty($topCat['uuid']) && $topCat['uuid'] === $item->parent_uuid)
                    ) {
                        if (!isset($topCat['children']) || !is_array($topCat['children'])) {
                            $topCat['children'] = [];
                        }
                        $topCat['children'][] = $customNode;
                        $topCat['has_child'] = 1;

                        self::sortChildren($topCat['children']);
                        break;
                    }
                }
                unset($topCat);
            }
        }

        // 2. Внедрение элементов 3-го уровня (пункты внутри разделов 2-го уровня)
        if (!empty($level3Items)) {
            foreach ($level3Items as $item) {
                $customNode = self::createCustomNode($item);

                foreach ($tree as &$topCat) {
                    if (!empty($topCat['children']) && is_array($topCat['children'])) {
                        foreach ($topCat['children'] as &$subCat) {
                            if (
                                (isset($subCat['id']) && $subCat['id'] == $item->parent_id)
                                || (!empty($item->parent_uuid) && !empty($subCat['uuid']) && $subCat['uuid'] === $item->parent_uuid)
                            ) {
                                if (!isset($subCat['children']) || !is_array($subCat['children'])) {
                                    $subCat['children'] = [];
                                }
                                $subCat['children'][] = $customNode;
                                $subCat['has_child'] = 1;

                                self::sortChildren($subCat['children']);
                                break 2;
                            }
                        }
                        unset($subCat);
                    }
                }
                unset($topCat);
            }
        }
    }

    /**
     * Стабильная сортировка дочерних элементов:
     * Кастомные промо-пункты идут ВПЕРЕДИ стандартных категорий без явной сортировки (sort === null)
     * @param array $children
     */
    protected static function sortChildren(array &$children)
    {
        $indexed = [];
        $i = 0;
        foreach ($children as $child) {
            $indexed[] = ['idx' => $i++, 'data' => $child];
        }

        usort($indexed, function ($a, $b) {
            $getWeight = function ($el) {
                $isCustom = !empty($el['is_custom']);
                $s = $el['sort'] ?? null;
                if ($isCustom) {
                    return (is_numeric($s) && (int)$s > 0) ? (int)$s : 0;
                }
                if ($s === null || $s === '') {
                    return 100000; // null-сортировка у стандартных категорий идет после промо
                }
                return (int)$s;
            };

            $wa = $getWeight($a['data']);
            $wb = $getWeight($b['data']);

            if ($wa !== $wb) {
                return $wa <=> $wb;
            }

            return $a['idx'] <=> $b['idx'];
        });

        $children = array_column($indexed, 'data');
    }

    /**
     * Создание псевдо-узла категории для дерева
     * @param PromoMenuItem $item
     * @return array
     */
    protected static function createCustomNode(PromoMenuItem $item)
    {
        return [
            'id' => 'promo_' . $item->id,
            'uuid' => 'promo_' . $item->id,
            'name' => $item->title,
            'code' => 'promo-' . $item->id,
            'parent_id' => $item->parent_id,
            'parent_uuid' => $item->parent_uuid,
            'has_child' => 0,
            'children' => [],
            'url' => $item->url,
            'target_blank' => (bool)$item->target_blank,
            'highlight' => (bool)$item->highlight,
            'is_custom' => true,
            'sort' => (int)$item->sort,
        ];
    }
}
