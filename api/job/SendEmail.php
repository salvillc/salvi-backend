<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 19.09.2019
 * Time: 11:38
 */

namespace api\job;

use api\helpers\UserEmailHelper;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * Class SendEmail
 * @package api\job
 */
class SendEmail extends BaseObject implements JobInterface
{
    /** @var $view string */
    public $view;
    /** @var $data array */
    public $data = [];
    /** @var $subject string */
    public $subject;
    /** @var string|null */
    public $to;
    /** @var string|null */
    public $htmlBody;
    /** @var bool|string $file */
    public $file = false;
    /** @var bool  */
    public $firstTime = true;

    public function execute($queue)
    {
        if (!empty($this->htmlBody) && !empty($this->to)) {
            try {
                $from = !empty(\Yii::$app->params['senderEmail'])
                    ? \Yii::$app->params['senderEmail']
                    : [\Yii::$app->params['emailAccount'] => \Yii::$app->params['emailSenderName']];

                return \Yii::$app->mailer->compose()
                    ->setFrom($from)
                    ->setTo($this->to)
                    ->setSubject($this->subject)
                    ->setHtmlBody($this->htmlBody)
                    ->send();
            } catch (\Throwable $e) {
                \Yii::error('SendEmail queue error: ' . $e->getMessage(), 'mailer');
                return false;
            }
        }
        return UserEmailHelper::sendMsg($this->view, $this->data, $this->subject, $this->file, $this->firstTime);
    }
}
