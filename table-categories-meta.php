<?php
/**
 * 基于WordPress Meta系统的餐桌分类管理
 * Table Category Management using WordPress Meta System
 *
 * 这个文件实现了使用WordPress内置Meta系统来管理餐桌分类和桌位信息
 * 替代原有的自定义MySQL表方案，提供更好的兼容性和维护性
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta系统架构设计
 *
 * 1. 餐桌分类 (Table Categories):
 *    - 使用 wp_options 表存储：ruiyi_table_categories
 *    - 数据结构：array of category objects
 *
 * 2. 餐桌信息 (Table Mappings):
 *    - 使用 wp_options 表存储：ruiyi_table_mappings
 *    - 数据结构：array of table objects with category relationships
 *
 * 3. 缓存策略：
 *    - 利用WordPress的object cache
 *    - 提供手动清除缓存的选项
 */

class RUIYI_Table_Meta_Manager {

    private static $instance = null;
    private $repairing_mappings = false;

    // Option keys for meta storage
    const CATEGORIES_OPTION = 'ruiyi_table_categories_meta';
    const MAPPINGS_OPTION = 'ruiyi_table_mappings_meta';
    const VERSION_OPTION = 'ruiyi_table_meta_version';

    // Current version for migration purposes
    const META_VERSION = '1.0.0';

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize meta system
        add_action('init', array($this, 'init_meta_system'));
    }

    /**
     * 初始化Meta系统
     */
    public function init_meta_system() {
        // Check if migration from old system is needed
        $meta_version = get_option(self::VERSION_OPTION, '0.0.0');

        if (version_compare($meta_version, self::META_VERSION, '<')) {
            $this->migrate_from_old_system();
            update_option(self::VERSION_OPTION, self::META_VERSION);
        }
    }

    /**
     * 从旧系统迁移数据
     */
    public function migrate_from_old_system() {
        ruiyi_debug_log('RUIYI Meta: Starting migration from old table system');

        // Get data from old tables
        $old_categories = $this->get_old_categories();
        $old_mappings = $this->get_old_mappings();

        // Convert to new format
        $new_categories = $this->convert_categories($old_categories);
        $new_mappings = $this->convert_mappings($old_mappings);

        // Save to meta system
        $this->save_categories($new_categories);
        $this->save_mappings($new_mappings);

        ruiyi_debug_log('RUIYI Meta: Migration completed successfully');
    }

    /**
     * 获取旧系统的分类数据
     */
    private function get_old_categories() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ruiyi_table_categories';

        // Check if old table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        if (!$table_exists) {
            return array();
        }

        return $wpdb->get_results("SELECT * FROM $table_name ORDER BY sort_order, id", ARRAY_A);
    }

    /**
     * 获取旧系统的桌位映射数据
     */
    private function get_old_mappings() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ruiyi_table_category_mapping';

        // Check if old table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        if (!$table_exists) {
            return array();
        }

        return $wpdb->get_results("SELECT * FROM $table_name ORDER BY category_id, table_number_in_category", ARRAY_A);
    }

    /**
     * 转换分类数据格式
     */
    private function convert_categories($old_categories) {
        $new_categories = array();

        foreach ($old_categories as $category) {
            $new_categories[] = array(
                'id' => intval($category['id']),
                'name' => sanitize_text_field($category['name']),
                'name_es' => sanitize_text_field($category['name_es']),
                'name_en' => sanitize_text_field($category['name_en']),
                'name_zh' => sanitize_text_field($category['name_zh']),
                'slug' => sanitize_title($category['slug']),
                'color' => sanitize_hex_color($category['color']),
                'icon' => sanitize_text_field($category['icon']),
                'sort_order' => intval($category['sort_order']),
                'created_at' => $category['created_at'],
                'updated_at' => current_time('mysql')
            );
        }

        return $new_categories;
    }

    /**
     * 转换桌位映射数据格式
     */
    private function convert_mappings($old_mappings) {
        $new_mappings = array();

        foreach ($old_mappings as $mapping) {
            $new_mappings[] = array(
                'table_id' => sanitize_text_field($mapping['table_id']),
                'category_id' => intval($mapping['category_id']),
                'table_number_in_category' => intval($mapping['table_number_in_category']),
                'global_table_number' => intval($mapping['global_table_number']),
                'category_display_name' => sanitize_text_field($mapping['category_display_name']),
                'table_number' => sanitize_text_field($mapping['table_number']),
                'updated_at' => current_time('mysql')
            );
        }

        return $new_mappings;
    }

    /**
     * 保存分类数据到Meta系统
     */
    public function save_categories($categories) {
        return update_option(self::CATEGORIES_OPTION, $categories, false);
    }

    /**
     * 保存桌位映射到Meta系统
     */
    public function save_mappings($mappings) {
        return update_option(self::MAPPINGS_OPTION, $mappings, false);
    }

    /**
     * 获取所有分类
     */
    public function get_categories() {
        $categories = get_option(self::CATEGORIES_OPTION, array());

        // 🔥 确保每个分类都有 delivery_enabled 字段（向后兼容）
        foreach ($categories as &$category) {
            if (!isset($category['delivery_enabled'])) {
                $category['delivery_enabled'] = 0;
            } else {
                $category['delivery_enabled'] = intval($category['delivery_enabled']);
            }
        }

        // Sort by sort_order
        usort($categories, function($a, $b) {
            return intval($a['sort_order']) - intval($b['sort_order']);
        });

        return $categories;
    }

    /**
     * 根据ID获取分类
     */
    public function get_category($category_id) {
        $categories = $this->get_categories();

        foreach ($categories as $category) {
            if (intval($category['id']) === intval($category_id)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * 创建新分类
     */
    public function create_category($data) {
        $categories = $this->get_categories();

        // Generate new ID
        $max_id = 0;
        foreach ($categories as $category) {
            $max_id = max($max_id, intval($category['id']));
        }
        $new_id = $max_id + 1;

        // Create new category
        $new_category = array(
            'id' => $new_id,
            'name' => sanitize_text_field($data['name']),
            'name_es' => sanitize_text_field($data['name_es']),
            'name_en' => sanitize_text_field($data['name_en']),
            'name_zh' => sanitize_text_field($data['name_zh']),
            'slug' => sanitize_title($data['slug'] ?: $data['name']),
            'color' => sanitize_hex_color($data['color']),
            'icon' => sanitize_text_field($data['icon']),
            'sort_order' => intval($data['sort_order']),
            'delivery_enabled' => isset($data['delivery_enabled']) ? intval($data['delivery_enabled']) : 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        $categories[] = $new_category;

        if ($this->save_categories($categories)) {
            return $new_id;
        }

        return false;
    }

    /**
     * 更新分类
     */
    public function update_category($category_id, $data) {
        $categories = $this->get_categories();

        foreach ($categories as &$category) {
            if (intval($category['id']) === intval($category_id)) {
                $category['name'] = sanitize_text_field($data['name']);
                $category['name_es'] = sanitize_text_field($data['name_es']);
                $category['name_en'] = sanitize_text_field($data['name_en']);
                $category['name_zh'] = sanitize_text_field($data['name_zh']);
                $category['slug'] = sanitize_title($data['slug'] ?: $data['name']);
                $category['color'] = sanitize_hex_color($data['color']);
                $category['icon'] = sanitize_text_field($data['icon']);
                $category['sort_order'] = intval($data['sort_order']);
                $category['delivery_enabled'] = isset($data['delivery_enabled']) ? intval($data['delivery_enabled']) : 0;
                $category['updated_at'] = current_time('mysql');

                return $this->save_categories($categories);
            }
        }

        return false;
    }

    /**
     * 删除分类
     */
    public function delete_category($category_id) {
        $categories = $this->get_categories();
        $mappings = $this->get_mappings();

        // Remove category
        $categories = array_filter($categories, function($category) use ($category_id) {
            return intval($category['id']) !== intval($category_id);
        });

        // Remove associated mappings
        $mappings = array_filter($mappings, function($mapping) use ($category_id) {
            return intval($mapping['category_id']) !== intval($category_id);
        });

        // Save both
        $result1 = $this->save_categories(array_values($categories));
        $result2 = $this->save_mappings(array_values($mappings));

        return $result1 && $result2;
    }

    /**
     * 获取所有桌位映射
     */
    public function get_mappings($auto_repair = true) {
        $mappings = get_option(self::MAPPINGS_OPTION, array());

        if ($auto_repair) {
            $mappings = $this->maybe_repair_mappings($mappings);
        }

        return $mappings;
    }

    /**
     * 自动检测并修复桌位映射异常。
     *
     * 这些异常会导致前端桌位显示"占用"，但点击后按另一个全局桌号查询，
     * 从而弹出"无订单"。删除并重建分区能恢复，是因为映射被重新生成。
     */
    public function maybe_repair_mappings($mappings = null, $force = false) {
        if ($this->repairing_mappings) {
            return is_array($mappings) ? $mappings : get_option(self::MAPPINGS_OPTION, array());
        }

        if ($mappings === null) {
            $mappings = get_option(self::MAPPINGS_OPTION, array());
        }

        if (!is_array($mappings) || empty($mappings)) {
            return is_array($mappings) ? $mappings : array();
        }

        $categories = $this->get_categories();
        if (empty($categories)) {
            return $mappings;
        }

        $category_by_id = array();
        $category_order = array();
        foreach ($categories as $index => $category) {
            $category_id = intval($category['id'] ?? 0);
            if ($category_id <= 0) {
                continue;
            }
            $category_by_id[$category_id] = $category;
            $category_order[$category_id] = intval($category['sort_order'] ?? $index);
        }

        if (empty($category_by_id)) {
            return $mappings;
        }

        $original_serialized = serialize($mappings);
        $issues = array(
            'orphan_mappings' => 0,
            'missing_local_numbers' => 0,
            'duplicate_local_numbers' => 0,
            'fixed_table_ids' => 0,
            'fixed_global_numbers' => 0,
            'fixed_display_names' => 0,
        );

        // 先按分类顺序和分类内桌号排序，保证修复后的全局桌号稳定。
        usort($mappings, function($a, $b) use ($category_order) {
            $cat_a = intval($a['category_id'] ?? 0);
            $cat_b = intval($b['category_id'] ?? 0);
            $order_a = $category_order[$cat_a] ?? 999999;
            $order_b = $category_order[$cat_b] ?? 999999;
            if ($order_a !== $order_b) {
                return $order_a - $order_b;
            }
            $local_a = intval($a['table_number_in_category'] ?? 0);
            $local_b = intval($b['table_number_in_category'] ?? 0);
            if ($local_a !== $local_b) {
                return $local_a - $local_b;
            }
            return strcmp(strval($a['table_id'] ?? ''), strval($b['table_id'] ?? ''));
        });

        $global_number_counts = array();
        $max_global_number = 0;
        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }
            $global_number = intval($mapping['global_table_number'] ?? 0);
            if ($global_number <= 0) {
                continue;
            }
            if (!isset($global_number_counts[$global_number])) {
                $global_number_counts[$global_number] = 0;
            }
            $global_number_counts[$global_number]++;
            $max_global_number = max($max_global_number, $global_number);
        }

        $used_local_numbers = array();
        $used_global_numbers = array();
        $next_local_number = array();
        $next_global_number = max(1, $max_global_number + 1);
        $repaired_mappings = array();

        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $category_id = intval($mapping['category_id'] ?? 0);
            if ($category_id <= 0 || !isset($category_by_id[$category_id])) {
                $issues['orphan_mappings']++;
                continue;
            }

            if (!isset($used_local_numbers[$category_id])) {
                $used_local_numbers[$category_id] = array();
                $next_local_number[$category_id] = 1;
            }

            $local_number = intval($mapping['table_number_in_category'] ?? 0);
            if ($local_number <= 0) {
                $issues['missing_local_numbers']++;
                $local_number = $next_local_number[$category_id];
            }

            if (isset($used_local_numbers[$category_id][$local_number])) {
                $issues['duplicate_local_numbers']++;
                while (isset($used_local_numbers[$category_id][$next_local_number[$category_id]])) {
                    $next_local_number[$category_id]++;
                }
                $local_number = $next_local_number[$category_id];
            }

            $used_local_numbers[$category_id][$local_number] = true;
            $next_local_number[$category_id] = max($next_local_number[$category_id], $local_number + 1);

            $expected_table_id = $category_id . '_' . $local_number;
            if (($mapping['table_id'] ?? '') !== $expected_table_id) {
                $issues['fixed_table_ids']++;
            }

            $current_global_number = intval($mapping['global_table_number'] ?? 0);
            $global_number_is_valid = $current_global_number > 0 &&
                ($global_number_counts[$current_global_number] ?? 0) === 1 &&
                !isset($used_global_numbers[$current_global_number]);

            if ($global_number_is_valid) {
                $stable_global_number = $current_global_number;
            } else {
                $issues['fixed_global_numbers']++;
                while (isset($used_global_numbers[$next_global_number]) || isset($global_number_counts[$next_global_number])) {
                    $next_global_number++;
                }
                $stable_global_number = $next_global_number;
                $next_global_number++;
            }
            $used_global_numbers[$stable_global_number] = true;

            $category_name = $this->get_category_name_by_lang($category_by_id[$category_id]);
            $expected_display_name = trim($category_name . ' ' . $local_number);
            $current_display_name = trim(strval($mapping['category_display_name'] ?? ''));
            $normalized_current = strtoupper(preg_replace('/\s+/', '', $current_display_name));
            $normalized_expected = strtoupper(preg_replace('/\s+/', '', $expected_display_name));
            $looks_like_system_display_name = false;
            foreach ($category_by_id as $candidate_category) {
                $candidate_name = $this->get_category_name_by_lang($candidate_category);
                $candidate_display = strtoupper(preg_replace('/\s+/', '', trim($candidate_name . ' ' . $local_number)));
                if ($candidate_display !== '' && $normalized_current === $candidate_display) {
                    $looks_like_system_display_name = true;
                    break;
                }
            }

            // 保留真正的自定义桌名；但修复缺失、无空格旧格式、或明显属于当前分类的基础显示名。
            if ($current_display_name === '' || $normalized_current === $normalized_expected || $looks_like_system_display_name) {
                if ($current_display_name !== $expected_display_name) {
                    $issues['fixed_display_names']++;
                }
                $mapping['category_display_name'] = $expected_display_name;
            }

            $mapping['category_id'] = $category_id;
            $mapping['table_number_in_category'] = $local_number;
            $mapping['global_table_number'] = $stable_global_number;
            $mapping['table_id'] = $expected_table_id;
            $mapping['table_number'] = $this->generate_table_name($stable_global_number);
            $mapping['updated_at'] = current_time('mysql');

            $repaired_mappings[] = $mapping;
        }

        if (serialize($repaired_mappings) !== $original_serialized || $force) {
            $this->repairing_mappings = true;
            $saved = $this->save_mappings(array_values($repaired_mappings));
            $this->repairing_mappings = false;

            if ($saved) {
                ruiyi_debug_log('RUIYI Meta: Auto repaired table mappings: ' . wp_json_encode($issues));
                return array_values($repaired_mappings);
            }
        }

        return $mappings;
    }

    /**
     * 根据分类获取桌位
     */
    public function get_tables_by_category($category_id, $display_mode = 'category') {
        $mappings = $this->get_mappings();
        $tables = array();

        foreach ($mappings as $mapping) {
            if (intval($mapping['category_id']) === intval($category_id)) {
                // 🔥 category_display_name 已包含完整桌位名（自定义名或 "分区名 编号"），直接使用
                $cat_name = !empty($mapping['category_display_name']) ? $mapping['category_display_name'] : '';
                $table_name_display = $cat_name
                    ? $cat_name
                    : $this->generate_table_name($mapping['table_number_in_category']);

                $tables[] = array(
                    'table_id' => $mapping['table_id'],
                    'table_name' => $table_name_display,
                    'table_number_in_category' => $mapping['table_number_in_category'],
                    'global_table_number' => $mapping['global_table_number'],
                    'category_display_name' => $mapping['category_display_name']
                );
            }
        }

        // Sort by table number in category
        usort($tables, function($a, $b) {
            return intval($a['table_number_in_category']) - intval($b['table_number_in_category']);
        });

        return $tables;
    }

    /**
     * 重新计算所有分类的全局桌位编号
     * 兼容旧调用：现在不再重新洗牌所有全局桌号，只修复缺失/重复/孤儿映射。
     * 原来的全量重排会改变已有 WooCommerce 订单对应的桌位身份，导致串单。
     */
    public function recalculate_global_numbers() {
        $mappings = $this->maybe_repair_mappings(null, true);
        return is_array($mappings);
    }

    /**
     * 添加桌位到分类
     */
    public function add_tables_to_category($category_id, $table_count) {
        ruiyi_debug_log("RUIYI Meta: Adding {$table_count} tables to category {$category_id}");

        $mappings = $this->get_mappings();
        $category = $this->get_category($category_id);

        ruiyi_debug_log("RUIYI Meta: Found " . count($mappings) . " existing mappings");
        ruiyi_debug_log("RUIYI Meta: Category: " . json_encode($category));

        if (!$category) {
            ruiyi_debug_log("RUIYI Meta: Category {$category_id} not found");
            return 0;
        }

        // Get current max numbers
        $max_category_number = 0;
        $max_global_number = 0;

        foreach ($mappings as $mapping) {
            if (intval($mapping['category_id']) === intval($category_id)) {
                $max_category_number = max($max_category_number, intval($mapping['table_number_in_category']));
            }
            $max_global_number = max($max_global_number, intval($mapping['global_table_number']));
        }

        $success_count = 0;
        $category_name = $this->get_category_name_by_lang($category);

        ruiyi_debug_log("RUIYI Meta: Max category number: {$max_category_number}, Max global number: {$max_global_number}");
        ruiyi_debug_log("RUIYI Meta: Category name: {$category_name}");

        // Add new tables
        for ($i = 1; $i <= $table_count; $i++) {
            $new_category_number = $max_category_number + $i;
            $new_global_number = $max_global_number + $i;
            $table_id = $category_id . '_' . $new_category_number;

            $new_mapping = array(
                'table_id' => $table_id,
                'category_id' => intval($category_id),
                'table_number_in_category' => $new_category_number,
                'global_table_number' => $new_global_number,
                'category_display_name' => $category_name . ' ' . $new_category_number,
                'table_number' => $this->generate_table_name($new_global_number),
                'updated_at' => current_time('mysql')
            );

            $mappings[] = $new_mapping;
            $success_count++;

            ruiyi_debug_log("RUIYI Meta: Created mapping for table {$table_id}");
        }

        ruiyi_debug_log("RUIYI Meta: Attempting to save " . count($mappings) . " mappings");

        if ($this->save_mappings($mappings)) {
            $this->maybe_repair_mappings(null, true);
            ruiyi_debug_log("RUIYI Meta: Successfully saved mappings and repaired mapping integrity, returning {$success_count}");
            return $success_count;
        }

        ruiyi_debug_log("RUIYI Meta: Failed to save mappings");
        return 0;
    }

    /**
     * 删除分类中的最后一个桌位
     */
    public function remove_last_table($category_id) {
        $mappings = $this->get_mappings();
        $last_table = null;
        $last_number = 0;

        // Find the last table in category
        foreach ($mappings as $key => $mapping) {
            if (intval($mapping['category_id']) === intval($category_id)) {
                if (intval($mapping['table_number_in_category']) > $last_number) {
                    $last_number = intval($mapping['table_number_in_category']);
                    $last_table = array('key' => $key, 'data' => $mapping);
                }
            }
        }

        if ($last_table) {
            unset($mappings[$last_table['key']]);
            return $this->save_mappings(array_values($mappings));
        }

        return false;
    }

    /**
     * 生成桌位名称
     */
    private function generate_table_name($table_number, $lang = null) {
        if (!$lang) {
            $cookie_lang = isset($_COOKIE['ruiyi_language']) ? $_COOKIE['ruiyi_language'] : null;
            $lang = $cookie_lang ?: (defined('RUIYI_CURRENT_LANG') ? RUIYI_CURRENT_LANG : 'zh');
        }

        $prefixes = array(
            'es' => 'Mesa',
            'en' => 'Table',
            'zh' => '餐桌'
        );

        $prefix = $prefixes[$lang] ?: 'Mesa';
        return $prefix . ' ' . $table_number;
    }

    /**
     * 根据语言获取分类名称
     */
    private function get_category_name_by_lang($category, $lang = null) {
        if (!$lang) {
            $cookie_lang = isset($_COOKIE['ruiyi_language']) ? $_COOKIE['ruiyi_language'] : null;
            $lang = $cookie_lang ?: (defined('RUIYI_CURRENT_LANG') ? RUIYI_CURRENT_LANG : 'zh');
        }

        switch ($lang) {
            case 'en':
                return $category['name_en'] ?: $category['name'];
            case 'zh':
                return $category['name_zh'] ?: $category['name'];
            default:
                return $category['name_es'] ?: $category['name'];
        }
    }

    /**
     * 清除缓存
     */
    public function clear_cache() {
        wp_cache_delete(self::CATEGORIES_OPTION, 'options');
        wp_cache_delete(self::MAPPINGS_OPTION, 'options');
    }

    /**
     * 获取系统状态
     */
    public function get_system_status() {
        $categories = $this->get_categories();
        $mappings = $this->get_mappings();

        $total_categories = count($categories);
        $total_tables = count($mappings);

        $tables_by_category = array();
        foreach ($mappings as $mapping) {
            $cat_id = $mapping['category_id'];
            if (!isset($tables_by_category[$cat_id])) {
                $tables_by_category[$cat_id] = 0;
            }
            $tables_by_category[$cat_id]++;
        }

        return array(
            'total_categories' => $total_categories,
            'total_tables' => $total_tables,
            'tables_by_category' => $tables_by_category,
            'meta_version' => get_option(self::VERSION_OPTION),
            'using_meta_system' => true
        );
    }
}

// Initialize the meta manager
function ruiyi_get_meta_manager() {
    return RUIYI_Table_Meta_Manager::getInstance();
}

// Compatibility wrapper functions for existing code
function ruiyi_meta_get_categories() {
    return ruiyi_get_meta_manager()->get_categories();
}

function ruiyi_meta_get_tables_by_category($category_id, $display_mode = 'category') {
    return ruiyi_get_meta_manager()->get_tables_by_category($category_id, $display_mode);
}

function ruiyi_meta_add_tables_to_category($category_id, $table_count) {
    return ruiyi_get_meta_manager()->add_tables_to_category($category_id, $table_count);
}

function ruiyi_meta_create_category($data) {
    return ruiyi_get_meta_manager()->create_category($data);
}

function ruiyi_meta_update_category($category_id, $data) {
    return ruiyi_get_meta_manager()->update_category($category_id, $data);
}

function ruiyi_meta_delete_category($category_id) {
    return ruiyi_get_meta_manager()->delete_category($category_id);
}
