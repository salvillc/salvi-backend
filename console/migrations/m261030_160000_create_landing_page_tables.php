<?php

use yii\db\Migration;

/**
 * Class m261030_160000_create_landing_page_tables
 */
class m261030_160000_create_landing_page_tables extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        // 1. Таблица лендингов / промо-страниц
        $this->createTable('{{%landing_pages}}', [
            'id' => $this->primaryKey(),
            'slug' => $this->string(100)->notNull()->unique(),
            'title' => $this->string(255)->notNull(),
            'file_test' => $this->string(255)->defaultValue(null),
            'file_guest' => $this->string(255)->defaultValue(null),
            'file_auth' => $this->string(255)->defaultValue(null),
            'manager_email' => $this->string(255)->defaultValue(null),
            'active' => $this->integer()->defaultValue(1),
            'created_at' => $this->integer(11),
            'updated_at' => $this->integer(11),
        ], $tableOptions);

        $this->createIndex('idx_landing_pages_slug', '{{%landing_pages}}', 'slug');

        // 2. Таблица заявок с промо-страниц
        $this->createTable('{{%landing_orders}}', [
            'id' => $this->primaryKey(),
            'landing_page_id' => $this->integer()->defaultValue(null),
            'order_number' => $this->string(64)->notNull(),
            'name' => $this->string(255)->defaultValue(null),
            'phone' => $this->string(64)->defaultValue(null),
            'email' => $this->string(255)->defaultValue(null),
            'company' => $this->string(255)->defaultValue(null),
            'channel' => $this->string(64)->defaultValue(null),
            'comment' => $this->text()->defaultValue(null),
            'budget' => $this->string(64)->defaultValue(null),
            'qty' => $this->integer()->defaultValue(null),
            'total_estimate' => $this->decimal(12, 2)->defaultValue(null),
            'deadline' => $this->string(64)->defaultValue(null),
            'wishes' => $this->text()->defaultValue(null),
            'interests' => $this->text()->defaultValue(null),
            'packaging' => $this->string(255)->defaultValue(null),
            'filler' => $this->string(255)->defaultValue(null),
            'ribbon' => $this->string(255)->defaultValue(null),
            'decor' => $this->text()->defaultValue(null),
            'logo_file' => $this->string(255)->defaultValue(null),
            'logo_placement' => $this->string(255)->defaultValue(null),
            'raw_data' => 'MEDIUMTEXT',
            'user_id' => $this->integer()->defaultValue(null),
            'ip' => $this->string(45)->defaultValue(null),
            'email_status' => $this->string(32)->defaultValue('pending'),
            'email_error' => $this->text()->defaultValue(null),
            'created_at' => $this->integer(11),
            'updated_at' => $this->integer(11),
        ], $tableOptions);

        $this->createIndex('idx_landing_orders_order_number', '{{%landing_orders}}', 'order_number');
        $this->createIndex('idx_landing_orders_page_id', '{{%landing_orders}}', 'landing_page_id');
        $this->createIndex('idx_landing_orders_created_at', '{{%landing_orders}}', 'created_at');

        // Добавляем запись по умолчанию для новогодних наборов
        $this->insert('{{%landing_pages}}', [
            'slug' => 'novorichni-nabory',
            'title' => 'Новорічні подарункові набори для бізнесу',
            'file_test' => 'test.html',
            'file_guest' => 'guest.html',
            'manager_email' => 'info@salvigroup.com.ua',
            'active' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%landing_orders}}');
        $this->dropTable('{{%landing_pages}}');
    }
}
