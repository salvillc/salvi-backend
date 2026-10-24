<?php
/**
 * Created by PhpStorm.
 * User: tik
 * Date: 19.08.2019
 * Time: 18:07
 */

namespace api\models\forms;

use common\models\AuthAssignment;
use common\models\AuthItemChild;
use common\models\Chat;
use common\models\ChatGuestUser;
use common\models\ChatMessage;
use common\models\ChatMessageRead;
use common\models\ChatUser;
use common\models\User;
use Exception;
use Throwable;
use Yii;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\web\IdentityInterface;

/**
 * Class MessageForm
 * @package api\models\forms
 */
class MessageForm extends Model
{
    public $user_id;
    public $chat_id;
    public $name;
    public $email;
    public $phone;
    public $message;

    /** @var $chat Chat */
    private $chat;
    /** @var $user User | ChatGuestUser | bool */
    private $user;

    public function rules()
    {
        return [
            ['message', 'required'],
            [['name', 'email', 'phone'], 'required', 'on' => 'register_guest'],
            [['email'], 'email', 'on' => 'register_guest'],
            ['phone', 'match', 'pattern' => '/^\d{9}$/', 'message' => 'Поле має містити 9 цифр',
                'on' => 'register_guest'],
            [['user_id', 'chat_id'], 'safe']
        ];
    }

    public function sendMessage()
    {
        $this->getUser();
        $this->getChat();
        return [
            'result' => $this->makeMessage(),
            'chat_id' => $this->chat->id,
            'user_id' => $this->user->id
        ];
    }

    /**
     * @return array|bool|ChatGuestUser|ActiveRecord|IdentityInterface|null
     * @throws Throwable
     */
    private function getUser()
    {
        if (Yii::$app->user->isGuest) {
            if (empty($this->user_id)) {
                $model = new ChatGuestUser([
                    'name' => $this->name,
                    'phone' => $this->phone,
                    'email' => $this->email,
                    'ip_address' => Yii::$app->getRequest()->getUserIP()
                ]);
                $result = $model->save();
                if ($result) {
                    $this->user = $model;
                    return true;
                }
                $this->user = false;
                return true;
            } else {
                $this->user = ChatGuestUser::find()->where(['id' => $this->user_id])->one();
                return true;
            }
        } else {
            $this->user = Yii::$app->user->getIdentity();
            return true;
        }
    }

    /**
     * @return bool
     */
    private function getChat()
    {
        $model = Chat::find()
            ->where(['id' => $this->chat_id])
            ->andWhere(['status' => Chat::STATUS_OPEN])
            ->one();
        if (!$model) {
            $usersChats = ChatUser::find()->where(['user_id' => $this->user->id])->groupBy('chat_id')->all();
            if ($usersChats) {
                $model = Chat::find()->where(['status' => Chat::STATUS_OPEN])
                    ->andWhere(['in', 'id', ArrayHelper::getColumn($usersChats, 'chat_id')])
                    ->one();
            }
            if (!$model) {
                $model = new Chat([
                    'status' => Chat::STATUS_OPEN,
                    'is_guest' => Yii::$app->user->isGuest,
                    'user_id' => $this->user->id
                ]);
                $result = $model->save();
                if ($result) {
                    $roles = AuthItemChild::find()->where(['child' => 'receiveMessagesSupport'])->all();
                    if ($roles) {
                        $roles = ArrayHelper::getColumn($roles, 'parent');
                        $users = AuthAssignment::find()->where(['in', 'item_name', $roles])->all();
                        if ($users) {
                            $users = ArrayHelper::getColumn($users, 'user_id');
                            if ($this->user instanceof User) {
                                $users = User::getManagersByCompany($this->user->company_id, $users);
                            }
                            $data = [
                                [
                                    'chat_id' => $model->id,
                                    'user_id' => $this->user->id,
                                    'is_guest' => ($this->user instanceof ChatGuestUser) ? 1 : 0
                                ]
                            ];
                            foreach ($users as $user) {
                                $data[] = [
                                    'chat_id' => $model->id,
                                    'user_id' => $user,
                                    'is_guest' => 0
                                ];
                            }
                            if (count($data) > 1) {
                                try {
                                    Yii::$app->db->createCommand()->batchInsert(
                                        ChatUser::tableName(),
                                        ['chat_id', 'user_id', 'is_guest'],
                                        $data
                                    )->execute();
                                } catch (Exception $e) {
                                    Yii::error($e);
                                }
                            }
                        }
                    }
                }
            }
        }
        $this->chat = $model;
        return true;
    }

    private function makeMessage()
    {
        $model = new ChatMessage([
            'chat_id' => $this->chat->id,
            'message' => $this->message,
            'user_id' => $this->user->id,
            'from_guest' => ($this->user instanceof ChatGuestUser) ? 1 : 0
        ]);
        $result = $model->save();
        if ($result) {
            $users = ChatUser::find()
                ->where(['chat_id' => $this->chat->id])
//                ->andWhere(['is_guest' => 0])
                ->all();
            if ($users) {
                $users = ArrayHelper::getColumn($users, 'user_id');
                $data = [];
                foreach ($users as $user) {
                    $data[] = [
                        'message_id' => $model->id,
                        'chat_id' => $this->chat->id,
                        'user_id' => $user,
                        'is_read' => ($user == $this->user->id) ? 1 : 0,
                        'created_at' => date('U'),
                    ];
                }
                if (!empty($data)) {
                    try {
                        Yii::$app->db->createCommand()->batchInsert(
                            ChatMessageRead::tableName(),
                            ['message_id', 'chat_id', 'user_id', 'is_read', 'created_at'],
                            $data
                        )->execute();
                    } catch (Exception $e) {
                        Yii::error($e);
                    }
                }
            }
        }
        return $result;
    }

    public function attributeLabels()
    {
        return [
            'name' => 'Ім\'я',
            'email' => 'Email',
            'phone' => 'Мобільний телефон',
            'message' => 'Повідомлення'
        ];
    }
}
