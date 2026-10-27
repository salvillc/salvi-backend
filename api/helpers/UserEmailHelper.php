<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 27.05.2019
 * Time: 14:28
 */

namespace api\helpers;

use api\helpers\UniSender\UnisenderSended;
use api\job\SendEmail;
use api\models\forms\UserForm;
use common\models\EmailLog;
use common\models\User;
use Yii;
use yii\helpers\ArrayHelper;

/**
 * Class UserEmailHelper
 * @package api\helpers
 */
class UserEmailHelper
{
//    public static function sendMsg(string $view, array $data, string $subject, $file = false, bool $oneTime = true)
//    {
//        $html = UnisenderSended::renderView(
//            $view,
//            $data
//        );
//        $result = UnisenderSended::createAndSend(
//            ArrayHelper::getValue($data, 'user.email'),
//            $subject,
//            $html,
//            $file
//        );
//        if (!$result && $oneTime) {
//            Yii::$app->queue->delay(60*5)->push(new SendEmail([
//                'view' => $view,
//                'data' => $data,
//                'subject' => $subject,
//                'firstTime' => false
//            ]));
//        }
//        return $result;
//    }

    public static function sendMsg(string $view, array $data, string $subject, $file = false, bool $oneTime = true)
    {
        try {
            $mail = Yii::$app
                ->mailer
                ->compose(
                    ['html' => $view],
                    $data
                )
                ->setFrom([Yii::$app->params['emailAccount'] => Yii::$app->params['emailSenderName']])
                ->setTo(ArrayHelper::getValue($data, 'user.email'))
                ->setSubject($subject);
	
            if($file)
            {
                $mail->attachContent($file, [
                    'fileName' => "mail_file_".date('d_m_Y_H_i_s').".txt",
                    'contentType' => 'text/plain'
                ]);
            }
	    
            $log = $mail->toString();
            $response = $mail->send();
            $model = new EmailLog([
                'result' => $response,
                'email' => ArrayHelper::getValue($data, 'user.email'),
                'body' => quoted_printable_decode($log)
            ]);
            return $model->save();
        } catch (\Throwable $e) {
            Yii::error('Mailer send error: ' . $e->getMessage(), 'mailer');
            return false;
        }
    }

    /** Подтверждение заявки на регистрацию
     * @param User $user
     * @param string $password
     * @return bool
     */
    public static function registerUser(User $user, string $password)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'emailVerify',
            'data' => [
                'user' => $user,
                'password' => $password
            ],
            'subject' => 'Реєстрація на  ' . Yii::$app->params['emailSenderName']
        ]));
    }

    /** Администратор отклонил заявку регистрации
     * @param User $user
     * @return bool
     */
    public static function refuseUser(User $user, string $reason)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'refuseUser',
            'data' => ['user' => $user, 'reason' => $reason],
            'subject' => Yii::$app->params['emailSenderName']
        ]));
    }

    /** Ссылка для восстановления пароля
     * @param User $user
     * @return bool
     */
    public static function passwordResetRequest(User $user)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'passwordResetToken',
            'data' => ['user' => $user],
            'subject' => 'Відновлення пароля'
        ]));
    }

    /**
     * @param User $user
     * @param $password
     * @return mixed
     */
    public static function createUser(User $user, $password)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'newUser',
            'data' => [
                'user' => $user,
                'password' => $password
            ],
            'subject' => 'Вітаємо в системі ' . Yii::$app->params['emailSenderName']
        ]));
    }

    public static function contractCreated(array $data)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'createdContract',
            'data' => $data,
            'subject' => Yii::$app->params['emailSenderName']
        ]));
    }

    public static function coordinationNeedApprove(array $data)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'coordinationNeedApprove',
            'data' => $data,
            'subject' => Yii::$app->params['emailSenderName']
        ]));
    }

    public static function soonEndContractNew(array $data)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'soonEndContractNew',
            'data' => $data,
            'subject' => Yii::$app->params['emailSenderName']
        ]));
    }

    public static function endedContract(array $data)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'endedContract',
            'data' => $data,
            'subject' => Yii::$app->params['emailSenderName']
        ]));
    }

    /**
     * @param array $data
     * @param bool $file
     * @return mixed
     */
    public static function defaultMessage(array $data, $file = false)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => $data['view'],
            'data' => $data,
            'subject' => Yii::$app->params['emailSenderName'],
            'file' => $file
        ]));
    }

    public static function activatedUser(User $model)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'activatedUser',
            'data' => ['user' => $model],
            'subject' => Yii::$app->params['emailSenderName']
        ]));
    }

    public static function deactivatedUser(User $model)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'deactivatedUser',
            'data' => ['user' => $model],
            'subject' => Yii::$app->params['emailSenderName']
        ]));
    }

    public static function createdUser(User $model, string $password)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'createdUser',
            'data' => ['user' => $model, 'password' => $password],
            'subject' => Yii::$app->params['emailSenderName']
        ]));
    }

    /**
     * @param $email
     * @param $password
     * @return mixed
     */
    public static function emailChangedUser($email, $password)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'emailChangedUser',
            'data' => [
                'user' => (object)[
                    'email' => $email
                ],
                'newEmail' => $email,
                'password' => $password
            ],
            'subject' => 'Ви змінили e-mail адресу ' . $email
        ]));
    }


    /**
     * @param User $user
     * @param $password
     * @return mixed
     */
    public static function passwordChanged(User $user, $password)
    {
        return Yii::$app->queue->push(new SendEmail([
            'view' => 'passwordChanged',
            'data' => [
                'user' => $user,
                'password' => $password
            ],
            'subject' => 'Новий пароль ' . Yii::$app->params['emailSenderName']
        ]));
    }
}
