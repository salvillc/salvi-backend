<?php

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\helpers\FileHelper;

/**
 * Class LandingPage
 * @package common\models
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $file_test
 * @property string|null $file_guest
 * @property string|null $file_auth
 * @property string|null $manager_email
 * @property int $active
 * @property int $created_at
 * @property int $updated_at
 *
 * @property LandingOrder[] $orders
 */
class LandingPage extends ActiveRecord
{
    const TYPE_TEST = 'test';
    const TYPE_GUEST = 'guest';
    const TYPE_AUTH = 'auth';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%landing_pages}}';
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
            [['slug', 'title'], 'required'],
            [['slug'], 'unique'],
            [['active', 'created_at', 'updated_at'], 'integer'],
            [['slug'], 'string', 'max' => 100],
            [['title', 'file_test', 'file_guest', 'file_auth', 'manager_email'], 'string', 'max' => 255],
            [['manager_email'], 'email'],
            [['active'], 'default', 'value' => 1],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'slug' => 'Символьний код (URL)',
            'title' => 'Назва промо-сторінки',
            'file_test' => 'Файл тестової версії',
            'file_guest' => 'Файл бойової версії (гості)',
            'file_auth' => 'Файл бойової версії (клієнти)',
            'manager_email' => 'Email менеджера для заявок',
            'active' => 'Активність',
            'created_at' => 'Дата створення',
            'updated_at' => 'Дата оновлення',
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getOrders()
    {
        return $this->hasMany(LandingOrder::class, ['landing_page_id' => 'id'])
            ->orderBy(['id' => SORT_DESC]);
    }

    /**
     * Директория хранения загруженных HTML файлов страницы
     * @return string
     */
    public function getStoragePath()
    {
        $path = Yii::getAlias('@common/uploads/landings/' . $this->slug);
        if (!is_dir($path)) {
            FileHelper::createDirectory($path, 0777);
        }
        return $path;
    }

    /**
     * Получить полный путь к файлу указанного типа
     * @param string $type 'test' | 'guest' | 'auth'
     * @return string|null
     */
    public function getAbsoluteFilePath($type)
    {
        $attribute = 'file_' . $type;
        if (!isset($this->$attribute) || empty($this->$attribute)) {
            return null;
        }

        $fullPath = $this->getStoragePath() . DIRECTORY_SEPARATOR . $this->$attribute;
        return file_exists($fullPath) ? $fullPath : null;
    }

    /**
     * Мета-информация о файле для админки
     * @param string $type
     * @return array
     */
    public function getFileInfo($type)
    {
        $attribute = 'file_' . $type;
        $fileName = $this->$attribute ?? null;
        $fullPath = $this->getAbsoluteFilePath($type);

        if (!$fullPath || !file_exists($fullPath)) {
            return [
                'exists' => false,
                'name' => null,
                'size' => 0,
                'size_formatted' => '0 KB',
                'updated_at' => null,
            ];
        }

        $size = filesize($fullPath);
        $mtime = filemtime($fullPath);

        return [
            'exists' => true,
            'name' => $fileName,
            'size' => $size,
            'size_formatted' => round($size / 1024, 1) . ' KB',
            'updated_at' => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
        ];
    }
}
