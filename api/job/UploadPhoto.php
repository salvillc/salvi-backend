<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 12.06.2020
 * Time: 16:51
 */

namespace api\job;

use common\helpers\ImageConvertHelper;
use common\models\Product;
use common\models\ProductPhoto;
use Ramsey\Uuid\Uuid;
use Yii;
use yii\base\BaseObject;
use yii\helpers\FileHelper;
use yii\queue\JobInterface;
use yii\queue\Queue;
use yii\caching\TagDependency;

/**
 * Class UploadPhoto
 * @package api\job
 */
class UploadPhoto extends BaseObject implements JobInterface
{
    /** @var Product */
    public $product;
    public $images;

    /**
     * @param Queue $queue
     * @return mixed|void
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function execute($queue)
    {
        $this->deleteImages();
        if (is_array($this->images)) {
            foreach ($this->images as $image) {
                $this->uploadImage($image, $this->product);
            }
        } else {
            $this->uploadImage($this->images, $this->product);
        }
    }

    /**
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    private function deleteImages()
    {
        $oldImages = ProductPhoto::find()
            ->where(['product_uuid' => $this->product->uuid])
            ->all();
        if ($oldImages) {
            foreach ($oldImages as $img) {
                $file = Yii::getAlias('@api') . '/web/product/' . $img->name;
                try {
                    unlink($file);
                } catch (\Exception $e) {
                    Yii::error($e->getMessage());
                }
                $img->delete();
            }
        }
    }

    /**
     * @param string $url
     * @param Product $product
     * @return bool
     */
    private function uploadImage(string $url, Product $product)
    {
        $chunks = explode('/', $url);
        $chunks = array_reverse($chunks);
        if (!isset($chunks[0])) {
            return false;
        }
        try {
            $mode = FTP_BINARY;
            $asynchronous = false;
            $name = Uuid::uuid4()->toString() . '.' . pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            $path = Yii::getAlias('@api') . '/web/product/';
            FileHelper::createDirectory($path);
            $fileFullPath = $path . $name;
            $remotePath = '/upload/pictures/' . $chunks[0];

            Yii::$app->ftp->get($remotePath, $fileFullPath, $mode, $asynchronous);

            $fileSize = file_exists($fileFullPath) ? (int)@filesize($fileFullPath) : 0;
            $found = file_exists($fileFullPath) && $fileSize > 0;
            $this->logPhotoStatus($product, $remotePath, $fileFullPath, $found, null, $fileSize);

            if (!$found) {
                return false;
            }

            Yii::$app->ftp->delete($remotePath);

            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'png') {
                $jpgName = pathinfo($name, PATHINFO_FILENAME) . '.jpg';
                $jpgFullPath = $path . $jpgName;
                if (ImageConvertHelper::convertPngToJpg($fileFullPath, $jpgFullPath)) {
                    unlink($fileFullPath);
                    $name = $jpgName;
                    $fileFullPath = $jpgFullPath;
                }
            }

            $modelImage = new ProductPhoto([
                'name'         => $name,
                'product_uuid' => $product->uuid,
                'product_id'   => $product->id
            ]);
            $modelImage->save();

            $listCacheToClear = [
                'GetSeasonGoods',
                'CategoryPopular',
                'Get4BlockCategories',
                'GetCategoryPage',
                'GetProductByCategoryOnCategoryPage',
            ];

            foreach ($listCacheToClear as $item)
            {
                TagDependency::invalidate(
                    Yii::$app->cache,
                    $item
                );
            }

        } catch (\Throwable $e) {
            $fileSize = (isset($fileFullPath) && file_exists($fileFullPath)) ? (int)@filesize($fileFullPath) : 0;
            $errorMessage = sprintf(
                'UploadPhoto error: product_id=%s uuid=%s url=%s remote=%s local=%s size=%d error=%s (%s:%d)',
                $product->id,
                $product->uuid,
                $url,
                $remotePath ?? '',
                $fileFullPath ?? '',
                $fileSize,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
            \Yii::error($errorMessage, 'upload_photo');
            $this->logPhotoStatus($product, $remotePath ?? $url, $fileFullPath ?? '', false, $e->getMessage(), $fileSize);
        }
    }

    /**
     * @param Product $product
     * @param string $remotePath
     * @param string $localPath
     * @param bool $found
     * @param string|null $error
     * @param int|null $fileSize
     */
    private function logPhotoStatus(Product $product, string $remotePath, string $localPath, bool $found, string $error = null, int $fileSize = null)
    {
        $dir = Yii::getAlias('@api') . '/runtime/logs/products_photo/';
        FileHelper::createDirectory($dir);
        $file = $dir . $product->id . '.log';

        $line = sprintf(
            "[%s] product_id=%s uuid=%s remote=%s local=%s size=%s status=%s%s\n",
            date('Y-m-d H:i:s'),
            $product->id,
            $product->uuid,
            $remotePath,
            $localPath,
            $fileSize !== null ? (string)$fileSize : 'unknown',
            $found ? 'FOUND' : 'NOT_FOUND',
            $error !== null ? " error={$error}" : ''
        );

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
