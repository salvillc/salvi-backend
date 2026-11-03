<?php

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Class LandingOrder
 * @package common\models
 *
 * @property int $id
 * @property int|null $landing_page_id
 * @property string $order_number
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $company
 * @property string|null $channel
 * @property string|null $comment
 * @property string|null $budget
 * @property int|null $qty
 * @property float|null $total_estimate
 * @property string|null $deadline
 * @property string|null $wishes
 * @property string|null $interests
 * @property string|null $packaging
 * @property string|null $filler
 * @property string|null $ribbon
 * @property string|null $decor
 * @property string|null $logo_file
 * @property string|null $logo_placement
 * @property string|null $raw_data
 * @property int|null $user_id
 * @property string|null $ip
 * @property string $email_status
 * @property string|null $email_error
 * @property int $created_at
 * @property int $updated_at
 *
 * @property LandingPage|null $landingPage
 * @property User|null $user
 */
class LandingOrder extends ActiveRecord
{
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%landing_orders}}';
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
            [['order_number'], 'required'],
            [['landing_page_id', 'qty', 'user_id', 'created_at', 'updated_at'], 'integer'],
            [['total_estimate'], 'number'],
            [['comment', 'wishes', 'interests', 'decor', 'raw_data', 'email_error'], 'string'],
            [['order_number', 'phone', 'channel', 'budget', 'deadline', 'email_status'], 'string', 'max' => 64],
            [['name', 'email', 'company', 'packaging', 'filler', 'ribbon', 'logo_file', 'logo_placement'], 'string', 'max' => 255],
            [['ip'], 'string', 'max' => 45],
            [['email_status'], 'default', 'value' => self::STATUS_PENDING],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'order_number' => '№ Заявки',
            'name' => 'Ім\'я',
            'phone' => 'Телефон',
            'email' => 'Email',
            'company' => 'Компанія',
            'channel' => 'Канал зв\'язку',
            'budget' => 'Бюджет на набір',
            'qty' => 'Кількість (шт)',
            'total_estimate' => 'Орієнтовна сума (грн)',
            'deadline' => 'Бажаний термін',
            'packaging' => 'Упаковка',
            'filler' => 'Наповнювач',
            'ribbon' => 'Стрічка',
            'decor' => 'Декор',
            'logo_file' => 'Файл логотипу',
            'email_status' => 'Статус відправки email',
            'created_at' => 'Дата надходження',
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getLandingPage()
    {
        return $this->hasOne(LandingPage::class, ['id' => 'landing_page_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * Полный публичный URL к прикрепленному логотипу
     * @return string|null
     */
    public function getLogoUrl()
    {
        if (empty($this->logo_file)) {
            return null;
        }

        // Если файл начинается с http - уже ссылка
        if (strpos($this->logo_file, 'http') === 0) {
            return $this->logo_file;
        }

        return Yii::$app->urlManager->createAbsoluteUrl(['/landings/logos/' . ltrim($this->logo_file, '/')]);
    }

    /**
     * Преобразование в массив для выдачи в API админки
     * @return array
     */
    public function toAdminArray()
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'created_at' => $this->created_at ? date('Y-m-d H:i', $this->created_at) : null,
            'contact' => [
                'name' => $this->name,
                'phone' => $this->phone,
                'email' => $this->email,
                'company' => $this->company,
                'channel' => $this->channel,
                'comment' => $this->comment,
            ],
            'quiz' => [
                'budget' => $this->budget,
                'qty' => $this->qty,
                'total_estimate' => $this->total_estimate,
                'deadline' => $this->deadline,
                'interests' => $this->interests,
                'wishes' => $this->wishes,
            ],
            'packaging' => [
                'name' => $this->packaging,
                'filler' => $this->filler,
                'ribbon' => $this->ribbon,
                'decor' => $this->decor,
            ],
            'logo' => [
                'placement' => $this->logo_placement,
                'file_name' => $this->logo_file ? basename($this->logo_file) : null,
                'url' => $this->getLogoUrl(),
            ],
            'email_status' => $this->email_status,
            'email_error' => $this->email_error,
            'user_id' => $this->user_id,
        ];
    }
}
