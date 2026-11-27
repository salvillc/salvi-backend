<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 13.11.2019
 * Time: 11:03
 */

namespace common\helpers;

/**
 * Class CropImageHelper
 * @package common\helpers
 */
class CropImageHelper
{
    const IMAGE_HANDLERS = [
        IMAGETYPE_JPEG => [
            'load' => 'imagecreatefromjpeg',
            'save' => 'imagejpeg',
            'quality' => 100
        ],
        IMAGETYPE_PNG => [
            'load' => 'imagecreatefrompng',
            'save' => 'imagepng',
            'quality' => 0
        ]
    ];

    /**
     * @param $src
     * @param $dest
     * @param $targetWidth
     * @param null $targetHeight
     * @return mixed|null
     */
    public static function createThumbnail($src, $dest, $targetWidth, $targetHeight = null)
    {
        $type = exif_imagetype($src);
        if (!$type || !isset(self::IMAGE_HANDLERS[$type])) {
            return null;
        }

        try {
            $image = call_user_func(self::IMAGE_HANDLERS[$type]['load'], $src);
        } catch (\Throwable $e) {
            $size = file_exists($src) ? @filesize($src) : false;
            $contextMsg = sprintf(
                'CropImageHelper::createThumbnail failed: src=%s type=%s size=%s error=%s',
                $src,
                (string)$type,
                $size === false ? 'unknown' : (string)$size,
                $e->getMessage()
            );
            \Yii::warning($contextMsg, 'image');
            throw new \RuntimeException($contextMsg, (int)$e->getCode(), $e);
        }

        if (!$image) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($targetHeight == null) {
            $ratio = $width / $height;

            if ($width > $height) {
                $targetHeight = floor($targetWidth / $ratio);
            } else {
                $targetHeight = $targetWidth;
                $targetWidth = floor($targetWidth * $ratio);
            }
        }

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($type == IMAGETYPE_GIF || $type == IMAGETYPE_PNG) {
            imagecolortransparent(
                $thumbnail,
                imagecolorallocate($thumbnail, 0, 0, 0)
            );

            if ($type == IMAGETYPE_PNG) {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
            }
        }

        imagecopyresampled(
            $thumbnail,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        return call_user_func(
            self::IMAGE_HANDLERS[$type]['save'],
            $thumbnail,
            $dest,
            self::IMAGE_HANDLERS[$type]['quality']
        );
    }
}
