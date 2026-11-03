<?php

use yii\db\Migration;

/**
 * Class m261102_150000_create_promo_menu_items_table
 */
class m261102_150000_create_promo_menu_items_table extends Migration
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

        $this->createTable('{{%promo_menu_items}}', [
            'id' => $this->primaryKey(),
            'landing_page_id' => $this->integer()->defaultValue(null),
            'title' => $this->string(255)->notNull(),
            'url' => $this->string(500)->notNull(),
            'level' => $this->smallInteger()->notNull()->defaultValue(2), // 2 = 2-й уровень (колонка), 3 = 3-й уровень (пункт)
            'parent_id' => $this->integer()->notNull()->defaultValue(0), // id родительской категории в таблице category
            'parent_uuid' => $this->string(255)->defaultValue(null),
            'sort' => $this->integer()->defaultValue(0),
            'highlight' => $this->smallInteger()->defaultValue(0), // 1 = выделить красным
            'target_blank' => $this->smallInteger()->defaultValue(1), // 1 = открывать в новой вкладке
            'active' => $this->smallInteger()->defaultValue(1), // 1 = активен, 0 = скрыт
            'created_at' => $this->integer(11),
            'updated_at' => $this->integer(11),
        ], $tableOptions);

        $this->createIndex('idx_promo_menu_items_active', '{{%promo_menu_items}}', 'active');
        $this->createIndex('idx_promo_menu_items_level', '{{%promo_menu_items}}', 'level');
        $this->createIndex('idx_promo_menu_items_parent_id', '{{%promo_menu_items}}', 'parent_id');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%promo_menu_items}}');
    }
}
