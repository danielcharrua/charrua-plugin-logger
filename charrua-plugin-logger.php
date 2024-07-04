<?php
/**
 * Plugin Name:       Charrúa Plugin Logger
 * Description:       Registra la actualización, activación y desactivación de plugins.
 * Version:           1.0.0
 * Author:            Daniel Pereyra
 * Author URI:        https://charrua.es
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       charrua_plugin_logger
 * Domain Path:       /languages
 */

// Evita el acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Crear la tabla al activar el plugin
register_activation_hook(__FILE__, 'charrua_pl_create_table');

function charrua_pl_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'plugin_update_logs';

    // Verificar si la tabla ya existe
    if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            plugin_name varchar(255) NOT NULL,
            version varchar(50) NOT NULL,
            new_version varchar(50) NOT NULL,
            update_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            accion varchar(50) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Almacenar la versión anterior antes de la actualización
add_action('pre_set_site_transient_update_plugins', 'charrua_pl_store_old_versions');

function charrua_pl_store_old_versions($transient) {
    if (isset($transient->response) && is_array($transient->response)) {
        foreach ($transient->response as $plugin => $details) {
            $plugin_data = charrua_pl_get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
            if ($plugin_data) {
                update_option('charrua_pl_old_version_' . $plugin, $plugin_data['Version']);
            }
        }
    }
    return $transient;
}

// Hook en la actualización del plugin
add_action('upgrader_process_complete', 'charrua_pl_log_update', 10, 2);

function charrua_pl_log_update($upgrader_object, $options) {
    global $wpdb;

    if ($options['action'] == 'update' && $options['type'] == 'plugin') {
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $update_time = current_time('mysql');
        
        foreach ($options['plugins'] as $plugin) {
            $plugin_data = charrua_pl_get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
            $plugin_name = $plugin_data['Name'];
            $new_version = $plugin_data['Version'];
            
            $old_version = get_option('charrua_pl_old_version_' . $plugin, 'unknown');
            $accion = version_compare($new_version, $old_version, '>') ? 'update' : 'downgrade';
            
            $wpdb->insert(
                $wpdb->prefix . 'plugin_update_logs',
                [
                    'plugin_name' => $plugin_name,
                    'version' => $old_version,
                    'new_version' => $new_version,
                    'update_time' => $update_time,
                    'accion' => $accion,
                    'user_id' => $user_id
                ]
            );

            delete_option('charrua_pl_old_version_' . $plugin);
        }
    }
}

// Hook en la activación del plugin
add_action('activated_plugin', 'charrua_pl_log_activation', 10, 2);

function charrua_pl_log_activation($plugin, $network_wide) {
    global $wpdb;
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;
    $update_time = current_time('mysql');
    $plugin_data = charrua_pl_get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
    $plugin_name = $plugin_data['Name'];
    $version = $plugin_data['Version'];

    $wpdb->insert(
        $wpdb->prefix . 'plugin_update_logs',
        [
            'plugin_name' => $plugin_name,
            'version' => $version,
            'new_version' => '',
            'update_time' => $update_time,
            'accion' => 'activate',
            'user_id' => $user_id
        ]
    );
}

// Hook en la desactivación del plugin
add_action('deactivated_plugin', 'charrua_pl_log_deactivation', 10, 2);

function charrua_pl_log_deactivation($plugin, $network_wide) {
    global $wpdb;
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;
    $update_time = current_time('mysql');
    $plugin_data = charrua_pl_get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
    $plugin_name = $plugin_data['Name'];
    $version = $plugin_data['Version'];

    $wpdb->insert(
        $wpdb->prefix . 'plugin_update_logs',
        [
            'plugin_name' => $plugin_name,
            'version' => $version,
            'new_version' => '',
            'update_time' => $update_time,
            'accion' => 'deactivate',
            'user_id' => $user_id
        ]
    );
}

// Función para obtener los datos del plugin
function charrua_pl_get_plugin_data($plugin_file) {
    if (!file_exists($plugin_file)) {
        return false;
    }
    return get_file_data($plugin_file, [
        'Name' => 'Plugin Name',
        'Version' => 'Version'
    ]);
}

// Añadir página de opciones
add_action('admin_menu', 'charrua_pl_add_admin_menu');

function charrua_pl_add_admin_menu() {
    add_menu_page(
        'Charrúa Plugin Logger', // Título de la página
        'Plugin Logger', // Título del menú
        'manage_options',       // Capacidad
        'plugin-update-logger', // Slug del menú
        'charrua_pl_options_page' // Función de la página
    );
}

// Mostrar los registros en la página de opciones
function charrua_pl_options_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'plugin_update_logs';
    $results = $wpdb->get_results("SELECT * FROM $table_name");

    echo '<div class="wrap">';
    echo '<h1>Charrúa Plugin Logger</h1>';
    echo '<table class="widefat fixed" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th class="manage-column">Plugin Name</th>';
    echo '<th class="manage-column">Version</th>';
    echo '<th class="manage-column">New Version</th>';
    echo '<th class="manage-column">Update Time</th>';
    echo '<th class="manage-column">Acción</th>';
    echo '<th class="manage-column">User</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($results as $row) {
        $user_info = get_userdata($row->user_id);
        $user_name = $user_info ? $user_info->display_name : 'Unknown User';
        $user_profile_url = $user_info ? get_edit_user_link($row->user_id) : '#';

        echo '<tr>';
        echo '<td>' . esc_html($row->plugin_name) . '</td>';
        echo '<td>' . esc_html($row->version) . '</td>';
        echo '<td>' . esc_html($row->new_version) . '</td>';
        echo '<td>' . esc_html($row->update_time) . '</td>';
        echo '<td>' . esc_html($row->accion) . '</td>';
        echo '<td><a href="' . esc_url($user_profile_url) . '">' . esc_html($user_name) . '</a></td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}

// Registrar la función de desinstalación
register_uninstall_hook(__FILE__, 'charrua_pl_uninstall');

function charrua_pl_uninstall() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'plugin_update_logs';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}