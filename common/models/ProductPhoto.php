<?php

namespace common\models;

use common\helpers\CropImageHelper;
use Yii;

/**
 * This is the model class for table "product_photo".
 *
 * @property int $id
 * @property string $name
 * @property string $product_uuid
 * @property int $product_id
 */
class ProductPhoto extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'product_photo';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['product_id'], 'integer'],
            [['name', 'product_uuid'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id'           => 'ID',
            'name'         => 'Name',
            'product_uuid' => 'Product Uuid',
            'product_id'   => 'Product ID',
        ];
    }

    public function delete()
    {
//        try {
//            $file = Yii::getAlias('@api') . '/web/product/' . $this->name;
//            echo unlink($file);
//            echo "\n";
//        } catch (\Exception $e) {
//            print_r($e->getMessage());
//            echo "\n";
//        }
        return parent::delete();
    }

    /**
     * @param $img
     * @param int $with
     * @param int $maxSize
     * @return bool
     */
    public static function cropPhoto($img, $with = 260, $maxSize = 100)
    {
        try {
            $size = filesize($img) / 1024;
        } catch (\Exception $e) {
            echo $e->getMessage();
            echo "\n";
            $size = 0;
        }
        $format = substr($img, -3);
        if ($format == 'tif') {
            return false;
        }
        if (strpos($img, '46b07173-5344-4cca-9f5c-660884353a6c.jpg')) {
            echo $size;
            echo "\n";
        }
        if ($size > $maxSize) {
            try {
                CropImageHelper::createThumbnail($img, $img, $with);
                echo $img;
                echo "\n";
            } catch (\Throwable $e) {
                Yii::error(sprintf(
                    'ProductPhoto::cropPhoto error: file=%s error=%s (%s:%d)',
                    $img,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ), 'crop_images');
                echo $img;
                echo "\n";
                echo $e->getMessage() . " from catch";
                echo "\n";
            }
        }
    }
}
